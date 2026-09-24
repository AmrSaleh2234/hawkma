# GCMC Frontend API Guide

This guide is the contract between the GCMC backend and the two frontend apps:

- **Dashboard A** — admin/consultant dashboard (guard `admin`)
- **Dashboard B** — client dashboard (guard `client`)

It matches the real behaviour of the API. Every endpoint also exists in the
Postman collection `postman/GCMC-API.postman_collection.json` with a saved,
real response example — import it and look at the examples while reading this.

---

## 1. Basics

### 1.1 Base URLs

| Environment | Base URL |
|---|---|
| Local | `http://localhost:8000/api/v1` |
| Staging | `https://staging-api.gcmc.sa/api/v1` |

All URLs below are relative to the base URL.

### 1.2 Headers (send on every request)

```
Accept: application/json
Accept-Language: ar          # ar | en — controls message/label language (default ar)
Authorization: Bearer TOKEN  # after login
Content-Type: application/json   # for JSON bodies; NOT for FormData uploads
```

### 1.3 The response envelope

Every JSON response — success or error — has the same envelope.

**Success:**

```json
{
  "success": true,
  "message": "OK",
  "data": { }
}
```

**Validation error (422):**

```json
{
  "success": false,
  "message": "حقل البريد الإلكتروني مطلوب",
  "error_code": "VALIDATION_ERROR",
  "errors": { "email": ["حقل البريد الإلكتروني مطلوب"] }
}
```

Show `errors[field][0]` next to each form field; `message` is a safe fallback toast.

**Business error (usually 422 or 409):**

```json
{
  "success": false,
  "message": "هذا الموعد لم يعد متاحاً",
  "error_code": "SLOT_NOT_AVAILABLE",
  "errors": {}
}
```

Branch on `error_code` (stable), never on `message` (translated).

### 1.4 Error codes and suggested UI handling

| HTTP | `error_code` | Meaning | Suggested UI |
|---|---|---|---|
| 401 | `UNAUTHENTICATED` | No/expired/wrong-guard token | Clear token, redirect to login |
| 403 | `FORBIDDEN` | Missing permission / policy denied | "لا تملك صلاحية" toast; hide the action next time |
| 403 | `ACCOUNT_DISABLED` | `is_active = false` | Block login: "الحساب معطّل، تواصل مع الإدارة" |
| 404 | `NOT_FOUND` | Not found (or belongs to someone else) | 404 page |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method | Dev error |
| 422 | `VALIDATION_ERROR` | Form validation | Inline field errors from `errors` |
| 422 | `INVALID_CREDENTIALS` | Wrong email/password | "بيانات الدخول غير صحيحة" |
| 422 | `ROLE_PROTECTED` | Editing/deleting the `admin` role | Toast |
| 422 | `ROLE_HAS_USERS` | Deleting a role that still has users | Toast |
| 422 | `CANNOT_DELETE_SELF` | Deleting your own user | Toast |
| 422 | `LAST_ADMIN` | Removing the last active admin | Toast |
| 422 | `CONSULTANT_INACTIVE` | Booking an inactive consultant | Pick another consultant |
| 422 | `CONSULTANT_HAS_FUTURE_BOOKINGS` | Deleting a consultant with future bookings | Toast |
| 422 | `CLIENT_HAS_FUTURE_BOOKINGS` | Deleting a client with future bookings | Toast |
| 422 | `AVAILABILITY_OVERLAP` | Overlapping availability ranges | Highlight the range |
| 409 | `SLOT_NOT_AVAILABLE` | Slot taken between quote and submit | Reload slots, ask user to re-pick (see §5.7) |
| 422 | `PACKAGE_INACTIVE` | Booking an inactive package | Toast |
| 422 | `PACKAGE_HAS_SUBSCRIPTIONS` | Deleting a package with subscriptions | Toast |
| 422 | `SUBSCRIPTION_EXHAUSTED` | Quota ran out between quote and submit (concurrent bookings) | Re-quote; offer payment |
| 422 | `LOCATION_NOT_OWNED` | Location belongs to another client | Dev error |
| 422 | `PAYMENT_METHOD_REQUIRED` | No card given while payment is required | Highlight the payment step |
| 422 | `PAYMENT_METHOD_NOT_OWNED` | Card belongs to another client | Dev error |
| 402 | `PAYMENT_FAILED` | Card declined | Show `message`, offer another card |
| 422 | `PAYMENT_ALREADY_PROCESSED` | Verifying an already-final payment | Safe to ignore; refresh the booking |
| 503 | `PAYMENT_PENDING_CONFIRMATION` | Gateway unreachable during charge/verify — the payment state is unknown and is being reconciled via webhook | "Your payment is being confirmed"; poll the booking after ~30 s. **Do not** resubmit the charge blindly (a retry is safe — Moyasar dedupes via `given_id` — but the user may already be paid) |
| 422 | `BOOKING_INVALID_STATUS` | e.g. completing a cancelled booking | Refresh the booking |
| 422 | `BOOKING_NOT_STARTED` | Completing before `starts_at` | Toast |
| 422 | `BOOKING_CANCEL_WINDOW_PASSED` | Client cancelling < 24 h before start | "انتهت مهلة الإلغاء" |
| 422 | `REPORT_NOT_ALLOWED` | Report for a non-completed booking | Toast |
| 502 | `MEETING_CREATION_FAILED` | Google Meet creation failed | Retry button (BKG-05) |
| 429 | `TOO_MANY_REQUESTS` | Throttled | "حاول بعد قليل" |
| 500 | `SERVER_ERROR` | Anything else | Generic error page/toast |

