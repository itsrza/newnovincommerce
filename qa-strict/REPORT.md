# Novin Commerce 1.10.12 — گزارش ۱۰۰ تست سخت‌گیرانه

- تاریخ اجرا: 2026-09-06 (sandbox، منطقه UTC)
- نتیجه: **100/100 تست موفق**

## محدودیت صادقانه

این sandbox آفلاین است و PHP/WordPress/WooCommerce در آن نصب نیست؛ بنابراین اجرای واقعی
REST و رندر مرورگر ممکن نبود. همهٔ مسیرهای قابل ارزیابی بدون وب‌سرور اجرا شدند:
عبارت‌های SQL پنل‌های داشبورد روی دیتابیس شبیه‌سازی‌شده (SQLite) اجرا و شمارش‌ها راستی‌آزمایی شد،
کل منطق تجمیع داشبورد در پایتون بازپیاده‌سازی و با نتایج SQL مقایسه شد، JSON واقعی WebPrd محصول
دکوپ و قرارداد فیلدهای آن بررسی شد، و همهٔ کدهای غیر vendor از نظر سازگاری PHP 7.4 تا 8.4 اسکن شد.
چرخهٔ end-to-end (REST واقعی با کلاینت حسابداری و ووکامرس) همچنان به سایت استیجینگ نیاز دارد.

## فهرست تست‌ها

