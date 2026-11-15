# Mobile API Changes — Client App

Changes affecting the **client** mobile app. The admin-side features
(payouts, settings, review inbox) do not apply — mobile only calls
`/client/*` and `/public/*`.

## 1. Subscribe & book a consultant (full endpoint reference)

These endpoints already exist in the API and are now in the Postman
collection — documented here so the mobile team has the complete flow.

### 1.1 Browse packages (no auth)

- `GET /public/packages` — active packages list.
- `GET /public/packages/{slug}` — package detail (limits, `price`,
  `price_formatted`).

### 1.2 Buy / book a consultation (auth: `Bearer` client token)

```text
GET  /public/consultants                 → pick a consultant
GET  /public/consultants/{id}/available-dates
GET  /public/consultants/{id}/slots?date=Y-m-d   → pick a slot
GET  /client/locations                   → pick the company location
POST /client/bookings/quote              → price preview
POST /client/bookings                    → pay & confirm
```

`POST /client/bookings/quote` + `POST /client/bookings` body:

```json
{
  "package_id": 1,
  "consultant_id": 5,
  "date": "2026-11-10",
  "time": "14:00",
  "client_location_id": 3,
  "payment_method_id": 7,        // saved card — or omit and send card_token
  "card_token": "tok_...",        // from Moyasar JS/SDK tokenization
  "save_card": true,
  "client_notes": "…"
}
```

- Quote response includes `requires_payment`: **false** when an active
  subscription covers the booking — then just omit the payment fields on
  `POST /client/bookings`.
- Response is a `pending_payment` booking → run the Moyasar payment →
  `POST /client/payments/{payment}/verify` → booking becomes `pending`
  (confirmed) with a Meet link at `booking.meeting.url`.
- See `MOYASAR_MOBILE_GUIDE.md` for the card-tokenization flow.

### 1.3 Subscriptions (owned packages)

- `GET /client/subscriptions` — all subscriptions.
- `GET /client/subscriptions/active` — currently usable ones
  (`consultations_remaining`, `is_unlimited`, `ends_at`).

### 1.4 Book inside a purchased package (no payment)

```text
POST /client/subscriptions/{subscription}/bookings
```

```json
{ "consultant_id": 5, "date": "2026-11-10", "time": "14:00",
  "client_location_id": 3, "client_notes": "…" }
```

Consumes one consultation from the quota — **no payment step at all**.
Prefer this for the "My packages → book" shortcut. Errors: `404` foreign
subscription · `422 SUBSCRIPTION_INACTIVE` expired/cancelled ·
`422 SUBSCRIPTION_EXHAUSTED` quota used up (offer to buy again via §1.2) ·
`409 SLOT_NOT_AVAILABLE`.

### 1.5 Existing bookings

- `GET /client/bookings` — list (upcoming/past via `filter`).
- `GET /client/bookings/{id}` — detail incl. `meeting.url`,
  `payment_status`, `report_status`, cancel state.
- `POST /client/bookings/{id}/cancel` — `{ "reason": "…" }`; allowed ≥ 24 h
  before start, quota is returned to the subscription.

### 1.6 Book a consultant directly — no package (new)

The client can now book a consultant **without buying a package**. Omit
`package_id`; the price is the consultant's own `consultation_price`.

```text
POST /client/bookings/quote   { "consultant_id": 5, "date"?, "time"? }
POST /client/bookings         { "consultant_id": 5, "date", "time",
                                "client_location_id", "card_token" | "payment_method_id" }
```

- Quote response: `package: null`, `subscription: null`,
  `requires_payment: true` (always), `amount` = consultant price (halalas),
  `currency: "SAR"`, `slot_available` when date+time are sent.
- Booking response: same shape as a package booking; `booking.package` is
  `null` (handle it in the UI) and `booking.amount` is the consultant price.
- Payment is **always required** (card token or saved method) and follows
  the same 3DS flow: `payment.requires_action` → open `transaction_url` →
  `POST /client/payments/{id}/verify`.
- No subscription is created and no quota is consumed.
- Send `package_id` as before to keep the package/subscription flows.
- Errors: `422 CONSULTANT_PRICE_REQUIRED` (consultant has no price yet —
  hide/disable direct booking for him), `422 PAYMENT_METHOD_REQUIRED`,
  `409 SLOT_NOT_AVAILABLE`, `402 PAYMENT_FAILED`.
- `GET /public/consultants` and `GET /public/consultants/{id}` now return
  `consultation_price` (halalas, `null` if not set), `consultation_price_formatted`
  and `currency`. Hide "Book now" when the price is `null`. Bank details are
  never exposed publicly.

## 2. Reports are now approval-gated (behavior change)

An uploaded report is **invisible to the client until an admin approves it**.

Affected endpoints — they only expose `approval_status = approved` reports,
everything else looks like **404**:

- `GET /client/reports` — pending/rejected/returned reports are excluded
  from the list (they simply don't appear; no error).
- `GET /client/reports/{id}` — 404 for non-approved reports.
- `GET /client/reports/{id}/download` — 404 for non-approved reports.
- `GET /public/reports/{id}/signed-download` — 404 for non-approved
  reports (signed links from older, unapproved emails stop working).

**Client impact:** if the app shows a "report pending" state, keep using the
booking's `report_status` (`pending` = consultant hasn't uploaded,
`uploaded` = submitted but possibly still in review). There is no client-side
way to distinguish "uploaded, awaiting admin review" from "rejected" — and
that's intentional.

## 3. Report-ready email timing (behavior change)

`ReportReadyNotification` is now sent **when the admin approves** the
report, not when the consultant uploads it. The email content (dashboard
link + 7-day signed download link) is unchanged.

## 4. New meta enums

`GET /public/meta` → `data.enums` now includes two more lists — use them if
the app ever renders these statuses:

- `report_approval_statuses`: `pending_review | approved | rejected | returned`
- `payout_statuses`: `pending | released | failed`

## 5. Report file versions (FYI)

A report may be revised and re-uploaded. List/show/download always return
the **latest approved version** (`file` fields unchanged: `name`,
`mime_type`, `size`). No app change needed — just don't cache a file
indefinitely if the user re-downloads.

## Not changed

- Auth, envelope (`success`/`message`/`data`), pagination, error codes,
  `Accept-Language`, blob downloads via `Authorization` header.
- Moyasar payment flows (see `MOYASAR_MOBILE_GUIDE.md`).
- Booking wizard, subscriptions, profile, notifications endpoints.