### 1.5 Pagination, filtering, sorting

List endpoints return:

```json
{
  "success": true,
  "message": "OK",
  "data": [ /* items */ ],
  "meta":  { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 },
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." }
}
```

| Query param | Meaning |
|---|---|
| `page` | Page number |
| `per_page` | 1–100, default 15 |
| `search` | Free text (searchable fields are listed per endpoint below) |
| `sort` | Field name, `-` prefix = descending (`sort=-starts_at`) |
| filters | Per endpoint, e.g. `status=pending&date_from=2026-09-01&date_to=2026-09-30` |

### 1.6 Dates and times

- Timestamps are ISO 8601 with the Riyadh offset: `2026-09-23T09:00:00+03:00`.
- Bookings also carry `date` (`2026-09-23`) and `time` (`09:00`) for direct display.
- Send dates as `YYYY-MM-DD` and times as `HH:MM` (24 h).

### 1.7 Money

Money is **integer halalas** (1 SAR = 100 halalas). Every money field has a
formatted twin — never format it yourself:

```json
{ "price": 190000, "price_formatted": "1,900.00 SAR", "currency": "SAR" }
```

### 1.8 Languages and translatable fields

`Accept-Language: ar|en` (default `ar`) controls `message`, enum `*_label`
fields and the convenience `name`/`title` fields. Translatable models also
return the raw columns (`name_ar`, `name_en`, …) so a language switcher can
flip client-side without re-fetching.

---

## 2. Authentication

Both dashboards use **Bearer tokens** (Laravel Sanctum). There is no refresh
token: when a call returns 401, send the user to login again.

### 2.1 Dashboard A (admin + consultant)

```
POST /admin/auth/login    { "email", "password", "device_name": "web" }
→ data.token, data.token_type, data.user { id, name, email, type: "admin"|"consultant", roles[], permissions[], avatar_url }
```

- Store the token in `localStorage` (`admin_token`) and send it as
  `Authorization: Bearer …` on every call.
- `GET /admin/auth/me` → the same `user` object (call it on app boot to
  re-hydrate, and after login to get `permissions`).
- `POST /admin/auth/logout` → revokes the current token. Call it on logout,
  then clear storage.
- **401** → clear token, redirect to `/login`.
- **403 `ACCOUNT_DISABLED`** on login → show "الحساب معطّل".

### 2.2 Dashboard B (client)

```
POST /client/auth/register  { "name", "email", "phone": "05XXXXXXXX", "company_name", "password", "password_confirmation", "device_name": "web" } → 201 + data.token + data.user
POST /client/auth/login     { "email", "password", "device_name" } → data.token + data.user
GET  /client/auth/me        → data { client fields + active_subscriptions[], default_location, default_payment_method }
POST /client/auth/logout
```

An **admin email cannot log into Dashboard B** and vice versa — a token of the
other guard gets 401.

