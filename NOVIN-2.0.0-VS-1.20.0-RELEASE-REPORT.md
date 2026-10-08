# گزارش مقایسه و تصمیم انتشار
## NovinCommerce 2.0.0 در برابر NovinCommerce 1.20.0-hardened

**تاریخ بررسی:** 2026-10-08  
**شاخه بررسی:** `arena/01a0c347-newnovincommerce`  
**نتیجه کوتاه:** نسخه 2.0.0 از نظر ظاهر داشبورد و ایده‌ی read model جذاب‌تر است، اما در وضعیت فعلی جایگزین امن نسخه hardened نیست. پیشنهاد انتشار مستقیم 2.0.0 رد می‌شود؛ مسیر مناسب، یک hybrid بر پایه‌ی هسته‌ی 1.20.0 و با انتقال کنترل‌شده‌ی UX و بعضی abstractionهای 2.0.0 است.

---

## 1. ورودی‌ها و روش بررسی

| بسته | SHA-256 | اندازه | تعداد entry آرشیو | تعداد فایل استخراج‌شده |
|---|---|---:|---:|---:|
| `NovinCommerce-2.0.0 (1).zip` | `a37dc3b0b98d4330c2211045caa08a4c92395542ee99ba9ba2d7a212c914c6bd` | 1,983,263 bytes | 1,792 | 1,643 |
| `NovinCommerce-1.20.0-hardened.zip` (بازسازی‌شده) | `20d01b0c5df77f2a7424cc103c728b406051b224536c00e8c1db6d9804850b36` | 2,127,479 bytes | 1,962 | 1,781 |

بررسی انجام‌شده:

- مقایسه‌ی ساختار، namespace، Composer، bootstrap، lifecycle، migration، uninstall و جدول‌های اختصاصی.
- بررسی parser و مدل WebPrd، موجودی/warehouse، multi-unit/composite، قیمت و discount، queue و stock concurrency.
- بررسی امنیت OTP، encryption، rate limiting، provider logging و پاسخ NPSMS.
- بررسی static PHP با `php-parser@3.7.0`:
  - candidate 2.0.0: **67 فایل، 0 خطا**.
  - current 1.20.0: **77 فایل، 0 خطا**.
- `git diff --check`: **PASS**.
- در این محیط PHP binary، WordPress، WooCommerce و database runtime وجود ندارد؛ بنابراین هیچ نتیجه‌ای از این گزارش به‌تنهایی runtime یا production certification محسوب نمی‌شود.

Artifact فعلی بعد از اصلاح parser و health signals در commit `2c53b2b` بازسازی شده و به branch مربوط push شده است.

---

## 2. تصمیم انتشار

### 2.1 نسخه 2.0.0 به‌صورت فعلی: **NO-GO**

نسخه 2.0.0 نباید مستقیماً روی نصب فعلی جایگزین شود. چند blocker مستقل دارد که صرفاً با تست بیشتر برطرف نمی‌شوند و نیاز به تغییر کد/قرارداد دارند:

1. namespace و branding آن با قرارداد فعلی متفاوت است.
2. migration نسخه schema را بدون verify کامل موفقیت جدول‌ها جلو می‌برد.
3. warehouse scope خالی را عملاً به scope همه‌ی انبارها تبدیل می‌کند.
4. stock multi-unit هنوز read-modify-write است و atomic/idempotent نیست.
5. OTP را به‌صورت plaintext در جدول ذخیره می‌کند و rate limit تلفنی آن race-prone است.
6. encryption در نبود OpenSSL یا secret مناسب fail-closed نیست.
7. ownership قیمت دستی و inherited به اندازه‌ی هسته فعلی سخت‌گیرانه نیست.
8. قرارداد composite/formula با fixture و aliasهای واقعی فعلی کامل منطبق نیست.
9. health read model وضعیت `unknown`، source `Mojodi` و warehouse scope را به‌صراحت و به‌صورت کافی نگه نمی‌دارد.

### 2.2 نسخه 1.20.0-hardened: **baseline بهتر، ولی هنوز production-ready نیست**

