# GCMC Activity Logs Guide (Frontend)

How the admin dashboard reads the **audit trail**: a single list of everything
that happened in the system — every create / update / draft / restore on every
module, plus login / logout / failed-login API events.

Read this together with `docs/FRONTEND_API_GUIDE.md` — the base URL, headers
(`Accept`, `Accept-Language`, `Authorization: Bearer …`) and the
`{ success, data, meta, links }` envelope are the same.

---

## 1. Endpoints

| ID | Endpoint | Permission |
|---|---|---|
| LOG-01 | `GET /api/v1/admin/activity-logs` | `view-activity-logs` |
| LOG-02 | `GET /api/v1/admin/activity-logs/meta` | `view-activity-logs` |

Both are admin-side and paginated (LOG-01: 15 per page default, `per_page`
max 100, newest first). No write endpoints exist — log rows are created by
the backend itself and are immutable (there is intentionally no delete).

---

## 2. What is a log row

```jsonc
{
  "id": 182,
  "log_name": "system",              // "system" | "api"
  "module": "consultants",           // users | consultants | clients | packages | bookings | payments | reports | auth | …
  "event": "updated",                // see §3
  "description": "ConsultantAvailability updated",
  "subject": {                       // the record that was touched (null for auth events)
    "type": "ConsultantAvailability",
    "id": 41,
    "name": null                     // best-effort label: name / title / reference
  },
  "causer": {                        // who did it (null for console/queue work)
    "type": "User",                  // "User" (admin/consultant) or "Client"
    "id": 7,
    "name": "Admin User"
  },
  "properties": {                    // payload — shape depends on the event (§4)
    "changes": { "is_active": false },
    "old": { "is_active": true },
    "request": {
      "method": "PUT",
      "url": "api/v1/admin/consultants/7/availability/41",
      "user_agent": "…"
    }
  },
  "ip_address": "88.99.10.4",
  "created_at": "2026-09-20T09:00:00+03:00"
}
```

Key points:

- `causer` is the acting **admin/consultant** (`User`) or **client**
  (`Client`). It is `null` for seeders, cron and queue work.
- `subject` is the affected record. Auth events have no subject.
- A drafted user/client who did something **before** being drafted still
  resolves — the causer/subject lookups include drafted records.

---

## 3. Events

| `event` | `log_name` | Fired when |
|---|---|---|
| `created` | system | A record is inserted (any module). `properties.attributes` = the new values. |
| `updated` | system | A record's fields change. `properties.changes`/`old` = the diff only. |
| `deleted` | system | A record is drafted (soft-deleted). `properties.force` tells a real purge from a draft. |
| `restored` | system | A drafted record is restored. |
| `login` | api | Successful admin/consultant/client login. `properties.guard` = `admin` or `client`. |
| `logout` | api | Token revoked. |
| `login_failed` | api | Wrong password (`properties.email`) or a disabled account (`properties.reason: "account_disabled"`). |

Notes:

- Login only bumps `last_login_at` — that housekeeping write is **not**
  logged as `updated`, so the log stays clean.
- Passwords, tokens and secrets are **never** stored — they are stripped
  from `properties` before the row is written.

---

## 4. `properties` cheat sheet

| Event | Shape |
|---|---|
| `created` | `{ attributes: {…new values…}, request: {…} }` |
| `updated` | `{ changes: {…new…}, old: {…previous…}, request: {…} }` |
| `deleted` | `{ attributes: {…final snapshot…}, force: false, request: {…} }` |
| `restored` | `{ request: {…} }` (or empty) |
| `login` / `logout` | `{ guard: "admin"\|"client", device_name?, request: {…} }` |
| `login_failed` | `{ guard, email, reason?, request: {…} }` |

`properties.request` is only present for HTTP-triggered actions.

---

## 5. Filters (LOG-01 query params)

| Param | Values | Notes |
|---|---|---|
| `user_id` | admin/consultant id | Only rows caused by that `User` |
| `client_id` | client id | Only rows caused by that `Client` |
| `module` | `users`, `consultants`, `clients`, `packages`, `bookings`, `payments`, `reports`, `auth`, … | Case-insensitive; see LOG-02 for the live list |
| `event` | any value from §3 | Exact match |
| `log_name` | `system` \| `api` | "What happened in the DB" vs "who entered the API" |
| `date_from` | `Y-m-d` or `Y-m-d H:i:s` | A bare date = from `00:00:00` that day |
| `date_to` | `Y-m-d` or `Y-m-d H:i:s` | A bare date = through `23:59:59` that day |
| `search` | free text | Matches `description`, `ip_address`, or the actor's name |
| `sort` | `created_at`, `-created_at` | Default `-created_at` |
| `per_page` | 1–100 | Default 15 |

All filters combine (AND). Common queries:

```
GET /api/v1/admin/activity-logs?module=packages&event=updated
GET /api/v1/admin/activity-logs?user_id=7&date_from=2026-09-01&date_to=2026-09-30
GET /api/v1/admin/activity-logs?log_name=api&event=login_failed&search=88.99
GET /api/v1/admin/activity-logs?module=bookings&date_from=2026-09-20 09:00:00&date_to=2026-09-20 12:00:00
```

---

## 6. Building the filter UI (LOG-02)

`GET /api/v1/admin/activity-logs/meta` returns exactly what the dropdowns
need — no hard-coded lists:

```jsonc
{
  "success": true,
  "data": {
    "modules": ["accesscontrol", "activitylogs", "auth", "bookings", "clients", "consultants", "packages", "users"],
    "events": ["created", "updated", "deleted", "restored", "login", "logout", "login_failed"],
    "log_names": ["system", "api"]
  }
}
```

`modules` is the distinct set of modules that actually produced rows (auth
events appear under module `auth`). Call it once when the page mounts, or on
focus if you want it fresh.

Suggested UX:

- One filter bar: **actor** (user/client picker), **module**, **event**,
  **source** (`log_name`), **date range** (date + optional time), **search**.
- A row renderer per event type: diff tables for `updated`
  (`changes` + `old` side by side), a simple line for auth events.
- Show `ip_address` and `request.url` in an expandable detail row.