### 2.3 Forgot / reset password

```
POST /admin/auth/forgot-password   { "email" }   → always 200 (never leaks existence)
POST /admin/auth/reset-password    { "token", "email", "password", "password_confirmation" }
POST /client/auth/forgot-password  { "email" }
POST /client/auth/reset-password   { "token", "email", "password", "password_confirmation" }
```

The email links to the frontend:

```
{ADMIN_FRONTEND_URL}/reset-password?token=…&email=…     (Dashboard A page)
{CLIENT_FRONTEND_URL}/reset-password?token=…&email=…    (Dashboard B page)
```

Your reset page reads `token` + `email` from the query string and posts them
with the new password. A successful reset **revokes all old tokens** → route
to login. A bad/expired token returns `422 VALIDATION_ERROR` with the reason
under `errors.email` → show "الرابط منتهي، اطلب رابطاً جديداً".

---

## 3. Permissions in Dashboard A

After login, call `GET /admin/auth/me` and keep `user.permissions` (flat array
of strings) and `user.type` (`admin` | `consultant`) in the store.

- Hide **menu items** by the page permission.
- Hide/disable **buttons** by the action permission.
- The backend enforces everything anyway — hidden UI is for UX, not security.

### 3.1 Page → required permission

| Page | Permission |
|---|---|
| Dashboard home | `view-dashboard` |
| Roles & Permissions | `view-roles` (permissions list needs `view-permissions`) |
| Users | `view-users` |
| Consultants | `view-consultants` |
| Consultant details tabs: Availability / Time off | `view-availability` |
| Consultant details tabs: Clients | `view-clients` |
| Consultant details tabs: Bookings | `view-bookings` |
| Consultant details tabs: Pending reports / Reports | `view-reports` |
| Bookings | `view-bookings` |
| Reports | `view-reports` |
| Clients | `view-clients` |
| Packages | `view-packages` |
| Payments | `view-payments` |
| Profile | — (every logged-in user) |
| My availability (consultant) | `view-availability` |

### 3.2 Button/action → required permission

| Action | Permission |
|---|---|
| Create/edit/delete role | `create-roles` / `update-roles` / `delete-roles` |
| Create/edit/delete user | `create-users` / `update-users` / `delete-users` |
| Assign roles to a user | `assign-roles` |
| Activate/deactivate user or client | `update-users` / `update-clients` |
| Create/edit/delete consultant | `create-consultants` / `update-consultants` / `delete-consultants` |
| Edit availability, add/remove time off | `manage-availability` |
| Create/edit/delete package | `create-packages` / `update-packages` / `delete-packages` |
| Complete booking | `complete-bookings` |
| Cancel booking | `cancel-bookings` |
| Regenerate meeting link | `manage-meetings` |
| Mark booking refunded | `refund-payments` |
| Upload report / resend report email | `upload-reports` |
| Download report | `download-reports` |
| Delete report | `delete-reports` |

### 3.3 Admin vs consultant (`user.type`)

- `admin` with the `admin` role bypasses every check.
- A `consultant` sees **only his own data**: the bookings/clients/reports/
  payments lists are scoped server-side; another consultant's record returns
  **403** (policy) or **404**. The consultants list returns only himself.
- Consultant default permissions: `view-dashboard`, `view-bookings`,
  `complete-bookings`, `cancel-bookings`, `view-reports`, `upload-reports`,
  `download-reports`, `view-clients`, `view-availability`,
  `manage-availability`.
- The dashboard stats response for a consultant has the same shape but **no
  `consultants` and no `revenue` keys** — check for their presence.

---

## 4. Page-by-page guide

Endpoint IDs (e.g. `BKG-01`) match the Postman request names.

### 4.1 Dashboard A

#### Login / Forgot password
`POST /admin/auth/login` → store token; `GET /admin/auth/me`; forgot/reset per §2.3.

#### Dashboard home
`GET /admin/dashboard/stats` (DSH-01):

```json
{ "bookings": { "total", "pending", "completed", "cancelled", "today" },
  "reports": { "total", "pending" },
  "clients": { "total", "new_this_month" },
  "consultants": { "total", "active" },          // admins only
  "revenue": { "this_month", "this_month_formatted", "total", "total_formatted" },  // admins only
  "upcoming_bookings": [ /* Booking objects, max 5 */ ] }
```

