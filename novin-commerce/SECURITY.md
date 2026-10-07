# Security notes — Novin Commerce 1.20.0

## Controls retained or added

- API, SMS and OTP secrets use the shared encrypted setting path. Masked values are never rendered back as plaintext, logged or returned through REST/AJAX.
- Migration failures do not advance the schema option and do not overwrite the original setting/data.
- Error notices are generic; provider responses, SQL, PHP stack traces and credentials are not shown to end users.
- Product health data is a read model. Dashboard requests use bounded queries and do not perform sync, queue insertion, stock writes or snapshot rebuilds.
- Backfill is an explicit capability- and nonce-protected `admin-post` action with a maximum batch of 100.
- Queue identity is `(item_id, item_type)` and stock operations use a plugin-owned idempotency table plus a transaction and conditional update.
- Warehouse aggregation requires configured scope and valid `Amount` plus warehouse GUID. Unknown scope fails closed.
- Accounting role-price deletion checks origin metadata. Manual role prices and Manual Sale values are not removed by accounting cleanup.
- WebPrd JSON is decoded only at `WebPrd_Parser`; admin/list/sync/unit modules consume the domain parser.
- Dashboard output is escaped and marked read-only; no secret field is included in health snapshots.

## Sensitive data policy

Do not add passwords, API keys, SMS credentials, bearer tokens, cookies or production WebPrd exports to fixtures, logs, snapshots, screenshots or Git. Fixtures in `tests/fixtures` contain synthetic values only.

## Security verification still required

A staging audit must exercise capability checks, nonces, REST/AJAX permission callbacks, HPOS order access, concurrent queue/stock requests, malformed WebPrd, oversized payloads, invalid dates, serialized legacy metadata and provider failure responses. Until then the release decision remains **NOT PRODUCTION READY**.
