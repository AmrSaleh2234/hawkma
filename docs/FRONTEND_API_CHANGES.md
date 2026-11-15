# Frontend API Changes — Consultant Payouts & Report Review

This document summarizes the API changes introduced with the consultant
payments and report-review feature. Base URLs, envelope, auth and error
handling are unchanged — see `FRONTEND_API_GUIDE.md`.

## New features

1. **Consultant pricing & bank details** — payout-related financial fields
   on the consultant profile.
2. **Platform settings** — global platform fee % and payout automation
   toggle.
3. **Payouts** — one payout per completed booking; manual release by
   default, gateway-backed automation behind a setting.
4. **Report review workflow** — uploaded reports stay invisible to the
   client until an admin approves them. Full version + decision history.

---

## 1. Consultant financial fields

`GET /admin/consultants/{id}` (CON-03) and `GET /admin/profile` now include:

```json
{
  "consultation_price": 100000,
  "platform_fee_percent": null,
  "iban": "SA0380000000608010167519",
  "bank_name": "Al Rajhi",
  "account_name": "Expert Person"
}
```

- `consultation_price` — halalas; the gross amount used to compute the
  payout when a booking completes. **A consultant without a price gets no
  payout.**
- `platform_fee_percent` — optional per-consultant override (null = global
  `platform_fee_percent` setting applies).
- `iban`, `bank_name`, `account_name` — bank details shown to admins on the
  payout screen for manual transfers.

Editable via `PUT /admin/consultants/{id}` (CON-04, admin) and
`PUT /admin/profile` (ADM-PRF-02, the consultant himself — `platform_fee_percent`
is admin-only, ignored for consultants).

## 2. Settings (new endpoints)

| Endpoint | Permission |
|---|---|
| `GET /admin/settings` (SET-01) | `view-settings` |
| `PUT /admin/settings` (SET-02) | `update-settings` |

Keys (all optional on update):

| Key | Type | Default |
|---|---|---|
| `platform_fee_percent` | numeric 0–100 | `15` |
| `payout_auto_transfer` | boolean | `false` |

`payout_auto_transfer=false` (default) means PO-03 only **records** a manual
transfer. `true` routes the release through the configured payout gateway
(`config payments.payout_driver`, default `manual`); a gateway failure marks
the payout `failed`.

## 3. Payouts (new endpoints)

| Endpoint | Permission |
|---|---|
| `GET /admin/payouts?status=&consultant_id=&date_from=&date_to=` (PO-01) | `view-payouts` |
| `GET /admin/payouts/stats` (PO-01b) | `view-payouts` |
| `GET /admin/payouts/{id}` (PO-02) | `view-payouts` |
| `POST /admin/payouts/{id}/release` (PO-03) | `release-payouts` |

- One payout is created automatically when a booking completes:
  `net_amount = consultation_price − consultation_price × fee_percent`.
  Amounts and the applied fee are **snapshotted** on the payout — later
  price/fee changes don't affect existing payouts.
- `status`: `pending | released | failed` (labels in PUB-08 `payout_statuses`).
- Consultants have `view-payouts` by default and only see **their own**
  payouts (`consultant_id` filter is ignored for them, 404 on foreign IDs).
- Release body: `{ "transfer_reference"?, "note"? }`. Errors:
  `422 PAYOUT_ALREADY_RELEASED`, `422 PAYOUT_NOT_RELEASABLE`.

## 4. Report review workflow

### Lifecycle

```
upload ──► pending_review ──approve──► approved  (client notified + visible)
                │
                ├──reject───► rejected  (consultant notified; booking → pending)
                └──return───► returned  (consultant notified; booking → pending)
                                      └ re-upload → pending_review again
```

### Changed endpoints

- `POST /admin/bookings/{id}/report` (RPT-03):
  - **Removed** `notify_client` — no client notification on upload.
  - **Added** `note` (optional) — stored on the submission history entry.
  - Response now has `approval_status: "pending_review"`.
  - Re-uploading **keeps all file versions** (was: replaced the file) and
    resets `approval_status` to `pending_review`.
- `GET /admin/reports` (RPT-01): new `approval_status` filter.
- `GET /admin/reports/{id}` (RPT-02): adds `approval_status`, `review_note`,
  `reviewed_by`, `reviewed_at`; `file` is the **current** version.
- `POST /admin/reports/{id}/notify-client` (RPT-06): now returns
  `422 REPORT_REVIEW_NOT_ALLOWED` unless the report is `approved`.

### New endpoints