`reports.pending` = completed bookings still awaiting a report → link the card
to the Reports page filtered by "pending".

#### Roles & Permissions
- List: `GET /admin/roles` (ACL-02) — includes `permissions_count`, `users_count`.
- Permissions picker: `GET /admin/permissions` (ACL-01) — grouped by group name.
- Create: `POST /admin/roles` `{ "name": "supervisor", "permissions": ["view-bookings", …] }` (ACL-03).
- Edit: `PUT /admin/roles/{id}` (ACL-05) — send `name` and/or `permissions`.
- Role users drawer: `GET /admin/roles/{id}/users` (ACL-07).
- Delete: `DELETE /admin/roles/{id}` (ACL-06) — handle `ROLE_PROTECTED`, `ROLE_IN_USE`.

#### Users
- List: `GET /admin/users?search=&type=admin|consultant&role=&is_active=&sort=` (USR-01).
- Create: `POST /admin/users` (USR-02) — fields: `name*`, `email*`,
  `password` (+`password_confirmation`, optional — without it the user gets a
  set-password email), `type` (`admin` default | `consultant`), `roles*` (array
  of role **names**), `is_active`, `avatar` (file).
- Show/edit: `GET|PUT /admin/users/{id}` (USR-03/04).
- Avatar: `POST /admin/users/{id}/avatar` (USR-08, FormData).
- Roles: `PUT /admin/users/{id}/roles` `{ "roles": ["admin"] }` (USR-06).
- Activate/deactivate: `PATCH /admin/users/{id}/status` `{ "is_active": false }` (USR-07) — revokes the user's tokens; `LAST_ADMIN` possible.
- Delete: `DELETE /admin/users/{id}` (USR-05) — `CANNOT_DELETE_SELF`, `LAST_ADMIN`.

#### Consultants
- List: `GET /admin/consultants?search=&is_active=&specialization=` (CON-01).
- Create: `POST /admin/consultants` (CON-02, **FormData** because of `photo`):
  `name*`, `email*`, `phone`, `title`, `specialization`, `bio`, `password`
  (optional), `is_active`, `photo` (jpg/png/webp ≤ 2 MB), optional
  `availability` JSON. The `consultant` role is assigned automatically.
- Details page tabs:
  - Profile: `GET /admin/consultants/{id}` (CON-03) · edit `PUT` (CON-04) ·
    photo `POST /admin/consultants/{id}/photo` (CON-07) · status
    `PATCH /admin/consultants/{id}/status` (CON-06) · delete `DELETE` (CON-05,
    `CONSULTANT_HAS_FUTURE_BOOKINGS`).
  - Availability: `GET /admin/consultants/{id}/availability` (CON-08) · replace
    `PUT` (CON-09) with
    `{ "days": [ { "day_of_week": 0, "ranges": [ { "start_time": "09:00", "end_time": "17:00" } ] } ] }`.
    `day_of_week`: **0 = Sunday … 6 = Saturday**. Days you omit are removed.
    Overlaps → `AVAILABILITY_OVERLAP`.
  - Time off: `GET|POST /admin/consultants/{id}/time-offs` (CON-10/11),
    `DELETE …/time-offs/{id}` (CON-12). `date*` (today or later); optional
    `start_time`+`end_time` pair for a partial day; `reason`.
  - Clients: `GET /admin/consultants/{id}/clients?search=` (CON-14).
  - Bookings: `GET /admin/consultants/{id}/bookings?status=` (CON-15) — the
    "Pending bookings" tab is `?status=pending`.
  - Pending reports: `GET /admin/consultants/{id}/pending-reports` (CON-16).
  - Reports: `GET /admin/consultants/{id}/reports` (CON-17).
  - Stats row: `GET /admin/consultants/{id}/stats` (CON-18).
  - Slot preview: `GET /admin/consultants/{id}/slots?date=` (CON-13).

#### My availability (consultant)
Same payloads as above against `GET|PUT /admin/my/availability` (MY-01/02),
`GET|POST /admin/my/time-offs`, `DELETE /admin/my/time-offs/{id}` (MY-03..05),
`GET /admin/my/slots?date=` (MY-06).

