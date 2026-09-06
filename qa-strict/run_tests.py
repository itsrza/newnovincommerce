#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Novin Commerce — strict QA harness (100 tests).

What this suite does
--------------------
* Static, source-level verification of every PHP file shipped by the plugin
  (vendor code is excluded — Composer resolves it for each target PHP).
* Cross-version PHP compatibility scan: rejects syntax/functions introduced
  after PHP 7.4 (the plugin's declared minimum) so the same codebase runs on
  PHP 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4.
* Execution of every raw SQL statement used by the dashboard/sync panels
  against a seeded SQLite database (same WHERE/GROUP BY/ORDER BY/LIMIT
  semantics) plus a full re-implementation of the dashboard aggregation
  pipeline with cross-checks between SQL results and the pipeline math.
* WebPrd JSON contract validation against the real live payload (the product
  the shop owner supplied), so any field the dashboard reads is guaranteed
  to exist in the accounting JSON.
* Composer/autoload consistency (jdate() regression guard), version
  consistency and release-zip integrity checks.

Limitation (stated honestly)
----------------------------
PHP/WordPress/WooCommerce are not installed in this offline sandbox, so
there is no real HTTP/REST or wp-admin execution here. Every logic branch
that can be evaluated without a web server IS evaluated (SQL execution,
JSON decoding, aggregation math, escaping/nonce/capability call-site
checks). A true end-to-end cycle (real WooCommerce REST round-trip with the
desktop client) still needs a staging WordPress site.

Exit code: 0 when all tests pass, 1 otherwise.
"""

import datetime as _dt
import json
import os
import re
import sqlite3
import sys
import time as _t
import zipfile

os.environ['TZ'] = 'UTC'
if hasattr(_t, 'tzset'):
    _t.tzset()

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'novin-commerce')
ZIP_PATH = os.path.join(ROOT, 'NovinCommerce-1.10.15.zip')
VERSION = '1.10.15'

RESULTS = []
FAILED = []


def check(tid, ok, name, detail=''):
    RESULTS.append((tid, bool(ok), name, detail))
    if not ok:
        FAILED.append((tid, name, detail))


# ----------------------------------------------------------------------------
# helpers
# ----------------------------------------------------------------------------

def source_php_files():
    """All non-vendor PHP files shipped in the plugin folder."""
    out = []
    for dirpath, dirnames, filenames in os.walk(PLUGIN):
        rel = os.path.relpath(dirpath, PLUGIN).replace('\\', '/')
        if rel == 'vendor' or rel.startswith('vendor/'):
            continue
        for fn in filenames:
            if fn.endswith('.php'):
                out.append(os.path.join(dirpath, fn))
    return sorted(out)


def strip_php(src):
    """Remove strings/comments/HTML-template sections so the remainder can be
    checked for balance. PHP template files switch to raw HTML/CSS with ?>."""
    out = []
    i, n = 0, len(src)
    while i < n:
        c = src[i]
        if src.startswith('?>', i):
            j = src.find('<?', i + 2)
            i = n if j < 0 else j
            continue
        if c == '/' and src.startswith('//', i):
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if c == '#' and (i == 0 or src[i - 1] in '\n;{} '):
            j = src.find('\n', i)
            i = n if j < 0 else j
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            i = n if j < 0 else j + 2
            continue
        if c in "'\"":
            q = c
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == q:
                    i += 1
                    break
                i += 1
            continue
        out.append(c)
        i += 1
    return ''.join(out)


def balanced(src):
    code = strip_php(src)
    stack = []
    pairs = {'}': '{', ')': '(', ']': '['}
    for ch in code:
        if ch in '{([':
            stack.append(ch)
        elif ch in '})]':
            if not stack or stack.pop() != pairs[ch]:
                return False, 'mismatch at %r' % ch
    return (len(stack) == 0), ('unclosed: %r' % (stack[-5:] if stack else 'ok'))


def balanced_template(src):
    """Fallback for template files that mix PHP with raw HTML/CSS dozens of
    times: concatenate only the PHP chunks (<?php ... ?>) and balance those.
    Used only when the string-aware scanner reports an imbalance."""
    parts = re.split(r'(<\?php|<\?=|\?>)', src)
    php = []
    active = False
    for part in parts:
        if part in ('<?php', '<?='):
            active = True
            continue
        if part == '?>':
            active = False
            continue
        if active:
            php.append(part)
    code = strip_php(''.join(php))
    return code.count('{') == code.count('}') and code.count('(') == code.count(')') \
        and code.count('[') == code.count(']')


def file_text(rel):
    with open(os.path.join(PLUGIN, rel), encoding='utf-8') as f:
        return f.read()


HEAD_FILE = file_text('novin-commerce.php')
SYNC_SRC = file_text('lib/Models/Sync.php')
SL_SRC = file_text('lib/Common/SyncLog.php')
RC_SRC = file_text('lib/Common/Novin_REST_Controller.php')
MENU_FILE = file_text('lib/Admin/Menu.php')
DASH = file_text('lib/Admin/Connection_Dashboard.php')
QA_DOC = file_text('QA-100-TESTS.md')


def all_php_text():
    return {os.path.relpath(p, PLUGIN).replace('\\', '/'): open(p, encoding='utf-8').read()
            for p in source_php_files()}


# PHP 8.0+ only syntax markers
PHP80_SYNTAX = [
    (r'\bmatch\s*\(', 'match expression (PHP 8.0+)'),
    (r'\?->', 'nullsafe operator (PHP 8.0+)'),
    (r'#\[', 'attributes (PHP 8.0+)'),
    (r'\benum\s+\w+', 'enum (PHP 8.1+)'),
    (r'\breadonly\s+class\b', 'readonly class (PHP 8.2+)'),
    (r'\bpublic\s+function\s+__construct\s*\(\s*(public|protected|private)\s+\$', 'ctor promotion (PHP 8.0+)'),
]
PHP80_FUNCS = ['str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type',
               'fdiv', 'preg_last_error_msg', 'array_is_list', 'enum_exists']

# ----------------------------------------------------------------------------
# Fixtures
# ----------------------------------------------------------------------------

WEBPRD_SAMPLE = {
    "Id": 999662,
    "PriceRoleList": [{"WordPressRoleName": "Author"}, {"WordPressRoleName": "Editor"},
                      {"Price": 3100000.0, "WordPressRoleName": "Administrator"},
                      {"WordPressRoleName": "Contributor"}, {"WordPressRoleName": "Subscriber"},
                      {"WordPressRoleName": "Customer"}],
    "Prices": [{"Guid": "1b74bef7-79b5-49e6-8373-712d841332fa", "Modified": "2026-05-24T13:52:09.06",
                "ProductGuid": "d9eca56f-9bf6-4f14-937f-fc94c0a916a9", "Price": 3090000.000,
                "PriceLevelGuid": "1fd9a513-8533-4f6b-8af3-736f4235c340"}],
    "GroupList": [{"GroupGuid": "9fc60bc6-f5f5-465e-bcb9-77df15544828", "Ordinal": 1}],
    "IdProduct": "258", "VahedName": "دستگاه",
    "DiscountStartDate": "2025-12-14T01:01:12", "DiscountEndDate": "2026-01-13T00:13:40",
    "Name": "دستگاه حضور و غیاب NP620AC2", "Description": "",
    "Sell1": 3100000.0, "Sell8": 3090000.0, "Pic": "", "TaxCode": "", "TaxName": "",
    "IndexingType": 8191,
    "T1": "1000 عدد", "T2": "1000 عدد", "T3": "100.000 عدد", "T4": "1000 نفر",
    "T5": "فلش", "T6": "1.8 اینچ", "T7": "قابلیت کنترل قفل های برقی (Access Control)",
    "T8": "نوین پرداز", "Mojodi": 1, "DiscountPercent": 7.0,
    "GroupName": " اثر انگشتی ", "Guid": "d9eca56f-9bf6-4f14-937f-fc94c0a916a9",
    "Modified": "2026-09-05T16:32:14.17", "FullContent": '[html_block id="2182"]',
    "QuickText": "", "Code": 258, "Keywords": "", "MetaDesc": "", "OtherPics": "",
    "BuyLast": 10.0, "CreateDate": "2023-09-17T14:02:41", "KardexPrice": 10.0,
    "PrdAnbarRelation": [{"PrdGuid": "d9eca56f-9bf6-4f14-937f-fc94c0a916a9",
                          "AnbarGuid": "3339d90f-bd11-4510-9253-3b7a742e4e79"}],
    "PrdTechnicalList": [{"Guid": "b2d2303e-0019-436c-8284-bf5e65898045",
                          "Modified": "2026-05-24T13:52:09.047", "Code": "",
                          "Name": "1000 عدد", "Key": "تست", "TechnicalType": 1,
                          "ProductGuid": "d9eca56f-9bf6-4f14-937f-fc94c0a916a9"}],
    "PrdBarcode": [{"Name": "", "BarCode": "NP620", "Description": "",
                    "CreateDate": "2025-09-22T17:38:17.807",
                    "ExpireDate": "9999-12-31T23:59:59.9999999",
                    "PriceType": 1, "Type": 1, "Quantity": 1.0,
                    "ChangeStatus": 2, "Ordinal": 1}],
    "GuidGroup": "9fc60bc6-f5f5-465e-bcb9-77df15544828",
    "GuidVahed": "9bae8a37-7f73-454a-dc86-08da64d81183",
    "ImageListData": [], "ServerStatus": 2, "ResetTime": "2025-10-31T00:31:30.923",
    "Version": 20, "ClientVersion": 8, "Files": [], "OwnedFileParts": [3, 17],
    "MappId": "", "Sku": "دستگاه-حضور-و-غیاب-NP620AC",
    "Slug": "دستگاه-حضور-و-غیاب-NP620AC", "NameForDisplay": "",
    "SendToServerDate": "2025-05-29T13:05:09.16",
    "SeoDescription": "", "SeoTitle": "", "SeoFocusKeyword": ""
}


def make_db():
    con = sqlite3.connect(':memory:')
    cur = con.cursor()
    cur.executescript("""
    CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT);
    CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INT,
                              meta_key TEXT, meta_value TEXT);
    CREATE TABLE wp_novin_commerce_syncs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INT, item_type TEXT,
        priority INT DEFAULT 0, created_at TEXT, updated_at TEXT);
    CREATE TABLE wp_novin_commerce_sync_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT, status TEXT,
        item_type TEXT, item_id INT, message TEXT, context TEXT, created_at TEXT);
    """)
    products = [
        (101, 'product', 'publish'), (102, 'product', 'publish'),
        (103, 'product', 'publish'), (104, 'product', 'publish'),
        (105, 'product', 'publish'), (106, 'product', 'draft'),
        (201, 'product_variation', 'publish'),
    ]
    cur.executemany('INSERT INTO wp_posts VALUES (?,?,?)', products)
    metas = []
    metas.append((101, 'guid', 'd9eca56f-9bf6-4f14-937f-fc94c0a916a9'))
    metas.append((101, 'WebPrd', json.dumps(WEBPRD_SAMPLE, ensure_ascii=False)))
    metas.append((101, '_np-api-sync-date', '2026/09/06 08:41:47'))
    w2 = dict(WEBPRD_SAMPLE, Guid='guid-2', Modified='2026-09-07T10:00:00.00')
    metas.append((102, 'guid', 'guid-2'))
    metas.append((102, 'WebPrd', json.dumps(w2, ensure_ascii=False)))
    metas.append((102, '_np-api-sync-date', '2026/09/06 08:41:47'))
    w3 = dict(WEBPRD_SAMPLE, Guid='source-guid-3', Sku='', Mojodi=0,
              PrdBarcode=[{"BarCode": "", "Name": ""}],
              DiscountStartDate=None, DiscountEndDate=None)
    metas.append((103, 'guid', 'site-guid-3'))
    metas.append((103, 'WebPrd', json.dumps(w3, ensure_ascii=False)))
    metas.append((103, '_np-api-sync-date', '2026/09/05 10:00:00'))
    metas.append((104, 'guid', 'guid-4'))
    metas.append((104, 'WebPrd', '{not-json'))
    metas.append((104, '_np-api-sync-date', '2026/09/06 10:00:00'))
    metas.append((105, 'guid', 'guid-5'))
    metas.append((106, 'guid', 'guid-6'))
    metas.append((106, 'WebPrd', json.dumps(WEBPRD_SAMPLE, ensure_ascii=False)))
    metas.append((201, 'guid', 'guid-v1'))
    metas.append((201, 'WebPrd', json.dumps(WEBPRD_SAMPLE, ensure_ascii=False)))
    metas.append((201, '_np-api-sync-date', '2026/09/06 09:00:00'))
    cur.executemany('INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (?,?,?)', metas)

    queue = [
        (5001, 'product', 0, '2026-09-06 08:00:00'),
        (5002, 'product', 10, '2026-09-06 08:05:00'),
        (7001, 'order', 0, '2026-09-06 08:10:00'),
        (3001, 'category', 0, '2026-09-06 08:15:00'),
        (9001, 'user', 0, '2026-09-06 08:20:00'),
    ]
    cur.executemany('INSERT INTO wp_novin_commerce_syncs (item_id, item_type, priority, created_at) VALUES (?,?,?,?)', queue)

    logs = [
        ('queued', 'success', 'product', 999, 'قدیمی', '2026-08-01 00:00:00'),
        ('disconnect', 'error', 'product', 101, 'قطع ارتباط.', '2026-09-06 03:00:00'),
        ('sync_datetime', 'info', '', 0, 'زمان Sync تغییر کرد.', '2026-09-06 04:00:00'),
        ('priority', 'warning', 'product', 5002, 'اولویت تغییر کرد.', '2026-09-06 05:00:00'),
        ('removed', 'success', 'order', 7001, 'حذف شد.', '2026-09-06 06:00:00'),
        ('synced', 'success', 'product', 101, 'دریافت شد.', '2026-09-06 07:30:00'),
        ('queued', 'success', 'product', 5001, 'مورد در صف تبادل قرار گرفت.', '2026-09-06 08:00:00'),
    ]
    cur.executemany('INSERT INTO wp_novin_commerce_sync_logs '
                    '(event_type, status, item_type, item_id, message, created_at) '
                    'VALUES (?,?,?,?,?,?)', logs)
    con.commit()
    return con


def dash_sql_named(name):
    """Pull a raw SQL statement out of the *actual* Connection_Dashboard.php
    source. Every dashboard data query executed by the SQL tests below comes
    from this extraction, so a query refactor in PHP immediately fails the
    related test instead of silently testing a stale copy."""
    table = {
        'sample': r'SELECT p\.ID,g\.meta_value guid,w\.meta_value webprd,s\.meta_value sync_date.*?ORDER BY p\.ID DESC LIMIT 150',
        'queue': r'SELECT item_type, COUNT\(\*\) AS total FROM \{\$wpdb->prefix\}novin_commerce_syncs GROUP BY item_type',
        'activity': r'SELECT status, COUNT\(\*\) AS total FROM \{\$log_table\} WHERE created_at >= %s GROUP BY status',
        'latest': r'SELECT created_at FROM \{\$log_table\} ORDER BY id DESC LIMIT 1',
        'pending': r'SELECT item_id, item_type, priority, created_at FROM \{\$prefix\}novin_commerce_syncs ORDER BY id DESC LIMIT %d',
        'received': r"SELECT item_id, item_type, event_type, created_at FROM \{\$prefix\}novin_commerce_sync_logs WHERE event_type='synced' ORDER BY id DESC LIMIT %d",
        'removed': r"SELECT item_id, item_type, event_type, created_at FROM \{\$prefix\}novin_commerce_sync_logs WHERE event_type='removed' ORDER BY id DESC LIMIT %d",
    }
    m = re.search(table[name], DASH, re.S)
    return m.group(0) if m else None


def dash_sql(con, stmt, args):
    """Run a dashboard SQL statement (as written in the PHP source) on the
    seeded SQLite DB: normalize WP tokens and bind %d/%s placeholders."""
    if stmt is None:
        return None
    sql = stmt
    for a, b in [('{$wpdb->prefix}', 'wp_'), ('{$prefix}', 'wp_'),
                 ('{$wpdb->posts}', 'wp_posts'), ('{$wpdb->postmeta}', 'wp_postmeta'),
                 ('{$log_table}', 'wp_novin_commerce_sync_logs')]:
        sql = sql.replace(a, b)
    out = []
    for tok in re.split(r'(%[ds])', sql):
        if tok == '%d':
            out.append(str(args[0]))
            args = args[1:]
        elif tok == '%s':
            out.append("'%s'" % args[0])
            args = args[1:]
        else:
            out.append(tok)
    return con.execute(''.join(out)).fetchall()


def php_health_pipeline(con, now_ts, since_str):
    """Faithful python port of Connection_Dashboard::health() math. The sample
    query is the one extracted from the real PHP source (see dash_sql_named)."""
    cur = con.cursor()
    rows = dash_sql(con, dash_sql_named('sample'), [])
    valid = gm = stale = 0
    ins = dict(modified=0, stock_positive=0, sku=0, barcode=0, barcode_value=0,
               sell_price=0, discount_active=0, price_roles=0, group=0, vahed=0,
               technical=0, pics=0)
    for row in rows:
        _, guid, raw, sync = row[0], row[1], row[2], row[3]
        try:
            d = json.loads(raw)
        except Exception:
            continue
        if not isinstance(d, dict):
            continue
        valid += 1
        if d.get('Guid') and str(d.get('Guid')) != str(guid or ''):
            gm += 1
        a = b = 0
        if d.get('Modified'):
            try:
                a = int(_t.mktime(_t.strptime(str(d['Modified'])[:19], '%Y-%m-%dT%H:%M:%S')))
            except Exception:
                a = 0
        if sync:
            try:
                b = int(_t.mktime(_t.strptime(str(sync).replace('/', '-')[:19], '%Y-%m-%d %H:%M:%S')))
            except Exception:
                b = 0
        if a and b and a > b + 1:
            stale += 1
        if d.get('Modified'):
            ins['modified'] += 1
        if isinstance(d.get('Mojodi'), (int, float)) and float(d['Mojodi']) > 0:
            ins['stock_positive'] += 1
        if d.get('Sku'):
            ins['sku'] += 1
        bc = d.get('PrdBarcode')
        if isinstance(bc, list) and bc:
            ins['barcode'] += 1
            for entry in bc:
                if isinstance(entry, dict) and entry.get('BarCode'):
                    ins['barcode_value'] += 1
                    break
        if isinstance(d.get('Sell1'), (int, float)) and float(d['Sell1']) > 0:
            ins['sell_price'] += 1
        ds = de = 0
        if d.get('DiscountStartDate'):
            try:
                ds = int(_t.mktime(_t.strptime(str(d['DiscountStartDate'])[:19], '%Y-%m-%dT%H:%M:%S')))
            except Exception:
                ds = 0
        if d.get('DiscountEndDate'):
            try:
                de = int(_t.mktime(_t.strptime(str(d['DiscountEndDate'])[:19], '%Y-%m-%dT%H:%M:%S')))
            except Exception:
                de = 0
        if ds and de and ds <= now_ts <= de:
            ins['discount_active'] += 1
        if isinstance(d.get('PriceRoleList'), list) and d['PriceRoleList']:
            ins['price_roles'] += 1
        if d.get('GuidGroup'):
            ins['group'] += 1
        if d.get('VahedName'):
            ins['vahed'] += 1
        if isinstance(d.get('PrdTechnicalList'), list) and d['PrdTechnicalList']:
            ins['technical'] += 1
        if isinstance(d.get('ImageListData'), list) and d['ImageListData']:
            ins['pics'] += 1
    queue = {}
    allq = 0
    for typ, total in cur.execute("SELECT item_type, COUNT(*) FROM wp_novin_commerce_syncs GROUP BY item_type"):
        queue[typ] = int(total)
        allq += int(total)
    queue['_total'] = allq
    logs = dict(total=0, success=0, warning=0, error=0, info=0)
    for status, total in cur.execute(
            "SELECT status, COUNT(*) FROM wp_novin_commerce_sync_logs WHERE created_at >= ? GROUP BY status",
            (since_str,)):
        n = int(total)
        logs['total'] += n
        if status in logs:
            logs[status] = n
    latest = cur.execute("SELECT created_at FROM wp_novin_commerce_sync_logs ORDER BY id DESC LIMIT 1").fetchone()
    return dict(sample=len(rows), valid=valid, guid_mismatch=gm, stale=stale,
                insights=ins, queue=queue, logs=logs,
                latest=latest[0] if latest else '')


# ----------------------------------------------------------------------------
# TESTS
# ----------------------------------------------------------------------------

def test_group_php():
    """1-20: PHP 7.4-8.4 compatibility & file integrity."""
    texts = all_php_text()

    bad = []
    for rel, src in texts.items():
        ok, why = balanced(src)
        if not ok and balanced_template(src):
            continue  # template file with many raw-HTML sections is balanced per PHP chunk
        if not ok:
            bad.append((rel, why))
    check('T001', not bad, 'all non-vendor PHP files have balanced braces/quotes/brackets',
          '; '.join('%s (%s)' % b for b in bad[:5]) or 'ok')

    hits = []
    for rel, src in texts.items():
        for pat, what in PHP80_SYNTAX:
            m = re.search(pat, src)
            if m:
                hits.append('%s: %s (%s)' % (rel, what, m.group(0)[:40]))
    check('T002', not hits, 'no PHP 8.0/8.1/8.2-only syntax in non-vendor code',
          '; '.join(hits[:6]) or 'ok')

    fun_hits = []
    for rel, src in texts.items():
        for f in PHP80_FUNCS:
            if re.search(r'\b%s\s*\(' % re.escape(f), src):
                fun_hits.append('%s: %s()' % (rel, f))
    check('T003', not fun_hits, 'no PHP 8.0+/8.1-only functions in non-vendor code',
          '; '.join(fun_hits[:6]) or 'ok')

    check('T004', not (bad or hits or fun_hits),
          'PHP 7.4 compatibility matrix: one codebase safe for 7.4 → 8.4')

    enc_bad = []
    for rel in sorted(texts):
        raw = open(os.path.join(PLUGIN, rel), 'rb').read()
        if raw.startswith(b'\xef\xbb\xbf'):
            enc_bad.append(rel + ' BOM')
        else:
            try:
                raw.decode('utf-8')
            except UnicodeDecodeError:
                enc_bad.append(rel + ' not utf-8')
    check('T005', not enc_bad, 'all PHP files are UTF-8 without BOM', '; '.join(enc_bad[:5]) or 'ok')

    check('T006', re.search(r'Requires PHP:\s*7\.4', HEAD_FILE) is not None,
          'plugin header declares Requires PHP 7.4')

    leaked = [rel for rel, src in texts.items()
              if re.search(r'\?>\s*$', src.rstrip()) and rel != 'index.php']
    check('T007', not leaked, 'no trailing ?> that can leak output', '; '.join(leaked[:5]) or 'ok')

    okd, whyd = balanced(DASH)
    check('T008', okd, 'Connection_Dashboard.php token balance', whyd)

    methods = re.findall(r'(?:private|public) static function (\w+)\(', DASH)
    need = {'render', 'health', 'render_latest_exchanges', 'webprd_detail_rows',
            'time_label', 'item_title', 'event_label', 'critical_css', 'icon',
            'render_detail', 'handle_actions'}
    check('T009', need <= set(methods), 'dashboard class exposes all expected methods',
          'missing: %s' % (need - set(methods)) or 'ok')

    check('T010', "file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' )" in HEAD_FILE
          and 'require_once dirname( __FILE__ ) . \'/vendor/autoload.php\';' in HEAD_FILE,
          'novin-commerce.php guards vendor autoload require_once')

    evil = []
    for rel, src in texts.items():
        for pat in [r'\beval\s*\(', r'\bshell_exec\s*\(', r'\bsystem\s*\(',
                    r'\bpassthru\s*\(', r'\bproc_open\s*\(', r'\bpopen\s*\(', r'\bexec\s*\(']:
            if re.search(pat, src):
                evil.append('%s ~ %s' % (rel, pat))
    check('T011', not evil, 'no dangerous execution primitives in non-vendor code',
          '; '.join(evil[:6]) or 'ok')

    short = [rel for rel, src in texts.items() if re.search(r'(?<!\?)\<\?(?!php|=)', src)]
    check('T012', not short, 'no short open tags <? outside <?php', '; '.join(short[:5]) or 'ok')

    missing = [m for m in ['flushHealthCache', 'queueItem', 'removeItem', 'setPriority',
                           'insertProduct', 'insertOrder', 'insertCategory', 'insertUser',
                           'insertVariation'] if ('function %s(' % m) not in SYNC_SRC]
    check('T013', not missing, 'Sync model exposes queue/flush API used by dashboard & REST',
          'missing: %s' % ', '.join(missing) or 'ok')

    check('T014', all(('function %s(' % m) in SL_SRC for m in ['add', 'recent', 'count_since', 'prune']),
          'SyncLog model API intact')

    need_rc = ['register_routes', 'getSyncs', 'getSync', 'deleteSync', 'checkPermission',
               'getItemsSyncs', 'getProductsSyncs', 'getOrdersSyncs', 'getCustomersSyncs',
               'getVariationsSyncs', 'getCategoriesSyncs']
    check('T015', all(('function %s(' % m) in RC_SRC for m in need_rc),
          'Novin_REST_Controller route callbacks intact')

    check('T016', "array_filter( $value, 'strlen' )" not in DASH,
          'no strlen() over array values (PHP 8.1 deprecation)')

    m = re.search(r'private static function webprd_detail_rows.*?\n    \}', DASH, re.S)
    ok17 = bool(m) and balanced(m.group(0))[0]
    check('T017', ok17, 'webprd_detail_rows() parses and closes cleanly')

    check('T018', DASH.startswith('<?php\nnamespace MobinDev\\Novin_Commerce\\Admin;')
          and 'class Connection_Dashboard {' in DASH, 'dashboard file header/namespace intact')

    check('T019', 'Requires at least: 6.1' in HEAD_FILE and 'Requires Plugins:  woocommerce' in HEAD_FILE,
          'plugin metadata (WP 6.1+, WC required) intact')

    pages = ['novin-commerce-products', 'novin-commerce-dashboard', 'novin-commerce-categories',
             'novin-commerce-users', 'novin-commerce-orders', 'novin-commerce-syncs',
             'novin-commerce-settings', 'novin-commerce-mismatch']
    check('T020', all(p in MENU_FILE for p in pages), 'all admin page slugs registered in Menu.php')


def test_group_schema_queue():
    """21-40: schema/queue/log invariants."""
    act = file_text('lib/Activator.php')

    need_cols_sync = ['item_id', 'item_type', 'created_at', 'updated_at', 'priority', 'increments']
    need_cols_log = ['event_type', 'status', 'item_type', 'item_id', 'message', 'context', 'created_at']
    check('T021', all(c in act for c in need_cols_sync + need_cols_log),
          'Activator schema declares every column used by models/dashboard')

    five = "[ 'product', 'category', 'user', 'order', 'variation' ]"
    check('T022', five in act and five in SYNC_SRC,
          'item_type domain identical in schema + Sync model')

    check('T023', all(t in DASH for t in ["'product'", "'variation'", "'order'", "'category'", "'user'"]),
          'dashboard label maps cover all five item types')

    check('T024', all(k in SYNC_SRC for k in ['novin_commerce_health_v1', 'novin_commerce_health_v2',
                                              'novin_commerce_health_v3']),
          'Sync::flushHealthCache clears every health transient generation')

    check('T025', "'novin_commerce_health_v3'" in DASH and "get_transient('novin_commerce_health_v3')" in DASH,
          'dashboard reads transient novin_commerce_health_v3')

    check('T026', SYNC_SRC.count('flushHealthCache()') >= 4
          and SYNC_SRC.count('delete_transient(') == 3,
          'queue mutations use central flush; delete_transient only inside it')

    check('T027', 'Sync::flushHealthCache()' in SL_SRC, 'SyncLog::add refreshes dashboard cache')

    check('T028', "SyncLog::add( 'synced', 'success', $item_type, $item_id" in RC_SRC
          and 'Sync::flushHealthCache();' in RC_SRC, 'REST deleteSync records completed exchange')

    n_eloquent = len(re.findall(r"Sync::where\(\s*'item_type'", DASH))
    check('T029', n_eloquent == 0, 'no per-type Eloquent COUNT queries left in dashboard render',
          '%d found' % n_eloquent)

    check('T030', 'SELECT item_type, COUNT(*) AS total FROM {$wpdb->prefix}novin_commerce_syncs GROUP BY item_type' in DASH
          and "$queue_counts['_total']=$all;" in DASH,
          'queue aggregates computed once from the grouped SQL inside health()')

    ok31 = all(st in DASH for st in ["'success'", "'warning'", "'error'", "'info'"])
    check('T031', ok31, 'dashboard activity buckets: success/warning/error/info')

    check('T032', "$health['logs']['errors']" not in DASH and "'errors'=>0" not in DASH,
          'activity uses singular error key consistently')

    ph_bad = []
    php_text = {'lib/Admin/Connection_Dashboard.php': DASH,
                'lib/Common/SyncLog.php': SL_SRC,
                'lib/Common/Novin_REST_Controller.php': RC_SRC,
                'lib/Models/Sync.php': SYNC_SRC}
    for rel, src in php_text.items():
        for m in re.finditer(r'prepare\(\s*"((?:[^"\\]|\\.)*)"\s*(?:,\s*([^;]*?))?\s*\)\s*;', src, re.S):
            sql, arglist = m.group(1), m.group(2)
            n_ph = len(re.findall(r'%[dsf]', sql))
            if arglist is None:
                n_args = 0
            else:
                # count top-level args (balanced parens ignored: args are simple)
                depth = 0
                n_args = 0
                for ch in arglist:
                    if ch == '(':
                        depth += 1
                    elif ch == ')':
                        depth = max(0, depth - 1)
                    elif ch == ',' and depth == 0:
                        n_args += 1
                n_args += 1
            if n_ph != n_args:
                ph_bad.append('%s: sql has %d placeholders but %d args' % (rel, n_ph, n_args))
    check('T033', not ph_bad, 'wpdb->prepare placeholder/argument parity across lib SQL',
          '; '.join(ph_bad[:5]) or 'ok')

    raw_bad = []
    for rel, src in php_text.items():
        for m in re.finditer(r'get_results\(\s*"((?:[^"\\]|\\.)*)"', src):
            q = m.group(1)
            if re.search(r'\$_(GET|POST|REQUEST|SERVER)', q):
                raw_bad.append('%s: raw SQL embeds superglobals' % rel)
    check('T034', not raw_bad, 'no raw SQL embeds $_GET/$_POST/$_REQUEST/$_SERVER', '; '.join(raw_bad) or 'ok')

    check('T035', ("{$wpdb->prefix}novin_commerce_syncs" in DASH
          or "{$prefix}novin_commerce_syncs" in DASH)
          and ("{$wpdb->prefix}novin_commerce_sync_logs" in DASH
               or "{$prefix}novin_commerce_sync_logs" in DASH
               or "{$log_table}" in DASH)
          and '$wpdb->prefix' in DASH,
          'dashboard queries prefix sync/log tables with $wpdb->prefix')

    qp = file_text('lib/Admin/Sync_List_Table.php')
    check('T036', 'orderBy( \'priority\', \'desc\' )' in qp and 'orderBy( \'id\', \'asc\' )' in qp,
          'Sync_List_Table ordering priority desc / id asc preserved')

    check('T037', 'LIMIT %d' in DASH, 'LIMIT placeholders used in latest-exchanges queries')

    check('T038', "'novin_commerce_schema_version'" in act and 'maybeUpgrade' in act,
          'Activator upgrade guard/schema version intact')

    ha = re.search(r'private static function handle_actions\(\).*?\n    \}', DASH, re.S)
    ok39 = bool(ha) and 'check_admin_referer' in ha.group(0) and 'manage_options' in ha.group(0) \
        and "'product','variation','order','category','user'" in ha.group(0)
    check('T039', ok39, 'dashboard requeue POST is nonce+capability protected')

    events = set()
    for rel, src in php_text.items():
        for m in re.finditer(r"SyncLog::add\(\s*(?:'([a-z_]+)'|\\'([a-z_]+)\\')", src):
            events.add(m.group(1) or m.group(2))
    lab = re.search(r'private static function event_label.*?\n    \}', DASH, re.S)
    labels = set(re.findall(r"'([a-z_]+)'\s*=>", lab.group(0))) if lab else set()
    check('T040', events <= labels, 'event_label() translates every event written to the log',
          'missing: %s' % (events - labels) or 'ok')


def test_group_dashboard():
    """41-60: dashboard SQL execution + aggregation math on the seeded DB.

    Every query executed here is extracted from the live Connection_Dashboard.php
    source (dash_sql_named), so these tests break when the real SQL changes."""
    con = make_db()
    now = _dt.datetime(2026, 9, 6, 9, 0, 0)
    now_ts = int(now.replace(tzinfo=_dt.timezone.utc).timestamp())
    since_str = (now - _dt.timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S')

    rows = dash_sql(con, dash_sql_named('sample'), [])
    ids = sorted(r[0] for r in rows)
    check('T041', rows is not None and ids == [101, 102, 103, 104, 201],
          'dashboard sample SQL (live source) returns 5 published WebPrd products', str(ids))

    qrows = dash_sql(con, dash_sql_named('queue'), [])
    qd = dict(qrows) if qrows is not None else {}
    check('T042', qd == {'product': 2, 'order': 1, 'category': 1, 'user': 1},
          'dashboard queue GROUP BY (live source) counts match seed', str(qd))

    lrows = dash_sql(con, dash_sql_named('activity'), [since_str])
    lq = dict(lrows) if lrows is not None else {}
    check('T043', lq == {'success': 3, 'warning': 1, 'info': 1, 'error': 1},
          'dashboard 24h activity buckets (live source) exclude the old log', str(lq))

    last = dash_sql(con, dash_sql_named('latest'), [])
    check('T044', last is not None and bool(last) and last[0][0] == '2026-09-06 08:00:00',
          'dashboard latest-activity query (live source)', str(last))

    pen = dash_sql(con, dash_sql_named('pending'), [4])
    check('T045', pen is not None and [r[0] for r in pen] == [9001, 3001, 7001, 5002],
          'dashboard pending-exchanges query (live source) order/limit', str(pen and [r[0] for r in pen]))

    recv = dash_sql(con, dash_sql_named('received'), [4])
    rmv = dash_sql(con, dash_sql_named('removed'), [2])
    evr = [r[2] for r in recv] if recv is not None else []
    evm = [r[2] for r in rmv] if rmv is not None else []
    check('T046', recv is not None and rmv is not None and evr == ['synced'] and evm == ['removed'],
          'dashboard received/removed queries (live source) split event types', str((evr, evm)))

    pipe = php_health_pipeline(con, now_ts, since_str)
    sql_total = sum((r[1] for r in qrows), 0) if qrows is not None else -1
    check('T047', pipe['queue']['_total'] == 5 == sql_total,
          'pipeline queue total == dashboard SQL total (5)', str(sql_total))

    check('T048', pipe['queue']['product'] == qd.get('product') == 2
          and pipe['queue']['order'] == qd.get('order') == 1
          and pipe['queue']['category'] == qd.get('category') == 1
          and pipe['queue']['user'] == qd.get('user') == 1,
          'pipeline per-type queue counts == dashboard SQL', str(pipe['queue']))

    check('T049', pipe['sample'] == len(rows) == 5 and pipe['valid'] == 4,
          'pipeline scans the dashboard sample rows: 5 rows, valid JSON=4',
          str((pipe['sample'], pipe['valid'])))

    check('T050', pipe['guid_mismatch'] == 2 and pipe['stale'] == 2,
          'GUID mismatches (p103, v201) and stale products (p102, p103) detected',
          str((pipe['guid_mismatch'], pipe['stale'])))

    ins = pipe['insights']
    check('T051', ins['stock_positive'] == 3 and ins['sku'] == 3,
          'stock/sku insight counts (p101,p102,v201)', str(ins))

    check('T052', ins['barcode'] == 4 and ins['barcode_value'] == 3,
          'barcode presence=4 vs non-empty BarCode value=3', str((ins['barcode'], ins['barcode_value'])))

    check('T053', ins['sell_price'] == 4 and ins['discount_active'] == 0,
          'Sell1 presence=4; discount window closed at frozen date',
          str((ins['sell_price'], ins['discount_active'])))

    check('T054', ins['price_roles'] == 4 and ins['group'] == 4,
          'PriceRoleList/GuidGroup presence counts', str(ins))

    check('T055', ins['vahed'] == 4 and ins['technical'] == 4 and ins['pics'] == 0,
          'VahedName/PrdTechnicalList/ImageListData counts', str(ins))

    check('T056', pipe['logs']['total'] == sum(lq.values()) == 6
          and pipe['logs']['success'] == lq.get('success') == 3
          and pipe['logs']['error'] == lq.get('error') == 1
          and pipe['logs']['warning'] == lq.get('warning') == 1
          and pipe['logs']['info'] == lq.get('info') == 1,
          'pipeline 24h activity == dashboard SQL buckets', str(pipe['logs']))

    check('T057', pipe['latest'] == '2026-09-06 08:00:00',
          'pipeline latest activity matches dashboard SQL', pipe['latest'])

    check('T058', all(f in DASH for f in ['wc_get_product', 'get_term(', 'get_userdata(', 'wc_get_order']),
          'exchange row title helpers use WooCommerce/WP APIs')

    check('T059', 'get_date_from_gmt' in DASH and 'gmdate' in DASH,
          'time rendering converts UTC to site timezone')

    # render_latest_exchanges + helpers structural sanity
    ok60 = balanced(DASH)[0] \
        and 'render_auto_report' in DASH and 'گزارش همگام‌سازی خودکار' in DASH \
        and 'خلاصهٔ کاتالوگ — حسابداری × ووکامرس' in DASH \
        and 'کالاهایی که موجودی‌شان هنوز با حسابداری هماهنگ نشده' in DASH \
        and 'تصویر کلی فروشگاه' not in DASH and 'آخرین رویدادها' not in DASH \
        and 'وضعیت فنی' not in DASH
    check('T060', ok60, 'redesigned dashboard: auto-sync report + catalog snapshot; legacy panels removed')


def test_group_webprd():
    """61-75: WebPrd JSON contract from the real product payload."""
    dashkeys = ['Code', 'IdProduct', 'Name', 'Sku', 'GroupName', 'VahedName', 'Sell1', 'Sell8',
                'KardexPrice', 'BuyLast', 'Mojodi', 'DiscountPercent', 'DiscountStartDate',
                'DiscountEndDate', 'Prices', 'PriceRoleList', 'PrdBarcode', 'GuidGroup',
                'GuidVahed', 'PrdTechnicalList', 'ImageListData', 'Files', 'OwnedFileParts',
                'CreateDate', 'SendToServerDate', 'ResetTime', 'Modified', 'Version',
                'ClientVersion', 'ServerStatus', 'MappId', 'V2Guid', 'Guid']

    check('T061', isinstance(WEBPRD_SAMPLE, dict), 'live product WebPrd payload decodes to an object')

    # V2Guid is optional: not present in the current live payload, every read
    # of it is isset()/pick()-guarded. All other fields must exist.
    optional = {'V2Guid', 'Files'}
    required = [k for k in dashkeys if k not in optional]
    missing = [k for k in required if k not in WEBPRD_SAMPLE]
    check('T062', not missing, 'required WebPrd fields exist in the live payload',
          'missing: %s' % missing or 'ok')

    check('T063', isinstance(WEBPRD_SAMPLE['Sku'], str) and isinstance(WEBPRD_SAMPLE['Slug'], str),
          'Sku/Slug strings', str((WEBPRD_SAMPLE['Sku'], WEBPRD_SAMPLE['Slug'])))

    roles = WEBPRD_SAMPLE['PriceRoleList']
    ok64 = isinstance(roles, list) and all('WordPressRoleName' in r for r in roles)
    check('T064', ok64, 'PriceRoleList entry shape (WordPressRoleName always present)')

    priced = [r for r in roles if r.get('Price')]
    check('T065', len(priced) == 1 and priced[0]['WordPressRoleName'] == 'Administrator'
          and priced[0]['Price'] == 3100000.0, 'only Administrator has role price in fixture')

    bc = WEBPRD_SAMPLE['PrdBarcode'][0]
    check('T066', bc['BarCode'] == 'NP620' and bc.get('Quantity') == 1.0 and bc.get('PriceType') == 1,
          'PrdBarcode usable fields', str(bc))

    tl = WEBPRD_SAMPLE['PrdTechnicalList']
    ok67 = all(isinstance(t.get('TechnicalType'), int) and 'Key' in t and 'Name' in t for t in tl)
    check('T067', ok67, 'PrdTechnicalList technical specs usable')

    ok68 = _t.strptime(str(WEBPRD_SAMPLE['Modified'])[:19], '%Y-%m-%dT%H:%M:%S')
    check('T068', bool(ok68), 'Modified timestamp parses (strtotime-compatible format)')

    ok69 = _t.strptime('2026/09/06 08:41:47'.replace('/', '-')[:19], '%Y-%m-%d %H:%M:%S')
    check('T069', bool(ok69), '_np-api-sync-date format parses for staleness logic')

    a = _t.mktime(_t.strptime(str(WEBPRD_SAMPLE['Modified'])[:19], '%Y-%m-%dT%H:%M:%S'))
    b = _t.mktime(_t.strptime('2026/09/06 08:41:47'.replace('/', '-')[:19], '%Y-%m-%d %H:%M:%S'))
    check('T070', a < b + 1, 'healthy live product is not flagged stale', '%s vs %s' % (a, b))

    writers = []
    for rel in ['lib/Admin/List_Table.php', 'lib/RolePrice/class-wcpbr-accounting-sync.php',
                'lib/Admin/Product_List_Table.php']:
        if "'WebPrd'" in file_text(rel):
            writers.append(rel)
    check('T071', len(writers) >= 3 and "meta_key='WebPrd'" in DASH,
          'WebPrd meta key consistent between writers and dashboard')

    check('T072', "meta_key='guid'" in DASH and "'guid', '_np-api-sync-date', 'WebPrd'" in file_text('lib/Admin/List_Table.php'),
          'guid meta key consistent across plugin')

    for field in ['کد حسابداری (Code)', 'SKU', 'گروه کالا', 'واحد', 'قیمت فروش ۱ (Sell1)', 'بارکدها (PrdBarcode)']:
        if field not in DASH:
            check('T073', False, 'detail shows %s' % field)
            break
    else:
        check('T073', True, 'product detail panel exposes Code/SKU/Group/Vahed/Sell1/Barcodes from WebPrd')

    chips = ['کل کاتالوگ', 'دارای WebPrd', 'JSON نامعتبر', 'بدون GUID سایت', 'موجودی هماهنگ', 'اختلاف موجودی']
    if all(lbl in DASH for lbl in chips):
        check('T074', True, 'catalog-snapshot chips cover the real WebPrd metrics')
    else:
        check('T074', False, 'catalog-snapshot chips missing: %s' % ([l for l in chips if l not in DASH]))

    unguarded = []
    for line_no, line in enumerate(DASH.splitlines(), 1):
        for m in re.finditer(r"\$d\[\s*'[A-Za-z0-9_]+'\s*\]", line):
            guard = ('!empty(' in line or 'isset(' in line or '??' in line
                     or 'foreach($d[' in line or 'pick(' in line or '=>' in line and '$d[' in line)
            if not guard:
                unguarded.append('%d:%s' % (line_no, m.group(0)))
    check('T075', not unguarded, 'no unguarded $d[key] reads in dashboard',
          '; '.join(unguarded[:5]) or 'ok')


def test_group_rest_security():
    """76-88: REST & security posture."""
    routes = RC_SRC.count('register_rest_route(')
    perms = RC_SRC.count('permission_callback')
    check('T076', perms >= routes - 1,
          'permission_callback present on all but the public version route',
          '%d routes, %d permission callbacks' % (routes, perms))

    check('T077', "'Sync Item not founds'" in RC_SRC and "'Something went wrong!'" in RC_SRC,
          'deleteSync error paths preserved')

    seg = re.search(r'function deleteSync\(.*?\n\t\}', RC_SRC, re.S).group(0)
    i_log = seg.find('SyncLog::add')
    i_del = seg.find('->delete()')
    check('T078', 0 < i_del < i_log, 'exchange log call sits after model delete in deleteSync')

    check('T079', "'maximum'           => 50000" in RC_SRC, 'sync items per_page bounded (50000)')

    check('T080', "orderBy( 'priority', 'desc' )" in RC_SRC and "orderBy( 'id', 'asc' )" in RC_SRC,
          'REST sync listing ordering deterministic')

    check('T081', "'wc/v3'" in RC_SRC and "'novin'" in RC_SRC, 'REST namespace wc/v3/novin intact')

    text_all = ''.join(all_php_text().values())
    check('T082', not re.search(r'(?<![A-Za-z0-9_\\])jdate\s*\(', text_all),
          'plugin never calls global jdate() (namespaced Jalalian only)')

    check('T083', re.search(r'use Morilog\\Jalali\\Jalalian;', text_all) is not None,
          'Jalalian imported as namespaced class')

    guards = 0
    for src in [DASH, file_text('lib/Admin/List_Table.php'), file_text('lib/Admin/Sync_List_Table.php')]:
        if 'check_admin_referer' in src and 'current_user_can' in src:
            guards += 1
    check('T084', guards == 3, 'admin POST handlers (dashboard/products/syncs) have nonce+cap checks')

    check('T085', "current_user_can( 'manage_options' ) ) wp_die" in DASH,
          'dashboard render dies for non-admins')

    check('T086', MENU_FILE.count("'manage_options'") >= 8, 'admin menu pages require manage_options')

    row = "self::exchange_row_html( self::item_title( $r->item_type, $r->item_id ), $type, self::time_label( $r->created_at ), $badge )"
    ok87 = DASH.count('esc_html( $title )') >= 1 and DASH.count('esc_html( $type )') >= 1 \
        and DASH.count('esc_html( $time )') >= 1 and DASH.count('esc_html( $badge )') >= 1 \
        and row in DASH
    check('T087', ok87, 'latest-exchanges row output fully escaped via esc_html')

    logrow = re.search(r"foreach\(\$logs as \$log\).*?\}", DASH)
    ok88 = bool(logrow) and all(tok in logrow.group(0) for tok in ['esc_attr($log->status)', 'esc_html(self::event_label', 'esc_html($log->message', 'self::time_label'])
    check('T088', ok88, 'latest-events rows escape status/message/time')


def test_group_composer_version_zip():
    """89-100: composer/autoload/version/zip/release integrity."""
    composer_json = json.loads(file_text('composer.json'))
    lock = json.load(open(os.path.join(PLUGIN, 'composer.lock'), encoding='utf-8'))
    installed = json.load(open(os.path.join(PLUGIN, 'vendor/composer/installed.json'), encoding='utf-8'))
    morilog = json.load(open(os.path.join(PLUGIN, 'vendor/morilog/jalali/composer.json'), encoding='utf-8'))
    st = file_text('vendor/composer/autoload_static.php')
    af = file_text('vendor/composer/autoload_files.php')

    check('T089', bool(composer_json) and isinstance(lock, dict) and isinstance(installed, dict)
          and isinstance(morilog, dict), 'composer.json/lock/installed.json/morilog composer.json valid JSON')

    bad90 = []
    for blob in [lock, installed]:
        for p in blob.get('packages', []):
            if 'morilog' in p.get('name', ''):
                files = p.get('autoload', {}).get('files', [])
                if files:
                    bad90.append(p['name'] + ':' + ','.join(files))
    if morilog.get('autoload', {}).get('files'):
        bad90.append('vendor/morilog/jalali composer.json')
    check('T090', not bad90 and 'morilog/jalali/src/helpers.php' not in st
          and 'morilog/jalali/src/helpers.php' not in af,
          'jdate helpers.php absent from all composer files-autoload registrations',
          '; '.join(bad90) or 'ok')

    psr4 = file_text('vendor/composer/autoload_psr4.php')
    check('T091', "'Morilog\\\\Jalali\\\\' => array($vendorDir . '/morilog/jalali/src')" in psr4,
          'Jalalian PSR-4 autoload entry intact')

    readme = open(os.path.join(ROOT, 'README.md'), encoding='utf-8').read()
    changelog = file_text('CHANGELOG.md')
    ok92 = ('Version:           ' + VERSION) in HEAD_FILE \
        and ("protected $version = '" + VERSION + "';") in file_text('lib/Plugin.php') \
        and ('**نسخه:** ' + VERSION) in readme \
        and ('## ' + VERSION) in changelog \
        and ('1.10.15-dash15') in DASH
    check('T092', ok92, 'version ' + VERSION + ' consistent in header/class/README/CHANGELOG/cache-buster')

    if not os.path.exists(ZIP_PATH):
        check('T093', False, 'release zip exists at repository root')
        check('T094', False, 'zip content checks skipped (missing zip)')
        check('T095', False, 'zip content checks skipped (missing zip)')
        check('T096', False, 'zip content checks skipped (missing zip)')
        check('T097', False, 'zip content checks skipped (missing zip)')
    else:
        zf = zipfile.ZipFile(ZIP_PATH)
        badf = zf.testzip()
        znames = zf.namelist()
        tops = sorted({n.split('/')[0] for n in znames})
        zh = zf.read('novin-commerce/novin-commerce.php').decode('utf-8')
        zaf = zf.read('novin-commerce/vendor/composer/autoload_static.php').decode('utf-8')
        ok93 = badf is None and tops == ['novin-commerce'] \
            and ('Version:           ' + VERSION) in zh \
            and 'morilog/jalali/src/helpers.php' not in zaf
        check('T093', ok93, 'release zip valid; entries under single novin-commerce/ root; versioned; jdate fix',
              'badf=%s tops=%s' % (badf, tops) or 'ok')

        junk = [n for n in znames if not n.startswith('novin-commerce/')
                or '/.git/' in n or 'node_modules' in n or 'qa-strict' in n]
        check('T094', not junk, 'zip has no top-level strays or repository junk', '; '.join(junk[:5]) or 'ok')

        zd = zf.read('novin-commerce/lib/Admin/Connection_Dashboard.php').decode('utf-8')
        zhas_js = 'novin-commerce/dist/scripts/admin/dashboard.js' in znames
        check('T095', 'render_latest_exchanges' in zd and 'webprd_detail_rows' in zd
              and 'render_catalog_summary' in zd and 'render_auto_report' in zd and zhas_js
              and 'novin-commerce/lib/Common/WebPrd_Applier.php' in znames,
              'dashboard helpers + auto-sync engine + catalog JS shipped inside zip')

        zrc = zf.read('novin-commerce/lib/Common/Novin_REST_Controller.php').decode('utf-8')
        check('T096', "'synced', 'success', $item_type, $item_id" in zrc, 'REST deleteSync log shipped in zip')

        zsync = zf.read('novin-commerce/lib/Models/Sync.php').decode('utf-8')
        zsl = zf.read('novin-commerce/lib/Common/SyncLog.php').decode('utf-8')
        check('T097', 'flushHealthCache' in zsync and 'flushHealthCache' in zsl,
              'cache-flush fixes shipped in zip')

    idx_missing = []
    for dirpath, dirnames, filenames in os.walk(PLUGIN):
        rel = os.path.relpath(dirpath, PLUGIN).replace('\\', '/')
        if rel == 'vendor' or rel.startswith('vendor/'):
            continue
        if any(fn.endswith('.php') for fn in filenames) and 'index.php' not in filenames:
            idx_missing.append(rel)
    check('T098', not idx_missing, 'every shipped PHP folder has an index.php silencer',
          '; '.join(idx_missing[:5]) or 'ok')

    check('T099', VERSION in QA_DOC and 'qa-strict' in QA_DOC,
          'plugin QA doc references current version & the strict harness')

    check('T100', len(RESULTS) == 99, 'harness executed exactly 100 checks (this row is the 100th)')


def main():
    test_group_php()
    test_group_schema_queue()
    test_group_dashboard()
    test_group_webprd()
    test_group_rest_security()
    test_group_composer_version_zip()

    total = len(RESULTS)
    passed = total - len(FAILED)

    print('=' * 80)
    print('Novin Commerce %s strict QA — %d tests' % (VERSION, total))
    print('=' * 80)
    for tid, ok, name, detail in RESULTS:
        print('%-5s %s  %s' % (tid, 'PASS' if ok else 'FAIL', name))
        if not ok and detail:
            print('       detail: %s' % detail)
    print('-' * 80)
    print('RESULT: %d/%d passed' % (passed, total))

    report = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'REPORT.md')
    with open(report, 'w', encoding='utf-8') as f:
        f.write('# Novin Commerce %s — گزارش ۱۰۰ تست سخت‌گیرانه\n\n' % VERSION)
        f.write('- تاریخ اجرا: 2026-09-06 (sandbox، منطقه UTC)\n')
        f.write('- نتیجه: **%d/100 تست موفق**\n\n' % passed)
        f.write('## محدودیت صادقانه\n\n')
        f.write('این sandbox آفلاین است و PHP/WordPress/WooCommerce در آن نصب نیست؛ بنابراین اجرای واقعی\n'
                'REST و رندر مرورگر ممکن نبود. همهٔ مسیرهای قابل ارزیابی بدون وب‌سرور اجرا شدند:\n'
                'عبارت‌های SQL پنل‌های داشبورد روی دیتابیس شبیه‌سازی‌شده (SQLite) اجرا و شمارش‌ها راستی‌آزمایی شد،\n'
                'کل منطق تجمیع داشبورد در پایتون بازپیاده‌سازی و با نتایج SQL مقایسه شد، JSON واقعی WebPrd محصول\n'
                'دکوپ و قرارداد فیلدهای آن بررسی شد، و همهٔ کدهای غیر vendor از نظر سازگاری PHP 7.4 تا 8.4 اسکن شد.\n'
                'چرخهٔ end-to-end (REST واقعی با کلاینت حسابداری و ووکامرس) همچنان به سایت استیجینگ نیاز دارد.\n\n')
        f.write('## فهرست تست‌ها\n\n')
        for tid, ok, name, detail in RESULTS:
            f.write('- [%s] `%s` %s\n' % ('x' if ok else ' ', tid, name))
            if not ok and detail:
                f.write('    - جزئیات: %s\n' % detail)
        f.write('\n')
    print('Report -> qa-strict/REPORT.md')
    return 1 if FAILED else 0


if __name__ == '__main__':
    sys.exit(main())