| Endpoint | Permission | Notes |
|---|---|---|
| `GET /admin/reports/inbox` (RPT-01c) | `approve-reports` | pending_review only, paginated |
| `POST /admin/reports/{id}/approve` (RPT-04a) | `approve-reports`, admin only | `{ "note"? }` — notifies client + consultant |
| `POST /admin/reports/{id}/reject` (RPT-04b) | `approve-reports`, admin only | `{ "note"? }` — notifies consultant, booking → pending |
| `POST /admin/reports/{id}/return` (RPT-04c) | `approve-reports`, admin only | `{ "note"? }` — same as reject |
| `GET /admin/reports/{id}/history` (RPT-02b) | `view-reports` + report's consultant | every submission/decision w/ its file version |
| `GET /admin/report-reviews/{id}/download` (RPT-02c) | `download-reports` | the file of one history entry |

Review a non-pending report → `422 REPORT_REVIEW_NOT_ALLOWED`.

### Client gating

`GET /client/reports*`, `GET /client/reports/{id}/download` and
`GET /public/reports/{id}/signed-download` only expose `approved` reports —
everything else is a **404**. The `ReportReadyNotification` email is sent
**only on approval** (or via RPT-06 afterwards).

### History entries (`GET /admin/reports/{id}/history`)

```json
{
  "id": 9,
  "status": "approved",
  "status_label": "معتمد",
  "note": "Approved for release.",
  "actor": { "id": 1, "name": "Admin" },
  "file": {
    "id": 12, "name": "v2.pdf", "mime_type": "application/pdf", "size": 51200,
    "download_url": "…/admin/report-reviews/9/download"
  },
  "created_at": "…"
}
```

`status` is `pending_review` for submissions and `approved|rejected|returned`
for decisions.

## 4b. Direct consultant booking (no package)

`POST /client/bookings/quote` and `POST /client/bookings` accept a missing
`package_id`: the booking is priced at the consultant's `consultation_price`
(always paid, no subscription). The quote needs `package_id` **or**
`consultant_id`.

### Public consultant price (new fields)

`GET /public/consultants` and `GET /public/consultants/{id}` now include:

```json
{
  "consultation_price": 50000,
  "consultation_price_formatted": "...",
  "currency": "SAR"
}
```

- Amounts are in halalas. Both price fields are `null` when the consultant
  has no price: hide the "Book now" button in that case.
- Bank details (`iban`, `bank_name`, `account_name`) are never public.
- Use these for the home page consultants section, the consultant cards and
  the consultant profile page.

### Direct booking flow (`/book/consultant/[id]`)

```text
GET  /public/consultants/{id}                 → profile + price
GET  /public/consultants/{id}/available-dates → month calendar
GET  /public/consultants/{id}/slots?date=     → pick a slot
GET  /client/locations                        → pick the location
POST /client/bookings/quote   { consultant_id, date?, time? }
POST /client/bookings         { consultant_id, date, time, client_location_id,
                                card_token | payment_method_id, save_card? }
POST /client/payments/{id}/verify             → only after 3DS
```

- **Quote response:** `package: null`, `subscription: null`,
  `requires_payment: true` (always), `amount`, `currency: "SAR"`,
  `slot_available` (only when date+time are sent).
- **Booking response:** unchanged shape; `booking.package` is `null`,
  `booking.amount` is the consultant price. Payment is always required: same
  `paid` / `initiated` (3DS, redirect to `transaction_url`) handling as a
  package booking.
- **Wizard changes:** skip the package and consultant steps, never send
  `package_id`, and make the summary/confirm steps null-safe for `package`.
- **Package flows are unchanged:** send `package_id` to buy a package or use
  an active subscription.

### Admin side

- Booking resources: `package` is now **nullable**; `package_id`,
  `package_snapshot` and `client_subscription_id` are `null` for direct
  bookings. Render a "Direct booking" label instead of the package name.
- Completion creates the payout from `consultation_price` as usual.

### Errors

| Code | When |
|---|---|
| `422 CONSULTANT_PRICE_REQUIRED` | the consultant has no price (quote or booking) |
| `422 PAYMENT_METHOD_REQUIRED` | no card token or saved method |
| `422 VALIDATION_ERROR` | quote sent with neither `package_id` nor `consultant_id` |
| `409 SLOT_NOT_AVAILABLE` | slot taken |
| `402 PAYMENT_FAILED` | card declined |

## 5. Meta + labels

`GET /public/meta` (PUB-08) `data.enums` now includes:

- `report_approval_statuses`: `pending_review | approved | rejected | returned`
- `payout_statuses`: `pending | released | failed`

`report.approval_status` and `payout.status` responses carry `*_label` in the
request locale like every other enum.

## 6. New permissions + error codes

- Permissions: `approve-reports`, `view-payouts`, `release-payouts`,
  `view-settings`, `update-settings` (see `GET /admin/permissions`).
  Consultants get `view-payouts` by default.
- Error codes: `REPORT_REVIEW_NOT_ALLOWED`, `PAYOUT_ALREADY_RELEASED`,
  `PAYOUT_NOT_RELEASABLE`, `CONSULTANT_PRICE_MISSING` — standard envelope,
  localized `message` in `ar`/`en`.