#### Bookings
- List: `GET /admin/bookings?status=&consultant_id=&client_id=&date_from=&date_to=&search=&sort=` (BKG-01).
  `status`: `pending_payment | pending | completed | cancelled`. `search`
  matches the reference and the client name. Consultants only see their own.
- Calendar view: `GET /admin/bookings/calendar?from=YYYY-MM-DD&to=YYYY-MM-DD`
  (BKG-06, max 62 days).
- Details: `GET /admin/bookings/{id}` (BKG-02) — includes `client`,
  `consultant`, `package`, `location_snapshot`, `meeting { provider, status,
  url, ... }`, `payments[]`, `report`.
- Complete: `POST /admin/bookings/{id}/complete` `{ "notes"? }` (BKG-03) —
  only after `starts_at` (`BOOKING_NOT_STARTED`) and while `pending`.
- Cancel: `POST /admin/bookings/{id}/cancel` `{ "reason"* }` (BKG-04) — admin
  can cancel any time; a paid booking becomes `refund_status=requested`.
- Regenerate Meet link: `POST /admin/bookings/{id}/meeting` (BKG-05) — show a
  spinner; `502 MEETING_CREATION_FAILED` means Google is down, offer retry.
- Mark refunded: `POST /admin/bookings/{id}/mark-refunded` (BKG-07) — only
  while `refund_status=requested`, after refunding in the Moyasar dashboard.
  Also **cancels the subscription the booking paid for** (the client got the
  money back), so the client's "My packages" page may change afterwards.
- Upload report from the booking: see Reports below.

#### Reports
- List: `GET /admin/reports?consultant_id=&client_id=&date_from=&date_to=&search=` (RPT-01).
- Show: `GET /admin/reports/{id}` (RPT-02) — `download_url` is guard-specific.
- Upload: `POST /admin/bookings/{booking}/report` (RPT-03, **FormData**):
  `title*`, `summary`, `file*` (pdf/doc/docx ≤ 20 MB), `notify_client`
  (default true → the client gets the email with the 7-day signed link).
  Uploading again **replaces** the file. Booking must be `completed`
  (`REPORT_NOT_ALLOWED`).
- Download: `GET /admin/reports/{id}/download` (RPT-04) — see §6.2.
- Resend email: `POST /admin/reports/{id}/notify-client` (RPT-06).
- Delete: `DELETE /admin/reports/{id}` (RPT-05) — the booking returns to
  "awaiting report".

#### Clients
- List: `GET /admin/clients?search=&is_active=&consultant_id=&has_active_subscription=` (ADM-CL-01) — includes `bookings_count`, `reports_count`, `active_subscription`.
- Show: `GET /admin/clients/{id}` (ADM-CL-02) — with `locations`, `subscriptions`, counts.
- Edit: `PUT /admin/clients/{id}` (ADM-CL-03): `name*`, `email*`, `phone*` (`05XXXXXXXX`), `company_name*`.
- Status: `PATCH /admin/clients/{id}/status` (ADM-CL-04) — revokes client tokens.
- Tabs: bookings `GET /admin/clients/{id}/bookings` (ADM-CL-06) · reports
  `GET /admin/clients/{id}/reports` (ADM-CL-07) · subscriptions
  `GET /admin/clients/{id}/subscriptions` (ADM-CL-08).
- Delete: `DELETE /admin/clients/{id}` (ADM-CL-05) — `CLIENT_HAS_FUTURE_BOOKINGS`.

#### Packages
- List: `GET /admin/packages?search=&is_active=` (PKG-01) — includes inactive.
- Create: `POST /admin/packages` (PKG-02): `slug*` (lowercase `alpha_dash`),
  `name_ar*`, `name_en*`, `description_ar/en`, `features` =
  `[{ "ar": "…", "en": "…" }]`, `price*` (halalas), `billing_period_days`
  (default 30), `consultations_limit` / `documents_limit` (**null = unlimited**),
  `is_featured`, `is_active`, `sort_order`.
- Show/edit: `GET|PUT /admin/packages/{id}` (PKG-03/04).
- Status: `PATCH /admin/packages/{id}/status` (PKG-06).
- Delete: `DELETE /admin/packages/{id}` (PKG-05) — soft delete.