نسخه 1.20.0 از نظر namespace، compatibility، ownership، migration verification، currency backward compatibility و concurrency پایه‌ی امن‌تری است. با این حال QA فعلی همچنان این موارد را باز می‌داند:

- اجرای واقعی PHP 8.3/8.4، PHPUnit، PHPCS و PHPStan.
- اجرای WordPress/WooCommerce/HPOS و migration روی database واقعی.
- تست دو-worker برای queue و stock/refund.
- تست provider/OTP و encryption با staging.
- تکمیل و تست uninstall برای جدول‌ها و metadata جدیدی که در hardened اضافه شده‌اند.

بنابراین تصمیم نهایی فعلی **«انتشار هیچ‌کدام بدون staging gate»** است؛ اگر الزام به انتخاب یک پایه باشد، پایه‌ی 1.20.0 انتخاب می‌شود.

---

## 3. مقایسه‌ی معماری و قراردادها

### 3.1 Namespace، Composer و هویت بسته

- 2.0.0 از `Novinwp\\Novin_Commerce\\` استفاده می‌کند و در header/README/Composer به `Novinwp` و `npwp.ir` branding شده است.
- 1.20.0 از `MobinDev\\Novin_Commerce\\` استفاده می‌کند و این namespace در commitهای قبلی عمداً canonical شده است.
- bootstrap نسخه 2.0.0 نیز activation/deactivation و کلاس‌های runtime را با `Novinwp` صدا می‌زند.
- علاوه بر namespace، نام‌های مرکزی هم یکی نیستند: candidate از `Common\\Accounting_Data` و `Common\\Health_Snapshot` و جدول `novin_commerce_health_items` استفاده می‌کند؛ current از parserهای domain و `Product_Health_Snapshot` و جدول `novin_commerce_product_health` استفاده می‌کند.

**نتیجه:** کپی‌کردن فایل‌های 2.0.0 روی 1.20.0 یک upgrade معمولی نیست؛ بدون migration/compatibility bridge، autoload و activation hookها می‌توانند از کار بیفتند و read model جدید نیز به جدول/داده‌ی قبلی متصل نمی‌شود.

### 3.2 lifecycle و side effect در frontend

در candidate، `lib/Plugin.php` در `run()` مستقیماً `Activator::maybeUpgrade()` را اجرا می‌کند. این متد schedule، option و schema را بررسی/تغییر می‌دهد؛ در نتیجه bootstrap عادی frontend/REST نیز می‌تواند migration و write انجام دهد.

در current، `Plugin::run()` migration را به `admin_init` و hook پس‌زمینه‌ی `novin_commerce_run_migration` محدود کرده است. این با قاعده‌ی «dashboard/read نباید migration یا sync سنگین اجرا کند» هم‌راستاتر است.

**برتری قابل انتقال از 2.0.0:** migration chunked و bounded.  
**شرط انتقال:** فقط از مسیر cron/admin و با retry و verify، نه از هر page view.

### 3.3 schema و migration

در candidate، `Activator::activate()` بعد از `createTables()` بدون بررسی return/وجود همه‌ی جدول‌ها مقدار schema را به `6` می‌رساند. `maybeUpgrade()` نیز در مسیر مشابه بعد از `createTables()` option را update می‌کند. خطای DDL می‌تواند پنهان شود و نصب در وضعیتی بماند که version «موفق» اعلام شده ولی ستون/جدول کامل نیست.

در current، `Activator` نسخه schema `9` را فقط بعد از `createTables()`, `ensureQueueSchema()` و verify جدول queue، stock operation و health snapshot جلو می‌برد؛ در failure نسخه قبلی باقی می‌ماند تا retry شود.

**نتیجه:** migration candidate از نظر bounded بودن ایده‌ی خوبی دارد، اما implementation آن برای جایگزینی production کافی نیست.

---

## 4. مقایسه‌ی مدل محصول و WebPrd

### 4.1 نقاط قوت 2.0.0

