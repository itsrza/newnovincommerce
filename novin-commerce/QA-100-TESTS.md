# Novin Commerce 1.10.14 — QA Audit

## Result

100/100 strict automated checks passed (see the harness + full report at the repository root under `qa-strict/run_tests.py` → `qa-strict/REPORT.md`, run with plain Python 3, no third-party packages).

### What the strict suite verifies
- **PHP version matrix (7.4 → 8.4):** every non-vendor PHP file is token-balanced (braces/quotes/brackets), UTF-8 without BOM, free of PHP 8.0/8.1/8.2-only syntax (match, nullsafe `?->`, enums, readonly, constructor promotion, attributes) and of PHP 8.0/8.1-only functions, so the same codebase runs on PHP 7.4 through 8.4.
- **Queue / sync-log / schema invariants:** column names, the 5-value item_type domain, `wpdb->prepare` placeholder/argument parity, table prefixing, and every dashboard health-cache transient (`v1/v2/v3`) being flushed by `Sync::flushHealthCache()` on queue/log/REST mutations.
- **Dashboard panel logic is executed, not eyeballed:** the exact SQL of «صف تبادل»، «وضعیت فعالیت»، «آخرین موارد تبادل» and «آخرین رویدادها» is extracted live from `Connection_Dashboard.php` and runs against a seeded SQLite database; a faithful re-implementation of `health()` aggregation is cross-checked against those SQL results (queue counts, 24h status buckets, WebPrd sample/valid/GUID-mismatch/stale/issues counts, nine insight counters).
- **WebPrd JSON contract:** decoded against the real live product payload (the shop owner's REST example); every key the dashboard reads (Guid, Sku, Mojodi, Sell1/Sell8, Prices, PriceRoleList, PrdBarcode, GuidGroup/GuidVahed, PrdTechnicalList, ImageListData, Modified, Version, …) must exist with the expected shape.
- **Security:** nonce + capability checks on all admin POST handlers, escaping of every dynamic output in the new dashboard rows, no dangerous PHP primitives, bounded REST paging, and REST `permission_callback` coverage.
- **Composer/autoload/package integrity:** the global `jdate()` helper file is absent from every Composer `files` autoload registration (composer.lock, installed.json, package composer.json, generated autoload files) while `Morilog\Jalali\Jalalian` stays PSR-4 — the 1.10.10 fatal-error fix cannot regress; version metadata is consistent everywhere.
- **Release zip layout (new in 1.10.14):** every archive entry lives under the single root folder `novin-commerce/` — no top-level PHP files or stray entries — so WordPress «بارگذاری افزونه» updates the existing plugin instead of creating a second, un-activatable copy; and the zip is valid, versioned, and carries the dashboard/rest/composer fixes.

### Important limitation

This audit runs in an offline sandbox without PHP/WordPress/WooCommerce, so it is source-level plus executed-SQL/logic verification — no live wp-admin rendering or real REST round-trip with the Novin desktop client happened here. A final smoke test on a staging WordPress + WooCommerce site (PHP 7.4 and one PHP 8.x, e.g. 8.2/8.3) is still recommended before rollout, exactly as before.
