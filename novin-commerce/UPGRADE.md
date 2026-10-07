# Novin Commerce 1.20.0 upgrade notes

## Scope

1.20.0 adds a central WebPrd Domain Model and the `novin_commerce_product_health` read model. Product, Variable Product Parent and Variation are represented separately. The existing meta keys, role-price keys, queue routes and Festi-compatible representation remain readable.

## Before upgrading

1. Take a database backup and a copy of `wp-content/uploads`.
2. Verify PHP 8.3 or 8.4, WordPress 6.1+, WooCommerce and InnoDB support.
3. Export the existing sync queue and role-price configuration.
4. Confirm that no API password, SMS password or token is included in a debug export.
5. Test the plugin on staging with representative Simple, Variable, Variation, HPOS and order/refund data.

## Schema migration

The activation/admin migration creates or verifies:

- `novin_commerce_product_health`;
- the existing sync and stock-operation tables;
- queue lifecycle columns and the unique `(item_id, item_type)` identity.

Missing snapshot columns are added individually. Existing rows are not dropped or overwritten. The schema option is advanced only after table/column/index verification succeeds. A failed `ALTER` leaves the prior schema version in place and should be retried after the database issue is fixed.

## Warehouse scope

Go to **Novin Commerce → Settings → General** and select a warehouse scope:

- **Unknown and fail-safe**: no warehouse aggregate or Multi-Unit stock calculation;
- **All valid warehouses**: aggregate relations with a valid warehouse GUID;
- **Selected IDs**: aggregate only the comma-separated warehouse GUIDs.

`Mojodi` is stored as `source_mojodi`; it is never silently used as `warehouse_stock`.

## Backfill

After migration, open the dashboard and explicitly click **Backfill پنجاه‌تایی**. Each request processes at most 50 items and reports the next offset. The dashboard page view itself never writes, queues or synchronizes.

## Role prices and discounts

- `Sell1`…`Sell8` remain accounting levels.
- `PriceRoleList` is the only accounting-to-WordPress role mapping.
- Role-price origin metadata uses `accounting`, `manual`, `inherited`, `legacy` or `unknown`.
- Accounting cleanup can remove only accounting-owned prices/sales; manual sales are preserved.
- Accounting discounts require a valid percentage, valid sale price and complete schedule.

## Rollback

Disable the plugin only after stopping active sync workers. Restore the database backup if a verified migration cannot be completed. Do not delete the new snapshot table as a first response: the old product/meta data remains the source of truth and the snapshot can be rebuilt after the issue is fixed.

## Current release decision

This repository currently reports **NOT PRODUCTION READY** until the runtime WordPress/WooCommerce matrix, migration verification, official fixtures and concurrency tests pass on staging.