- `Accounting_Data` یک read model ساده‌تر با قراردادهای صریح برای `Mojodi`، warehouse relation، `Sell1..Sell8`، discount و unit data ارائه می‌کند.
- تفکیک `simple`، `variable`، `variation`، `multi_unit_variable` و `composite` از نظر مفهومی مناسب است.
- candidate نیز از map‌کردن `Sell1..Sell8` به role بر اساس position پرهیز می‌کند.

### 4.2 regression مهم composite/formula

`Accounting_Data::accounting_nature()` در candidate فقط `TolidFormulaGuid` و `Kind == 2` را بررسی می‌کند. fixture فعلی پروژه برای composite شامل این داده‌هاست:

- `Composite = true`
- `TolidFormula = formula-1`
- `Kind = 7`
- `ProductionCapacity = 40`

بنابراین نسخه candidate با همین fixture، composite و formula را از دست می‌دهد.

در current، commit `2c53b2b` این gap را اصلاح کرده است:

- `TolidFormulaGuid` و `TolidFormula` به‌عنوان alias خوانده می‌شوند.
- `Composite` به‌صورت boolean مستقل نگه داشته می‌شود.
- assertion رسمی برای `production()['composite']` اضافه شده است.
- accounting kind و health flags (`composite`, `production_formula`, `production_capacity`) مستقل از WooCommerce type و unit mode ثبت می‌شوند.

### 4.3 health status

candidate عمدتاً `healthy` و `attention` دارد و ambiguity را flag می‌کند؛ قرارداد فعلی نیاز دارد حالت‌های unresolved/unknown واقعاً از healthy جدا باشند. همچنین health row candidate source `Mojodi`، تعداد relationهای warehouse و scope انتخاب‌شده را به‌صورت کافی ذخیره نمی‌کند.

current در snapshot خود ستون‌های source/warehouse، `inventory_state`، `unit_mode`، `sync_state`، `health_status` و health flags مستقل دارد و برای `unknown`/`unresolved` مسیر صریح‌تری دارد.

**قابل انتقال از candidate:** کارت‌ها و UX عملیاتی health dashboard.  
**غیرقابل انتقال مستقیم:** جدول و enumهای candidate بدون mapping و migration دوطرفه.

---

## 5. موجودی، warehouse scope و multi-unit

### 5.1 warehouse scope — blocker نسخه 2.0.0

در candidate، `Accounting_Data::configured_warehouse_guids()` در نبود تنظیمات آرایه‌ی خالی برمی‌گرداند. `inventory_total()` فقط وقتی filter اعمال می‌کند که آرایه‌ی allowed غیرخالی باشد؛ پس آرایه‌ی خالی عملاً به معنی aggregate کردن همه‌ی relationهاست.

این با قرارداد فعلی سازگار نیست: وقتی scope ناشناخته است، مجموع باید `unknown/unresolved` بماند، نه اینکه به «همه‌ی انبارها» تبدیل شود.

در current، `Inventory_Scope::configuration()` حالت‌های `all`، `configured` و `unknown` را جدا می‌کند و `configured` بدون شناسه را به `unknown` تبدیل می‌کند.

### 5.2 parent availability

candidate برای parent variable از `is_in_stock()` استفاده می‌کند. current در مسیر hardened علاوه بر published بودن child، `is_purchasable()` را نیز در availability aggregate لحاظ می‌کند؛ این جلوی نمایش parent قابل انتخاب ولی غیرقابل خرید را بهتر می‌گیرد.

### 5.3 stock concurrency

candidate در `reduce_base_stock_on_order()` و restore:

- مقدار base stock را از post meta می‌خواند.
- با `max(0, base - reduction)` مقدار جدید می‌سازد.
- post meta را update می‌کند.
- برای order/refund یک operation table و conditional SQL transaction ندارد.

دو worker می‌توانند یک مقدار قدیمی را بخوانند و یکی از کاهش‌ها overwrite شود؛ `max(0, ...)` نیز oversell را پنهان می‌کند. restore تکراری و refund idempotency هم primitive قطعی ندارد.

current مسیر سخت‌گیرانه‌تری دارد:

