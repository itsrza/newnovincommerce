## 1.10.12
- **رفع نصب/به‌روزرسانی:** زیپ انتشار دوباره با ساختار صحیح ساخته شد — همهٔ فایل‌های افزونه داخل پوشهٔ `novin-commerce/` هستند تا وردپرس هنگام «بارگذاری افزونه» همان افزونهٔ قبلی را به‌روزرسانی کند و یک کپی دوم/غیرقابل‌فعال‌سازی نسازد (مشکل نسخهٔ 1.10.11).
- وضعیت فعالیت: متریک «خطا» حالا بر اساس دادهٔ WebPrd کالاها محاسبه می‌شود — کالایی نیازمند بررسی است که GUID داخل JSON آن با سایت ناخوانا/نامطابق باشد یا Modified حسابداری از تاریخ آخرین Sync قدیمی‌تر باشد؛ شمارش هر کالا فقط یک بار (بدون دوبار‌شماری) و نمایش «همگام‌شده و سالم» در کنار آن. رویدادهای ۲۴ ساعت اخیر همچنان در نمودار همان پنل نمایش داده می‌شوند.
- آخرین موارد تبادل: رویدادهای «حذف از صف» (removed) دیگر زیر عنوان «دریافت‌شده توسط حسابداری» نمایش داده نمی‌شوند؛ حالا سه گروه جدا: «در انتظار در صف»، «دریافت‌شده توسط حسابداری» (فقط رویداد synced) و «حذف‌شده از صف — به حسابداری ارسال نشده».
- به‌روزرسانی ابزار ۱۰۰ تست سخت‌گیرانه: افزودن بررسی ساختار پوشهٔ ریشه در زیپ (تا مشکل 1.10.11 دوباره رخ ندهد) و هماهنگ‌سازی تست‌های SQL پنل‌ها با گروه‌بندی جدید. — 100/100 موفق.

## 1.10.11
- داشبورد «مرکز کنترل تبادل» بازبینی و اصلاح شد:
  - صف تبادل: شمارش هر نوع از همان کوئری تجمیعی health خوانده می‌شود (حذف ۶ کوئری تکراری Eloquent)؛ نمایش پیام مناسب وقتی صف خالی است؛ آمار هر لحظه با فیلتر کش.
  - وضعیت فعالیت: سطل «اطلاع» (info) اضافه شد تا مجموع نوار و متریک‌ها با «مجموع ۲۴ ساعت» همیشه برابر باشد؛ زمان آخرین فعالیت هم زیر نمودار نمایش داده می‌شود.
  - آخرین موارد تبادل: حالا هم موارد در انتظار صف را نشان می‌دهد و هم تبادل‌هایی که نرم‌افزار حسابداری دریافت کرده (رویداد synced)؛ نام کالا/فاکتور/دسته/شخص از ووکامرس وردپرس واکشی می‌شود و زمان‌ها به منطقه زمانی سایت تبدیل می‌شود.
  - آخرین رویدادها: برچسب کامل همه رویدادها (queued/requeue/synced/removed/priority/disconnect/sync_datetime)، نمایش زمان سازگار و خروجی کاملاً escaped.
- رفع به‌روز نبودن کش داشبورد: `Sync::flushHealthCache()` هر سه نسل transient (v1/v2/v3) را پاک می‌کند و در همهٔ نقاط تغییر صف/رویداد (queueItem، removeItem، setPriority، SyncLog::add، DELETE سینک، requeue) صدا زده می‌شود.
- ثبت رویداد «synced» موقع دریافت مورد صف توسط نرم‌افزار حسابداری (DELETE /wc/v3/novin/syncs/{id}) تا فعالیت واقعی تبادل در داشبورد دیده شود.
- بهره‌گیری گسترده از JSON محصول (متای WebPrd): جزئیات کالا شامل Code/IdProduct/Name/Sku/GroupName/VahedName/Sell1/Sell8/KardexPrice/BuyLast/Mojodi/درصد و بازه تخفیف/Prices/PriceRoleList/PrdBarcode/PrdTechnicalList/ImageListData/Files و... و کاشی‌های جدید (بارکد مقداردار، قیمت فروش Sell1، تخفیف زمان‌دار فعال، مشخصات فنی) در داشبورد.
- افزودن ابزار تست سخت‌گیرانه `qa-strict/run_tests.py` (۱۰۰ تست: سازگاری PHP 7.4 تا 8.4، اجرای SQL پنل‌ها روی دیتابیس شبیه‌سازی‌شده، قرارداد JSON محصول، امنیت و یکپارچگی بسته).