#### Payments
- List: `GET /admin/payments?status=&client_id=&date_from=&date_to=` (PAY-01).
  `status`: `initiated | paid | failed | refunded`. `gateway_response` is never
  exposed.
- Show: `GET /admin/payments/{id}` (PAY-02) — payment + booking summary.

#### Profile (both user types)
`GET /admin/profile` (ADM-PRF-01) · `PUT /admin/profile` (ADM-PRF-02) · avatar
`POST|DELETE /admin/profile/avatar` (ADM-PRF-03/04) · password
`PUT /admin/profile/password` `{ "current_password", "password", "password_confirmation" }` (ADM-PRF-05).

### 4.2 Dashboard B

#### Register / Login / Forgot password
See §2.2/§2.3. After register/login store `client_token`.

#### Dashboard home
`GET /client/dashboard` (CLI-DSH-01):

```json
{ "next_booking": { /* Booking with meeting.url */ } | null,
  "bookings": { "upcoming", "completed", "cancelled" },
  "reports": { "total", "unread" },
  "active_subscriptions": [ /* Subscription objects */ ] }
```

`reports.unread` = reports never downloaded (`first_downloaded_at = null`) —
badge the "My reports" menu item.

#### Profile
`GET|PUT /client/profile` (CLI-PRF-01/02: `name*`, `email*`, `phone*`
`05XXXXXXXX`, `company_name*`) · avatar `POST|DELETE /client/profile/avatar`
(CLI-PRF-03/04) · password `PUT /client/profile/password` (CLI-PRF-05).

#### My bookings
- List: `GET /client/bookings?status=&sort=` (CLI-BKG-03, default `-starts_at`).
- Details: `GET /client/bookings/{id}` (CLI-BKG-04) — `meeting.url` is the
  Google Meet link (show it once `meeting.status = created`);
  `report.download_url` when a report exists.
- Cancel: `POST /client/bookings/{id}/cancel` `{ "reason"? }` (CLI-BKG-05) —
  allowed up to 24 h before the start (`BOOKING_CANCEL_WINDOW_PASSED`).
  A paid booking shows `refund_status=requested` → "سيتم استرداد المبلغ".

#### My reports
- List: `GET /client/reports?search=&date_from=&date_to=` (CLI-RPT-01).
- Show: `GET /client/reports/{id}` (CLI-RPT-02).
- Download: `GET /client/reports/{id}/download` (CLI-RPT-03) — see §6.2. The
  first download marks it read.

#### My packages (subscriptions)
- All: `GET /client/subscriptions` (CLI-SUB-01) — `consultations_used` /
  `consultations_remaining` (`null` = unlimited), `status`, `ends_at`.
- Active only: `GET /client/subscriptions/active` (CLI-SUB-02).

#### My locations
CRUD: `GET|POST /client/locations` (CLI-LOC-01/02), `GET|PUT|DELETE
/client/locations/{id}` (CLI-LOC-03/04/05), default
`PATCH /client/locations/{id}/default` (CLI-LOC-06).
Fields: `name*`, `city`, `address*`, `latitude`, `longitude`, `is_default`.

#### My payment methods
- List: `GET /client/payment-methods` (CLI-PM-01) — `brand`, `last_four`,
  `is_default`. The raw gateway token is never returned.
- Add: `POST /client/payment-methods` `{ "token", "is_default"? }` (CLI-PM-02)
  — `token` comes from the gateway JS (§5.5).
- Delete: `DELETE /client/payment-methods/{id}` (CLI-PM-03).
- Default: `PATCH /client/payment-methods/{id}/default` (CLI-PM-04).

#### New booking (wizard)
See §5.

---

## 5. Booking wizard guide

### 5.1 Steps → endpoints

| Step | Endpoint | Notes |
|---|---|---|
| 1. Package | `GET /public/packages` (PUB-01) | Public; keep the whole package object |
| 2. Account | register/login (§2.2) | Everything after this needs the client token |
| 3. Consultant | `GET /public/consultants?search=&specialization=` (PUB-03) | Photo, name, title, specialization |
| 4. Date & time | `GET /public/consultants/{id}/available-dates?month=YYYY-MM` (PUB-05) then `GET /public/consultants/{id}/slots?date=` (PUB-06) | See §5.3/§5.4 |
| 5. Location | `GET /client/locations` (CLI-LOC-01) + create (CLI-LOC-02) | Pre-select `is_default` |
| 6. Payment | `GET /client/payment-methods` (CLI-PM-01) + quote (§5.6) | Saved card or new card |
| 7. Confirm | `POST /client/bookings` (CLI-BKG-02) | See §5.6 |