- جدول `novin_commerce_stock_ops` با `operation_key`.
- transaction و `INSERT IGNORE` برای claim عملیات.
- `UPDATE ... WHERE stock >= delta` برای جلوگیری از oversell.
- مسیر جدا برای refund و idempotency key.

این بخش current هنوز باید روی InnoDB و دو worker runtime تست شود، اما candidate نمی‌تواند بدون بازنویسی این قسمت جایگزین شود.

---

## 6. قیمت، role ownership و discount

### 6.1 نقاط قوت candidate

- role فقط از `PriceRoleList` صریح و نام‌دار resolve می‌شود.
- `Sell1..Sell8` به‌صورت accounting level باقی می‌مانند.
- تاریخ discount و حالت expired/invalid در مدل candidate وجود دارد.

### 6.2 regressionهای ownership

در `class-wcpbr-accounting-sync.php` candidate، وقتی role در payload حسابداری وجود دارد، مقدار regular role price مستقیماً update می‌شود. guard کافی برای origin دستی موجود قبل از overwrite وجود ندارد؛ در نتیجه یک payload حسابداری می‌تواند قیمت دستی را replace کند.

candidate همچنین origin را عمدتاً در metaهای role-specific مثل `_novin_regular_origin_{role}` نگه می‌دارد و مدل `inherited` فعلی را کامل ندارد. مسیر sale نیز بیشتر role-price metaها را مدیریت می‌کند و قرارداد عمومی WooCommerce sale price را به سخت‌گیری current پوشش نمی‌دهد.

current:

- originهای `accounting`, `manual`, `inherited`, `legacy`, `unknown` را normalize می‌کند.
- manual/unknown را از overwrite حسابداری محافظت می‌کند.
- variation بدون قیمت مستقل را به‌صورت `inherited` علامت‌گذاری می‌کند.
- sale استاندارد WooCommerce را با `_novin_commerce_sale_origin` مدیریت می‌کند.
- discount منقضی فقط sale حسابداری را حذف می‌کند و sale دستی را نگه می‌دارد.

### 6.3 PriceRoleList compatibility

candidate `role_price_rows()` فقط وقتی مقدار `PriceRoleList` از قبل array باشد آن را می‌خواند؛ JSON string و PHP serialized legacy را پوشش نمی‌دهد.

current parser برای array، JSON و serialized legacy decode محدود و بدون کلاس دارد و این مسیر در تست compatibility نیز assertion شده است.

---

## 7. currency و واحد پول

candidate سیاست روشنی دارد: تبدیل خودکار Rial/Toman را حذف کرده و قیمت raw را عبور می‌دهد. این از نظر جلوگیری از double conversion جذاب است.

current برای نصب‌های موجود `Currency_Conversion` را به‌عنوان یک boundary واحد نگه داشته و تنظیم legacy را حفظ می‌کند. این backward compatibility بهتری دارد، اما باید در UI و QA صریحاً مشخص شود که conversion فقط یک‌بار و در boundary مشخص اجرا می‌شود.

**تصمیم لازم قبل از release major:**

- اگر conversion باید حذف شود، باید migration و communication رسمی ارائه شود و هیچ مبلغ قبلی حدسی تغییر نکند.
- اگر backward compatibility اولویت دارد، current boundary حفظ شود و regression test برای cart، checkout، admin و role price اضافه شود.

حذف option در activation candidate بدون plan انتقال، به‌تنهایی migration امن محسوب نمی‌شود.

---

## 8. امنیت، OTP و provider

### 8.1 secret encryption

candidate `Secret_Crypt`:

- در نبود `AUTH_KEY/AUTH_SALT` از `get_site_url() . DB_NAME` به‌عنوان fallback استفاده می‌کند؛ این secret مستقل و غیرقابل‌پیش‌بینی نیست.
- در نبود OpenSSL یا خطای encryption مقدار plaintext را برمی‌گرداند.
- از AES-CBC بدون authentication tag استفاده می‌کند.

current:

