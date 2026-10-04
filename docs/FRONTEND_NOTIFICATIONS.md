# Notification Centre — Frontend Guide

Everything the two frontends (admin portal + client portal) need to build
the notification bell: the endpoints, the payload shape, the `type` →
details-page mapping, and the recommended UX flow.

Base URL: `{{BASE_URL}}/api/v1` — same auth, envelope and headers as the
rest of the API (`Authorization: Bearer <token>`, `Accept: application/json`,
`Accept-Language: ar|en`).

There are **two identical route groups**, one per guard:

| Portal | Guard | Prefix |
|---|---|---|
| Admin portal (staff **and** consultants — both log in through `admin/auth`) | `admin` | `/admin/notifications` |
| Client portal | `client` | `/client/notifications` |

Every endpoint returns **only the logged-in user's own notifications**.
A consultant sees his personal notifications; a staff member sees his
personal notifications **plus** a copy of every relevant activity
(bookings, payments, reports, new clients, new staff accounts) — the admin
feed. A notification can never be read, marked or deleted by someone else
(the API answers `404`).

---

## 1. Endpoints

### `GET /admin/notifications` (and `/client/notifications`)

Paginated list, newest first.

**Query params**

| Param | Values | Meaning |
|---|---|---|
| `unread` | `1` / `0` | `unread=1` → only unread |
| `type` | see the type table below | filter by `data.type` |
| `page`, `per_page` | integers, `per_page` ≤ 100 | standard pagination |

