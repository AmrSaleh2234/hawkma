# Decisions — deviations from the plan

Every place where the implementation differs from
[`BACKEND_IMPLEMENTATION_PLAN.md`](BACKEND_IMPLEMENTATION_PLAN.md), and why.

## 1. Named rate limiters instead of literal `throttle:X,1` (§12.1)

The plan asks for `throttle:10,1` on login/register/forgot, `throttle:60,1`
on public routes and `throttle:120,1` on authenticated routes.

Laravel's default throttle signature for guests is `sha1(domain|ip)` — **one
shared bucket per IP across all routes**. With the literal middleware, ten
public page views would lock a guest out of the login endpoint, and any two
endpoints would share their budget. Also, the default guard is `admin`, so
`$request->user()` is null for client tokens and client users would have been
throttled as guests.

Instead, `CoreServiceProvider::configureRateLimiting()` registers three named
limiters with the **same limits** (10/60/120 per minute) but correct keys:

- `auth` — per `ip + route name` (each credential endpoint gets its own bucket)
- `public` — per `ip + route name`
- `api` — per user id, checking `user('admin')` then `user('client')`

Route files use `throttle:auth` / `throttle:public` / `throttle:api`.

## 2. Postman collection (§14)

### 2.1 Headers per request

Postman collection format v2.1 has no collection-level headers, so
`Accept: application/json` and `Accept-Language: {{locale}}` are set on every
request individually (same effect).

### 2.2 Five manual-only requests

These requests cannot run unattended in newman and skip themselves when
`ci=true` (`pm.execution.skipRequest()`):

| Request | Why |
|---|---|
| ADM-AUTH-05 / CLI-AUTH-06 reset password | Need the token from the email |
| BKG-03 complete booking | No seeded booking is both `pending` and past its start time |
| WHK-01 Moyasar webhook | Needs a real Moyasar signature |
| PUB-07 signed report download | The signed URL cannot be computed inside Postman — copy it from the email |

### 2.3 Extra seeded booking for the run

`DemoClientSeeder` seeds a **second** completed booking (report_status
`pending`, payment `not_required`, consultant sara.aldosari) beyond what §13
describes. The run uploads a fresh report to it (RPT-03 → 201) and deletes
that report (RPT-05), so the seeded demo report — needed by folders 16/17 —
survives the run untouched.

### 2.4 Serving the postman environment

`php artisan serve --env=postman` does **not** propagate the environment to
the request-handling subprocesses (the `--env` flag only tells the serve
command which `.env` file to watch). Serve with the OS variable instead, which
is passed through:

```sh
APP_ENV=postman php artisan serve --port=8000
```

## 3. Code coverage not measured (§12.5)

The plan asks for a coverage check "if coverage is available". The environment
has neither PCOV nor Xdebug (`php artisan test --coverage` reports "No code
coverage driver available"), so coverage was not measured. The suite is
470 tests / 2,382 assertions.

## 4. Hardening fixes found by the end-to-end runs

Not plan deviations, but worth recording:

- `Booking::client()` / `Booking::consultant()`, `Payment::client()` and
  `Report::consultant()` / `client()` / `uploader()` use `withTrashed()` —
  the newman run caught 500s on the admin payment list after a client was
  soft-deleted (regression test:
  `test_pay_01_a_payment_of_a_deleted_client_still_lists`).
- The N+1 test suite (`tests/Feature/NPlusOneTest.php`, one test per index
  endpoint) caught 7 missing eager loads (`media` on user/client/consultant/
  report indexes, `client` on the client bookings index); all fixed.
