# QA report — Novin Commerce 1.20.0

**Release decision: NOT PRODUCTION READY**

## Executed in this checkout

| Check | Result | Notes |
|---|---|---|
| PHP parser syntax pass | PASS (static parser) | `php-parser` 3.7.0 parsed all non-vendor/non-dist PHP files because the container has no `php` binary. |
| `git diff --check` | PASS | No whitespace errors in the current patch. |
| WebPrd parser review | PASS (static) | `WebPrd_Parser` is the only WebPrd JSON decoder; consumers use domain methods. |
| Dashboard source review | PASS (static) | Uses `Product_Health_Snapshot::summary()` and bounded `list_rows(..., 12)`. No page-view sync/write path. |
| Fixture files | PRESENT | NP567, Saffron, iPhone, Shirt, Expired Discount, Missing Schedule, Eight Price Levels and Composite are synthetic fixtures. |

## Not executed / release blockers

| Blocker | Evidence | Required fix |
|---|---|---|
| PHP 8.3/8.4 runtime | `php -l`, PHPUnit, PHPCS and PHPStan cannot run because `php` is not installed | Run the full toolchain in PHP 8.3 and 8.4 containers and attach logs. |
| WordPress/WooCommerce runtime | No staging WordPress/WooCommerce database is available | Run activation, migration verification, CRUD, HPOS, REST/AJAX and frontend smoke tests. |
| Migration verification | `Activator::maybeUpgrade()` was not executed against a real DB | Verify all snapshot columns, queue identity, stock-operation primary key and schema version on success/failure paths. |
| Official product fixtures | No WP/Woo execution | Build NP567/Saffron/iPhone/Shirt products and assert stock, parent availability, mode and snapshot fields. |
| Discount lifecycle | No clock-controlled Woo runtime | Assert expired/missing-schedule Accounting Sale cleanup and Manual Sale preservation. |
| Eight price levels | No live role configuration | Assert Sell1…Sell8 remain levels and only explicit `PriceRoleList` mappings create role prices. |
| Concurrency | No two-worker DB test | Run duplicate queue enqueue, atomic stock decrement/restore, refund idempotency and dead-worker lease tests on InnoDB. |
| Provider/OTP/security | No provider or staging | Run generic failure handling, secret masking/encryption, OTP rate-limit and REST permission tests. |

## Required acceptance run

1. `php -l` over all plugin PHP files excluding vendor.
2. PHPCS and PHPStan at the project’s configured levels.
3. PHPUnit/WP-CLI integration suite including `tests/ProductDataModelTest.php` and `tests/ConcurrencyAndSnapshotTest.php`.
4. PHP 8.3 and 8.4 matrix with WooCommerce HPOS on/off.
5. Migration dry run on a copy, forced `ALTER` failure, retry and schema-version assertions.
6. Dashboard query plan and bounded pagination test on a large catalog.
7. Security scan for secrets and final artifact contents.

Until all blockers are closed, do not advertise this build as production-ready.