## 1.10.10
- Fixed `PHP Fatal error: Cannot redeclare jdate()` which happened on sites whose theme or another plugin also declares a global `jdate()` function (e.g. themes shipping their own `jdf.php`). Root cause: the bundled `morilog/jalali` package registered its global helper `src/helpers.php` in Composer's eager `files` autoload, so the plugin's `vendor/autoload.php` executed it on every request before the theme even loaded — and its `jdate()` declaration then collided with the theme's.
- The plugin never calls the global `jdate()` helper — it only uses the namespaced `Morilog\Jalali\Jalalian` class — so `src/helpers.php` has been removed from the Composer `files` autoload (autoload_static.php / autoload_files.php / installed.json / composer.lock / the package's own composer.json). Nothing is lost: `Jalalian` remains PSR-4 autoloaded and lazy-loaded as before, and the plugin no longer injects any global function that can clash with themes/plugins.
- Maintainers: a plain `composer install` / `composer dump-autoload` keeps this fix (lock + installed.json are consistent). Only a `composer update` would re-pull upstream's `files` entry — if you ever run one, re-apply this change or the package will need a patched fork.

## 1.10.9
- Reverted the 1.10.7 attempt to fix the /wc/v3/novin/version PHP-notice-leak issue. That fix (opening an output buffer at plugin-load time, plus a rest_pre_serve_request cleanup filter clearing all open output buffers) was confirmed by the site owner to actually break the endpoint on fadak-gostar.com — reverting to the pre-1.10.7 behaviour (going back to the plain 1.6.1-era code, `return $this->plugin->get_version();`) was confirmed working. getVersion() and the REST/AJAX wiring around it are back to that simple, known-good shape. The underlying PHP-notice-leak (from an unrelated plugin on that site, "company-comment-reaction") is not fixed by us — it was never actually caused by this plugin, and any real fix belongs in WP_DEBUG_DISPLAY configuration on that site or in the other plugin.

## 1.10.8
- Fixed the "قطع ارتباط با حسابداری" (disconnect) link: it previously had no client-side handler outside the مغایرت‌گیری page, so clicking it did nothing on کالاها/دسته‌بندی‌ها/اشخاص/فاکتورها. Added a shared click handler for all list tables.
- Fixed the underlying AJAX handler (wp_ajax_remove_guid), which only ever deleted post meta — this silently failed for دسته‌بندی‌ها (term meta), اشخاص (user meta), and فاکتورها under WooCommerce HPOS (order meta via WC_Order, not postmeta). It's now item-type aware and clears the right storage for each.
- Disconnecting now updates the row in place instead of assuming it can always be removed from the table.

## 1.10.7
- Fixed the WooCommerce REST route /wc/v3/novin/version so it always returns a clean JSON version string, even when another plugin on the same site prints a stray PHP notice into the response body (e.g. a mistimed wp_enqueue_script call). This was breaking version parsing in the accounting desktop client.
- بررسی وضعیت تبادل and ابزارهای رفع مشکل tiles now use the same soft background as the WebPrd Insights cards.
- Page headers (کالاها، دسته‌بندی‌ها، اشخاص، فاکتورها، همگام‌سازی‌ها) now sit lower, span the full width of the table below them, and include a short description per section.
- Products table: consolidated slug/SKU/GUID/accounting-name/sync-date into fewer, denser columns; numeric columns are right-aligned; search placeholder now reflects that it matches name, SKU or GUID.
- Version bump.

## 1.10.6
- Fixed a duplicate/unscoped stylesheet rule that still applied 3px colored top borders to the WebPrd Insights tiles; insight tiles now consistently match the "وضعیت انتشار کالاها" card style (soft background, neutral border, matching radius).
- Added the shared page-header box (title + back-to-dashboard button) to کالاها، دسته‌بندی‌ها، اشخاص، فاکتورها and مغایرت‌گیری pages.
- Sync queue: added single-row delete and bulk delete actions.
- Sidebar: مغایرت‌گیری now appears before تنظیمات, with تنظیمات last.
- Removed leftover emoji from the مغایرت‌گیری page.
- Cache-busted dashboard/admin table stylesheet.

## 1.10.5
- Final dashboard layout hardening with a dedicated section stack and equal-width grids.
- Generic WebPrd Insights cards with stable icon sizing, colors, and explicit text spacing.
- Cache-busted dashboard assets.
- Plugin header declares PHP 7.4 compatibility.


## 1.10.4-patch2
- Fixed fatal error caused by overriding final `List_Table::prepare_items()`.
- Sync queue now uses the shared list-table pagination path through `fetchTableData()`.
- Loaded admin CSS during `admin_enqueue_scripts` to prevent initial unstyled SVG/icon flash.
- Dashboard queue, activity, and recent exchange panels now use the full dashboard width in three equal columns on desktop.
- Added PHP 8.1+ compatibility source for bundled wp-eloquent while preserving the original PHP 7.4-compatible source.
- Removed deprecated null PDO initialization on PHP 8.4+.
## 1.10.4 - 2026-09-01
- Fixed PHP 8.4 Carbon date-casting fatal in the dashboard.
- Removed Eloquent date casting from recent sync rendering by using a lightweight SQL read.
- Added PHP 8.2+ compatibility fixes for the bundled Carbon 2.58 code.
- Added scoped critical dashboard CSS and fixed stylesheet URL/version loading.

## 1.10.4 - 2026-09-01
- Fixed dashboard fatal error caused by Carbon 2.58 date casting on PHP 8.4.
- Removed Eloquent date casting from dashboard recent-sync rendering.
- Added PHP 8.2+ compatibility fixes for the bundled Carbon 2.58 vendor copy.
- Added dashboard critical CSS fallback and scoped SVG sizing to prevent oversized icons.
- Corrected dashboard stylesheet URL/version loading.

## 1.10.2 - 2026-09-01
- Expanded connection dashboard with catalog, queue, activity, publication and health metrics.
- Added lightweight SVG charts and responsive dashboard sections.
- Kept the accounting/C# protocol unchanged.

## 1.10.1 - 2026-09-01
- Removed the 1.10 accounting refresh-request / C# integration additions.
- Fixed the fatal error on the Sync page caused by overriding final parent methods.
- Refined the dashboard queue layout and user-facing wording.
- Removed the accounting-refresh settings and dashboard controls.

## 1.10.0 - 2026-09-01
- Fixed fatal-risk sync queue rendering with a dedicated lightweight table.
- Added accounting refresh request queue and REST endpoints.
- Added product/variation GUID diagnostic endpoint.
- Redesigned settings with top tabs.
- Added manual request to re-send authoritative WebPrd from accounting.


## September 1, 2026 - 1.7.0

* Integrated role-based pricing, multi-unit stock, accounting price sync and dashboard access control into Novin Commerce.
* Restricted WordPress dashboard and Admin Bar to Administrator users only.
* Added role-aware price cache isolation and safer fallback behavior.
* Reduced unnecessary multi-unit stock writes by syncing only when accounting data changes.
* Hardened GUID disconnect AJAX authorization and fixed category REST total counting.
* Improved transaction shortcode authentication, TLS verification, input validation and output escaping.
# Change Log

## April 12, 2015 - 1.0.0

* Fork of the Novin Woocommerce.
* Namespaces and autoloader (PSR-4) support.
* Composer dependency support.
* `Public` changed to `Frontend` due to it being a PHP keyword.

## November 10, 2025 - 1.4.0

* add slug, sku to plugin.
* copy to clipboard slug & sku with click on 
* show acc name in plugin for compare 
* show different color(red) for disagreement product acc name and wp name
* powerful sort and filter for products, categories & users in GUID parameter
* dissconect incorrect relation between acc product and wp product with unlink in GUID parameter
* update servers name and destination
* reconciliation between incorrect products
* prevent copying product meta specifically in GUID field, sync date field and sync status field when duplicating product in site
## 1.7.1
- Added paginated product and variation listing in Novin Commerce.
- Added variation GUID visibility and disconnect support.
- Added product/variation-aware bulk synchronization selection.
- Reduced sync-table memory usage by loading sync rows only for visible items.
- Hardened product-list SQL with prepared queries and fixed allowlists.
- Fixed the Product List Table `defaul` typo.
- Bumped plugin version to 1.7.1.
