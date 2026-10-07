# Compatibility matrix — Novin Commerce 1.20.0

| Component | Target | Repository evidence | Runtime status |
|---|---|---|---|
| PHP | 8.3 / 8.4 | Composer and plugin header require PHP 8.3 | Not executed in this checkout; PHP binary is unavailable |
| WordPress | 6.1+ | Plugin header and existing API contracts | Needs staging verification |
| WooCommerce | Current supported release with HPOS | CRUD product/order paths and HPOS-safe order access retained | Needs staging verification |
| HPOS | Enabled and disabled | Order logic uses Woo CRUD; no new postmeta order source | Needs staging verification |
| MySQL/MariaDB | InnoDB, utf8mb4 | verified plugin-owned tables, unique queue identity, atomic stock-operation table | Needs migration run |
| Existing role-price meta | `_wcpbr_*`, `festiUserRolePrices` | Read/write compatibility retained; unknown legacy fields preserved | Static only |
| Existing API/REST routes | Existing routes | No route removal in this change set | Needs endpoint smoke tests |
| Currency conversion | Existing Rial/Toman service | `Currency_Conversion` remains the single conversion boundary | Needs cart/checkout runtime test |
| OTP/safety net | Existing module | Existing Digits and safety-net code retained | Needs provider and page runtime test |

## Product Data Model modes

- Product and Variation are separate snapshot rows.
- Variable Product Parent has no independent stock source; availability is derived from purchasable child Variations.
- Multi-Unit is active only with explicit Accounting definitions, valid conversion and unambiguous variation mapping.
- Unit modes are `none`, `multi_unit` and `unresolved`.
- `V2Qt` and `V3Qt` are never combined or inferred from position.

## Not certified yet

No claim is made here for a live provider, database engine, PHP 8.3/8.4 execution, WooCommerce version, theme, cache plugin or third-party role-price plugin until the matrix is run and attached to `QA-REPORT.md`.