- secret را فقط از `NOVIN_COMMERCE_ENCRYPTION_KEY` در `wp-config.php` یا environment می‌گیرد.
- در نبود key/OpenSSL fail-closed می‌کند.
- برای مقدارهای جدید AES-256-GCM با tag استفاده می‌کند.
- legacy مقدار را فقط برای migration در memory می‌خواند و آن را دوباره plaintext ذخیره نمی‌کند.

### 8.2 OTP

candidate جدول OTP را با ستون `code` نگه می‌دارد و خود کد را plaintext ذخیره می‌کند. rate limit تلفنی نیز با `SELECT COUNT` قبل از insert انجام می‌شود و در درخواست‌های هم‌زمان race دارد.

current:

- `code_hash` را ذخیره می‌کند و `code` جدید را خالی می‌گذارد.
- rate limit تلفن و IP را با atomic bucket در جدول اختصاصی انجام می‌دهد.
- در نبود schema rate table fail-closed می‌کند.
- migration مرحله‌ای و verify‌شده برای OTP دارد.

### 8.3 NPSMS

هر دو نسخه URL encoding و redaction را بهبود داده‌اند، اما current سخت‌گیرانه‌تر است:

- body خام provider را در log برنمی‌گرداند.
- خطای transport را با جزئیات حساس نمایش نمی‌دهد.
- bare numeric response را به‌تنهایی success فرض نمی‌کند و marker موفقیت می‌خواهد.

**نتیجه امنیتی:** Digits و `Secret_Crypt` candidate نباید به‌صورت مستقیم port شوند؛ این بخش P0 است.

---

## 9. dashboard، performance و uninstall

### 9.1 dashboard

candidate از نظر UX برنده است:

- health/action cardهای قابل اقدام.
- نمایش stale، GUID mismatch، inventory unknown، multi-unit ambiguity، queue و پوشش index.
- متن و ساختار release/QA شفاف‌تر.

این مزیت باید حفظ شود، اما روی read model hardened current سوار شود و statusهای unknown/critical را از دست ندهد.

### 9.2 performance و side effects

هر دو نسخه ادعا می‌کنند sync در render frontend انجام نمی‌شود و backfill bounded است. candidate در bootstrap عمومی هنوز `maybeUpgrade()` را روی هر request صدا می‌زند؛ این با هدف side-effect-free بودن read path ناسازگار است.

current نیز نیاز به query-plan و catalog بزرگ runtime دارد؛ static bounded بودن به‌تنهایی performance certification نیست.

### 9.3 uninstall

candidate cleanup جدول `health_items` و metadata جدید خودش را انجام می‌دهد. در بازبینی current یک gap باقی‌مانده پیدا شد: `uninstall.php` فعلی هنوز باید جدول‌های hardened یعنی `novin_commerce_product_health` و `novin_commerce_stock_ops`، hook migration `novin_commerce_run_migration` و metadataهای جدید مثل origin/snapshot را صریحاً پاک‌سازی یا تصمیم‌گیری کند.

این موضوع مستقل از blockerهای candidate است و باید قبل از production baseline اصلاح و در multisite تست شود.

---

## 10. ماتریس «حفظ / انتقال / رد»

| قابلیت یا ایده | تصمیم |
|---|---|
| Dashboard عملیاتی و action-oriented نسخه 2.0.0 | **انتقال** به read model current، پس از mapping و تست |
| bounded migration chunks | **حفظ ایده، بازنویسی lifecycle و verify** |
| تفکیک Mojodi از warehouse Amount | **حفظ**؛ در هر دو لازم است |
| explicit warehouse scope | **حفظ با قرارداد fail-closed current** |
| explicit `PriceRoleList` mapping | **حفظ** |
| JSON/serialized legacy compatibility | **از current حفظ شود** |
| origin manual/accounting/inherited/legacy | **از current حفظ شود** |
| standard Woo sale ownership | **از current حفظ شود** |
| explicit unit attribute mapping | **از candidate منتقل و با Unit_Engine current یکپارچه شود** |
| composite/formula/capacity nature | **از current hardened حفظ شود**؛ candidate parser کافی نیست |
| atomic multi-unit stock/refund | **از current حفظ شود**؛ candidate code رد شود |
| namespace `Novinwp` و branding `npwp.ir` | **رد** مگر با تصمیم تجاری و compatibility bridge مستقل |
| حذف فوری currency setting | **رد در upgrade مستقیم**؛ فقط با migration major مستند |
| candidate Secret_Crypt و plaintext OTP | **رد کامل** |
| candidate `Health_Snapshot` table به‌عنوان جایگزین مستقیم | **رد**؛ نیازمند migration/dual-read است |
| NPSMS response encoding/redaction | **حفظ به شکل سخت‌گیرانه‌تر current** |