**Response** — the standard envelope:

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": "9f1b2c3d-…",
      "type": "new_booking",
      "data": {
        "type": "new_booking",
        "booking_id": 42,
        "reference": "BK-2026-000042",
        "company_name": "Acme Co."
      },
      "read_at": null,
      "created_at": "2026-09-20T09:05:00+03:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 3, "last_page": 1 },
  "links": { "first": "…", "last": "…", "prev": null, "next": null }
}
```

- `id` — the notification id (UUID string). Use it for mark-read / delete.
- `type` — the semantic type (**same value as `data.type`**, duplicated at
  the top level for convenience). `null` is possible for legacy rows.
- `data` — the payload that produced the notification (see the table).
- `read_at` — `null` = unread. This drives the bold/grey styling.
- `created_at` — when it happened, ISO-8601 (Riyadh time).

### `GET /admin/notifications/unread-count` (and `/client/notifications/unread-count`)

Lightweight endpoint for the bell **badge** — call it on page load and then
poll it (see §4).

```json
{ "success": true, "message": "OK", "data": { "unread_count": 3 } }
```

### `PATCH /admin/notifications/{id}/read` (and client)

Marks one notification read. Response: `data` = the notification resource
(now with `read_at` set). Idempotent — marking an already-read one is fine.

### `POST /admin/notifications/read-all` (and client)

Marks **all** unread notifications read.

```json
{ "success": true, "message": "…", "data": { "marked_count": 5 } }
```

### `DELETE /admin/notifications/{id}` (and client)

Deletes a single notification (the "×" in the list). `200`, `data: null`.

Errors are the usual contract: `401 UNAUTHENTICATED`, `404 NOT_FOUND`
(including trying to touch another user's notification),
`422 VALIDATION_ERROR` for bad query params.

---

## 2. The `type` → icon → details page map

`data.type` tells you **what the notification is about** and which id to
use for navigation:

| `type` | Meaning | Entity id in `data` | Open |
|---|---|---|---|
| `new_booking` | A booking was confirmed (→ consultant + admin feed) | `booking_id`, `reference`, `company_name` | **Booking details** `/bookings/{booking_id}` |
| `booking_confirmed` | Booking confirmed for the client (→ client + admin feed) | `booking_id`, `reference`, `meeting_url` | Booking details — `meeting_url` may be `null` (meeting failed); show a "join" button when present |
| `booking_cancelled` | A booking was cancelled (→ client, consultant, admin feed) | `booking_id`, `reference`, `reason` | Booking details (show `reason`) |
| `payment_failed` | The client's payment failed (→ client + admin feed) | `booking_id`, `reference` | Booking details — CTA "retry payment" re-opens the wizard for that slot |
| `report_ready` | A report was uploaded (→ client + admin feed) | `report_id`, `title` | **Report details** `/reports/{report_id}` |
| `client_welcome` | A client registered (→ client; admin feed = "new client") | — (admin feed has `recipient`) | No details page (admin: client profile via `recipient.id`) |
| `staff_account_created` | An admin created a staff/consultant account (→ that user; admin feed) | `email` (admin feed adds `recipient.id`) | No details page (admin: user profile `/users/{recipient.id}`) |
| `reset_password` | Password-reset notice (→ the account owner only) | — | No details page — never shown to admins |

### Admin feed extra field: `data.recipient`

On admin accounts, mirrored notifications carry **one extra key** telling
you who the original notification went to:

```json
"data": {
  "type": "report_ready",
  "report_id": 15,
  "title": "Quarterly review",
  "recipient": { "type": "client", "id": 9, "name": "Acme Co.", "email": "ops@acme.sa" }
}
```

- `recipient.type` — `"client"` or `"user"` (consultant/staff).
- Use it for the subtitle, e.g. *"Report ready — sent to Acme Co."*.

Staff/consultant personal notifications (like `staff_account_created` on
the new user's own bell) do **not** have `recipient` — check with
`"recipient" in data` before rendering it.

---

## 3. UX flow (the bell)

1. **Badge**: `GET …/notifications/unread-count` on app load → show the
   number on the bell icon. Poll every 30–60 s while the user is active
   (no websockets — polling is the contract for now).
2. **Open the dropdown**: `GET …/notifications?per_page=10` (add
   `unread=1` for an "unread only" tab). Show icon + text per `type`,
   bold when `read_at === null`, relative time from `created_at`.
3. **Click a notification**:
   - fire `PATCH …/notifications/{id}/read` (don't wait for it),
   - then navigate using the type map above:
     `booking_id` → booking details, `report_id` → report details.
     Informational types (`client_welcome`, `staff_account_created`,
     `reset_password`) have no details page — just mark read.
4. **"Mark all read"** button → `POST …/read-all`, then clear the badge.
5. **Per-item delete** → `DELETE …/{id}`, then remove the row locally.

---

## 4. Notes

- Notifications arrive through the `database` channel; emails are a
  separate channel and do not affect the bell.
- `data` keys are additive — new optional keys may appear (like
  `recipient`); code defensively.
- The list is per-user and personal; there is no global feed endpoint.
- Pagination, envelope, auth headers, throttling and error codes follow
  `docs/FRONTEND_API_GUIDE.md` exactly.

---

# Customer Service — Frontend Guide

Customer service is a ticket chat. The client opens **Customer Service**, clicks **Add Service**, chooses a category, writes the description, and submits it. The API resolves the consultant automatically; the client never selects a consultant.

## Routing rules

- A client with an active package is connected to the consultant selected when that package was purchased.
- A client without an active package is routed to staff only.
- `consultant_complaint` is always staff-only. The consultant cannot list, open, or receive messages/notifications for it; direct access returns `404`.
- Staff can access every ticket. A consultant can access only tickets assigned to them, excluding consultant complaints.

## Categories

`general_inquiry`, `booking_issue`, `payment_issue`, `technical`, `consultant_complaint`.

## Client endpoints

- `GET /client/support-tickets` — paginated service history.
- `POST /client/support-tickets` — create a service. Multipart fields: `category`, `description`, optional `image`, optional `voice`.
- `GET /client/support-tickets/{id}` — ticket details and chronological chat.
- `POST /client/support-tickets/{id}/messages` — send `body`, `image`, or `voice`.

## Admin and consultant endpoints

- `GET /admin/support-tickets` — paginated list. Staff may filter with `status`, `category`, `client_id`, or `consultant_id`; consultants are always server-scoped.
- `GET /admin/support-tickets/{id}` — open chat.
- `POST /admin/support-tickets/{id}/messages` — reply with text, image, or voice. Staff may send `is_internal=1`; internal notes are hidden from clients and consultants.
- `PATCH /admin/support-tickets/{id}/status` — staff-only; `open`, `in_progress`, `resolved`, or `closed`.

## Chat uploads

Send messages as `multipart/form-data`. At least one of `body`, `image`, or `voice` is required. Images: JPG/PNG/WebP up to 10 MB. Voice: MP3/M4A/OGG/WAV/WebM up to 20 MB. A `closed` ticket rejects new messages. A client message on a `resolved` ticket changes it to `in_progress`.

The normal API envelope, bearer tokens, localization, pagination, and throttling rules described earlier in this document apply.
---

# Join Us — Frontend Guide

The landing-page **Join Us** form lets an expert apply to become a consultant. It is public and does not require authentication.

## Public submission

`POST /public/join-requests` as `multipart/form-data`:

- `name` — required, max 191.
- `email` — required and must not belong to an existing user or pending application.
- `phone` — required.
- `specialization` — required.
- `bio` — optional, max 5,000.
- `linkedin_url` — optional valid URL.
- `cv` — **required PDF**, maximum 20 MB.

A successful submission returns `201` with status `pending` and sends a `join_request_submitted` database notification to staff. Duplicate pending applications and existing account emails return `422`.

## Admin dashboard

The dashboard section uses:

- `GET /admin/join-requests` — paginated list. Filters: `status=pending|approved|rejected`, `search`, `page`, `per_page`.
- `GET /admin/join-requests/{id}` — application details.
- `GET /admin/join-requests/{id}/cv` — authenticated CV download.
- `PATCH /admin/join-requests/{id}/approve` — approves a pending request.
- `PATCH /admin/join-requests/{id}/reject` with `{ "reason": "..." }` — rejects it and emails the reason.

## Approval flow

Approval automatically creates an active `consultant` account using the applicant name, email, phone, specialization, and bio. No known password is assigned. The existing account-created email sends the consultant a one-time reset link. The link opens the admin portal reset-password page, where the consultant chooses and confirms a private password, then logs in through the normal consultant/admin login endpoint using the application email.

The application becomes `approved`, stores `user_id`, `reviewed_by`, and `reviewed_at`, and cannot be reviewed again.

## Rejection flow

Rejection requires a reason, marks the application `rejected`, stores the reviewer and date, and sends the applicant an email containing that reason. No user account is created.

## Notification navigation

For notification `type = join_request_submitted`, use `data.join_request_id` and navigate to the admin Join Us application details page. The payload also includes `name` and `specialization`.
---

# Client Reviews — Frontend Guide

The client portal has a **Write a review** section. The form contains only: star rating (1-5), display name, and review text with a submit button.

## Client endpoints (requires client login)

- `POST /client/reviews` — submit `{ "name": "...", "rating": 1-5, "comment": "..." }` (`title` optional). Returns `201` with `status: pending`. Submitting notifies all staff.
- `GET /client/reviews` — the client's own reviews with their moderation status (`pending|approved|rejected`) and `rejection_reason`.

## Landing page (public)

`GET /public/reviews` — paginated, **approved reviews only**, newest `published_at` first. Each item exposes `name`, `rating`, `title`, `comment`, `published_at`. No authentication needed.

## Admin dashboard

- `GET /admin/reviews` — filters: `status=pending|approved|rejected`, `search`, `page`, `per_page`.
- `GET /admin/reviews/{id}` — details including the submitting client.
- `PATCH /admin/reviews/{id}/approve` — publishes immediately (`published_at` is set).
- `PATCH /admin/reviews/{id}/reject` with `{ "reason": "..." }` — hides it; the client sees the reason in `GET /client/reviews`.

A review can be reviewed once only — calling approve/reject twice returns `422`.

## Notification navigation

For notification `type = review_submitted`, use `data.review_id` to open the admin review details page. The payload also includes `name` and `rating`.