- [x] `T001` all non-vendor PHP files have balanced braces/quotes/brackets
- [x] `T002` no PHP 8.0/8.1/8.2-only syntax in non-vendor code
- [x] `T003` no PHP 8.0+/8.1-only functions in non-vendor code
- [x] `T004` PHP 7.4 compatibility matrix: one codebase safe for 7.4 → 8.4
- [x] `T005` all PHP files are UTF-8 without BOM
- [x] `T006` plugin header declares Requires PHP 7.4
- [x] `T007` no trailing ?> that can leak output
- [x] `T008` Connection_Dashboard.php token balance
- [x] `T009` dashboard class exposes all expected methods
- [x] `T010` novin-commerce.php guards vendor autoload require_once
- [x] `T011` no dangerous execution primitives in non-vendor code
- [x] `T012` no short open tags <? outside <?php
- [x] `T013` Sync model exposes queue/flush API used by dashboard & REST
- [x] `T014` SyncLog model API intact
- [x] `T015` Novin_REST_Controller route callbacks intact
- [x] `T016` no strlen() over array values (PHP 8.1 deprecation)
- [x] `T017` webprd_detail_rows() parses and closes cleanly
- [x] `T018` dashboard file header/namespace intact
- [x] `T019` plugin metadata (WP 6.1+, WC required) intact
- [x] `T020` all admin page slugs registered in Menu.php
- [x] `T021` Activator schema declares every column used by models/dashboard
- [x] `T022` item_type domain identical in schema + Sync model
- [x] `T023` dashboard label maps cover all five item types
- [x] `T024` Sync::flushHealthCache clears every health transient generation
- [x] `T025` dashboard reads transient novin_commerce_health_v3
- [x] `T026` queue mutations use central flush; delete_transient only inside it
- [x] `T027` SyncLog::add refreshes dashboard cache
- [x] `T028` REST deleteSync records completed exchange
- [x] `T029` no per-type Eloquent COUNT queries left in dashboard render
- [x] `T030` queue panel reads grouped counts with total + safe fallback
- [x] `T031` dashboard activity buckets: success/warning/error/info
- [x] `T032` activity uses singular error key consistently
- [x] `T033` wpdb->prepare placeholder/argument parity across lib SQL
- [x] `T034` no raw SQL embeds $_GET/$_POST/$_REQUEST/$_SERVER
- [x] `T035` dashboard queries prefix sync/log tables with $wpdb->prefix
- [x] `T036` Sync_List_Table ordering priority desc / id asc preserved
- [x] `T037` LIMIT placeholders used in latest-exchanges queries
- [x] `T038` Activator upgrade guard/schema version intact
- [x] `T039` dashboard requeue POST is nonce+capability protected
- [x] `T040` event_label() translates every event written to the log
- [x] `T041` dashboard sample SQL (live source) returns 5 published WebPrd products
- [x] `T042` dashboard queue GROUP BY (live source) counts match seed
- [x] `T043` dashboard 24h activity buckets (live source) exclude the old log
- [x] `T044` dashboard latest-activity query (live source)
- [x] `T045` dashboard pending-exchanges query (live source) order/limit
- [x] `T046` dashboard received/removed queries (live source) split event types
- [x] `T047` pipeline queue total == dashboard SQL total (5)
- [x] `T048` pipeline per-type queue counts == dashboard SQL
- [x] `T049` pipeline scans the dashboard sample rows: 5 rows, valid JSON=4
- [x] `T050` GUID mismatches (p103, v201) and stale products (p102, p103) detected
- [x] `T051` stock/sku insight counts (p101,p102,v201)
- [x] `T052` barcode presence=4 vs non-empty BarCode value=3
- [x] `T053` Sell1 presence=4; discount window closed at frozen date
- [x] `T054` PriceRoleList/GuidGroup presence counts
- [x] `T055` VahedName/PrdTechnicalList/ImageListData counts
- [x] `T056` pipeline 24h activity == dashboard SQL buckets
- [x] `T057` pipeline latest activity matches dashboard SQL
- [x] `T058` exchange row title helpers use WooCommerce/WP APIs
- [x] `T059` time rendering converts UTC to site timezone
- [x] `T060` latest-exchanges renderer balanced, split by synced/removed
- [x] `T061` live product WebPrd payload decodes to an object
- [x] `T062` required WebPrd fields exist in the live payload
- [x] `T063` Sku/Slug strings
- [x] `T064` PriceRoleList entry shape (WordPressRoleName always present)
- [x] `T065` only Administrator has role price in fixture
- [x] `T066` PrdBarcode usable fields
- [x] `T067` PrdTechnicalList technical specs usable
- [x] `T068` Modified timestamp parses (strtotime-compatible format)
- [x] `T069` _np-api-sync-date format parses for staleness logic
- [x] `T070` healthy live product is not flagged stale
- [x] `T071` WebPrd meta key consistent between writers and dashboard
- [x] `T072` guid meta key consistent across plugin
- [x] `T073` product detail panel exposes Code/SKU/Group/Vahed/Sell1/Barcodes from WebPrd
- [x] `T074` dashboard insight tiles cover new WebPrd metrics
- [x] `T075` no unguarded $d[key] reads in dashboard
- [x] `T076` permission_callback present on all but the public version route
- [x] `T077` deleteSync error paths preserved
- [x] `T078` exchange log call sits after model delete in deleteSync
- [x] `T079` sync items per_page bounded (50000)
- [x] `T080` REST sync listing ordering deterministic
- [x] `T081` REST namespace wc/v3/novin intact
- [x] `T082` plugin never calls global jdate() (namespaced Jalalian only)
- [x] `T083` Jalalian imported as namespaced class
- [x] `T084` admin POST handlers (dashboard/products/syncs) have nonce+cap checks
- [x] `T085` dashboard render dies for non-admins
- [x] `T086` admin menu pages require manage_options
- [x] `T087` latest-exchanges row output fully escaped via esc_html
- [x] `T088` latest-events rows escape status/message/time
- [x] `T089` composer.json/lock/installed.json/morilog composer.json valid JSON
- [x] `T090` jdate helpers.php absent from all composer files-autoload registrations
- [x] `T091` Jalalian PSR-4 autoload entry intact
- [x] `T092` version 1.10.12 consistent in header/class/README/CHANGELOG/cache-buster
- [x] `T093` release zip valid; entries under single novin-commerce/ root; versioned; jdate fix
- [x] `T094` zip has no top-level strays or repository junk
- [x] `T095` dashboard helpers shipped inside zip
- [x] `T096` REST deleteSync log shipped in zip
- [x] `T097` cache-flush fixes shipped in zip
- [x] `T098` every shipped PHP folder has an index.php silencer
- [x] `T099` plugin QA doc references current version & the strict harness
- [x] `T100` harness executed exactly 100 checks (this row is the 100th)