---

## 11. برنامه‌ی hybrid پیشنهادی

### فاز A — پایه‌ی release

1. هسته‌ی namespace/Composer و bootstrap فعلی 1.20.0 حفظ شود.
2. parser مرکزی current و alias/composite fix در commit `2c53b2b` حفظ شود.
3. Activator و verify schema current پایه‌ی migration باشد.
4. uninstall current برای جدول‌ها، optionها، hookها و metadataهای جدید تکمیل شود.
5. currency policy به‌صورت صریح انتخاب و تست شود؛ هیچ price تاریخی حدسی تغییر نکند.

### فاز B — انتقال قابلیت‌های خوب 2.0.0

1. کارت‌ها و layout داشبورد candidate روی `Product_Health_Snapshot::summary()` و `list_rows()` پیاده شود.
2. اگر abstraction شبیه `Accounting_Data` لازم است، به‌صورت adapter روی `WebPrd_Parser` ساخته شود، نه با دو parser مستقل.
3. explicit unit mapping candidate به `Unit_Engine` current متصل شود.
4. statusهای `healthy/attention/critical/unknown` و source/warehouse fields حفظ شوند.
5. migration UI فقط admin/capability/nonce و cron داشته باشد؛ هر chunk بعد از verify cursor را جلو ببرد.

### فاز C — gate عملیاتی

1. staging clone با backup و rollback.
2. تست migration از نسخه 1.19/1.20 با DDL failure مصنوعی و retry.
3. تست warehouse در سه حالت `unknown`, `configured`, `all`.
4. تست fixtureهای NP567، Saffron، iPhone، Shirt، ExpiredDiscount، MissingSchedule، EightPriceLevels و Composite.
5. تست دو worker order، duplicate hook، partial/full refund و dead worker.
6. تست ownership قیمت دستی، inherited، legacy و accounting.
7. تست OTP: هیچ code plaintext، rate-limit atomic، missing key fail-closed، provider failure.
8. تست PHP 8.3 و 8.4، WordPress/WooCommerce، HPOS روشن و خاموش، multisite و uninstall.

---

## 12. معیار صدور release نهایی

تا زمانی که موارد زیر log و artifact شوند، عبارت production-ready استفاده نشود:

- [ ] `php -l`, PHPCS, PHPStan و PHPUnit روی PHP 8.3 و 8.4.
- [ ] WordPress/WooCommerce runtime با HPOS on/off.
- [ ] schema/migration verify واقعی و retry بعد از failure.
- [ ] تمام fixtureهای مدل محصول و composite.
- [ ] warehouse scope unknown/configured/all.
- [ ] atomic stock concurrency و refund idempotency.
- [ ] price/discount ownership و preservation of manual values.
- [ ] currency boundary و cart/checkout regression.
- [ ] OTP hash-only، rate-limit atomic و secret fail-closed.
- [ ] provider response/log redaction.
- [ ] uninstall و multisite cleanup.
- [ ] scan نهایی namespace، secret، URL/branding و محتوای zip.

**نتیجه نهایی:** 2.0.0 از نظر UX و جهت معماری الهام‌بخش‌تر است، اما برای release مستقیم blockerهای P0 دارد. 1.20.0-hardened مبنای امن‌تر برای hybrid است؛ پس از بستن QA runtime، migration و uninstall می‌تواند پایه‌ی نسخه‌ی نهایی باشد.