### 5.2 Summary sidebar state

Keep in the wizard store: `package`, `consultant`, `date`, `time`,
`location`, `payment_method` (or "new card"), and the **quote** result
(`amount`, `amount_formatted`, `requires_payment`, `covered_by_subscription`).
Render the sidebar from these; refresh the quote whenever package/date/time
change.

### 5.3 Date picker from PUB-05

`GET /public/consultants/{id}/available-dates?month=2026-10` →
`data.dates = ["2026-10-04", …]`. Enable exactly those days in the calendar;
disable everything else. Re-fetch when the month changes (omit `month` for the
current month).

### 5.4 Time buttons from PUB-06

`GET /public/consultants/{id}/slots?date=2026-10-04` →
`data.slots = [{ "time": "10:00", "starts_at": "…" }, …]`. Render one button
per slot. Booked/past/time-off slots are **not returned** — an empty array
means "لا توجد مواعيد متاحة في هذا اليوم".

### 5.5 Card tokenization (Moyasar.js)

Never send card numbers to our API. Tokenize in the browser with the gateway
JS and send only the token:

1. `GET /public/meta` (PUB-08) → `data.payment_gateway.publishable_key` and
   `data.payment_gateway.driver` (call it on app boot; when `driver` is
   `fake` in dev, the tokens are simply `tok_fake_success`, `tok_fake_3ds`,
   `tok_fake_declined`).
2. Mount Moyasar.js with the publishable key, get a token, then either save
   the card (CLI-PM-02) or pass it as `card_token` when booking.

### 5.6 Quote, create, 3-D Secure

```
POST /client/bookings/quote
{ "package_id", "consultant_id"?, "date"?, "time"? }
→ data { amount, amount_formatted, currency, requires_payment,
         covered_by_subscription, slot_available }
```

- `requires_payment = false` (active subscription with consultations left) →
  **skip the payment step**; create the booking without card fields.
- `slot_available` is only present when consultant+date+time are sent.

```
POST /client/bookings
{ "package_id", "consultant_id", "date", "time", "client_location_id",
  "payment_method_id"   // saved card, or:
  "card_token", "save_card": true,
  "client_notes"? }
→ 201 data { booking, payment }
```

- `payment.status = paid` → done: `booking.status = pending`, show the
  confirmation with `booking.meeting.url`.
- `payment.status = initiated` + `payment.transaction_url` → **3-D Secure**:
  redirect the browser to `transaction_url`. The gateway returns the user to
  `PAYMENT_CALLBACK_URL` (`/bookings/payment-callback?payment_id=…`); that
  page calls `POST /client/payments/{id}/verify` (CLI-PAY-02) and shows the
  result (`paid` → confirmation; `failed` → offer retry). Verify is
  idempotent — call it freely on page load.
- `402 PAYMENT_FAILED` → show the message, let the user pick another card.
- `503 PAYMENT_PENDING_CONFIRMATION` → the gateway could not be reached, so
  the payment state is unknown (the charge may have gone through). Tell the
  user the payment is being confirmed and poll `GET /client/bookings/{id}`
  after ~30 s: the Moyasar webhook reconciles it in the background. The
  charge is idempotent (Moyasar `given_id`), so even an accidental retry can
  never charge the card twice.

### 5.7 `SLOT_NOT_AVAILABLE` (409)

The slot was taken between the quote and the submit. Re-call PUB-06 for the
same date, mark the chosen time as gone, and ask the user to pick another
slot. Do not retry the same payload.

---

## 6. Files

### 6.1 Uploads (avatar, consultant photo, report)

Use `FormData` and **do not set `Content-Type` yourself** (the browser adds
the boundary):

```js
const fd = new FormData();
fd.append('avatar', fileInput.files[0]);
await fetch(`${base}/client/profile/avatar`, {
  method: 'POST',
  headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  body: fd,
});
```

Limits: avatars/photos jpg/png/webp ≤ 2 MB; report `file` pdf/doc/docx ≤ 20 MB.
The upload responses return the fresh URLs (`avatar_url`, `photo_url`) — bust
the browser cache with the URL's `updated_at` if the image looks stale.

### 6.2 Downloads (report)

Downloads need the token, so fetch a blob and save it under the file name from
`Content-Disposition`:

```js
const res = await fetch(`${base}/client/reports/${id}/download`, {
  headers: { Authorization: `Bearer ${token}` },
});
const blob = await res.blob();
const cd = res.headers.get('Content-Disposition') ?? '';
const filename = cd.match(/filename="?([^"]+)"?/)?.[1] ?? 'report.pdf';
const a = Object.assign(document.createElement('a'), {
  href: URL.createObjectURL(blob), download: filename,
});
a.click();
URL.revokeObjectURL(a.href);
```

The report email also contains a **signed URL** (valid 7 days, no login) —
that link is for the email client, not for the app.

---

## 7. Status badges

Labels come translated from the API (`status_label` etc. or PUB-08 enums) —
use them; the colors below are suggestions.

### 7.1 `booking.status`

| Value | Label (ar) | Color |
|---|---|---|
| `pending_payment` | في انتظار الدفع | amber |
| `pending` | مؤكد / قادم | blue |
| `completed` | مكتمل | green |
| `cancelled` | ملغي | gray/red |

### 7.2 `booking.report_status`

| Value | Label (ar) | Color |
|---|---|---|
| `none` | — | gray |
| `pending` | بانتظار التقرير | amber |
| `uploaded` | تم رفع التقرير | green |

### 7.3 Payment statuses

`payment.status` (a payment record):

| Value | Label (ar) | Color |
|---|---|---|
| `initiated` | بدأ الدفع | amber |
| `paid` | مدفوع | green |
| `failed` | فشل الدفع | red |
| `refunded` | مسترد | purple |

`booking.payment_status` (roll-up on the booking):

| Value | Label (ar) | Color |
|---|---|---|
| `unpaid` | غير مدفوع | amber |
| `paid` | مدفوع | green |
| `not_required` | مشمول بالاشتراك | gray |
| `failed` | فشل الدفع | red |
| `refunded` | مسترد | purple |

### 7.4 `booking.refund_status`

| Value | Label (ar) | Color |
|---|---|---|
| `none` | — | gray |
| `requested` | استرداد مطلوب | amber |
| `refunded` | تم الاسترداد | green |

### 7.5 `booking.meeting.status`

| Value | Label (ar) | Color |
|---|---|---|
| `none` | — | gray |
| `pending` | جاري إنشاء الرابط | amber |
| `created` | الرابط جاهز | green |
| `failed` | فشل إنشاء الرابط | red (show BKG-05 retry to staff) |

### 7.6 `subscription.status`

| Value | Label (ar) | Color |
|---|---|---|
| `active` | نشط | green |
| `expired` | منتهي | gray |
| `cancelled` | ملغي | red |

---

## 8. Postman

1. Import `postman/GCMC-API.postman_collection.json` and
   `postman/GCMC-Local.postman_environment.json` (or `…-Staging…`).
2. Select the environment (top-right) — it carries `base_url`, `locale`, the
   demo credentials and the runtime variables (`admin_token`, `client_token`,
   `booking_id`, …) that the test scripts fill automatically.
3. Run the folders **in order** (00 → 99): logins save the tokens, later
   requests reuse the IDs saved by earlier ones.
4. Every request has a **saved example response** captured from a real run —
   use the examples as the response reference while building the UI.
5. Five requests are manual-only (they skip themselves in CI): both password
   resets (need the emailed token), BKG-03 complete (needs a booking whose
   start time passed), WHK-01 webhook (needs a real Moyasar signature) and
   PUB-07 signed download (needs the signed URL from the email).

Demo accounts (local, after `php artisan migrate:fresh --seed`):

| Who | Email | Password |
|---|---|---|
| Admin | `admin@gcmc.sa` | `Password@123` |
| Consultant | `ahmad.alotaibi@gcmc.sa` | `Password@123` |
| Client | `client@gcmc.sa` | `Password@123` |
