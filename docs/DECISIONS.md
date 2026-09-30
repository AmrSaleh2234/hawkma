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

## 5. Gateway errors never fail a payment (§9.6 clarification)

The plan's Moyasar mapping treated any non-paid answer — including HTTP
errors — as `failed`. That loses real money: an outage during the 3-D Secure
verify would cancel the booking, and the later "paid" webhook was ignored
because failed payments are final.

`MoyasarGateway` now only marks a payment failed when Moyasar's payload
explicitly says `failed` (or `voided` — no money taken). Transport errors
(timeout, connection error, 5xx) throw `GatewayException`: the payment stays
`initiated`, the caller gets a 503 `PAYMENT_PENDING_CONFIRMATION` (a specific
code, so the frontend can say "your payment is being confirmed" instead of
"server error"), and Moyasar retries the webhook until the reconciliation
succeeds. Moyasar's other statuses are mapped explicitly:
`authorized` → initiated (captured later), `captured` → paid, `refunded` →
refunded; an unknown status is logged and treated as initiated, never failed.
On `charge()`, a 4xx still maps to failed (the request was rejected before
processing), while 5xx/connection errors throw.

Covered by `Modules/Payments/tests/Feature/GatewayOutageTest.php`.

## 9. A charge timeout can never lose a payment

One money-loss hole remained after §5: if the FIRST charge request itself
times out, Moyasar may have taken the money while our payment row has no
`gateway_payment_id` (the exception fires before the response is stored).
The "paid" webhook then found nothing (payments were looked up only by that
id), answered 200, and the booking expired unpaid — money gone.

Two defences, both on the charge request:

- `given_id` = the payment's new `uuid` column. Moyasar rejects a second
  charge with the same `given_id`, so re-sending the SAME payment's charge can
  never charge twice. A client resubmitting the wizard is a new booking and a
  new payment (new uuid), so it is not covered by this; the held slot (409)
  and the 503 body's booking id (poll it instead of resubmitting) are what
  protect that case.
- `metadata.payment_id` = our payment id, next to the existing `booking_id`.
  When a webhook's Moyasar id matches no payment, `PaymentService` falls back
  to this id, adopts the gateway id, and reconciles as usual. A payment that
  already has a DIFFERENT gateway id is never re-pointed (tamper guard).

Related hardening: `verify()` skips the gateway call entirely when the
payment has no gateway id yet (only a webhook can reconcile it), and a
`refunded` payment is now a final state for verify, like paid/failed.

Regression tests in `GatewayOutageTest`:
`test_a_charge_timeout_is_reconciled_by_the_webhook_via_the_metadata_payment_id`,
`test_the_webhook_never_repoints_a_payment_that_already_has_a_different_gateway_id`,
`test_verifying_a_payment_without_a_gateway_id_skips_the_gateway_call`.

## 6. Marking a refund cancels the subscription (plan gap)

Per the plan, cancelling a paid booking returns the consultation to the
subscription quota AND flags a refund — so a refunded client kept the
package. Now `BKG-07 mark-refunded` also cancels the subscription the booking
paid for (`SubscriptionService::cancel()`, only when still active): the
client got the money back, so the package cannot stay active.

## 7. Quota race surfaces as SUBSCRIPTION_EXHAUSTED

`SubscriptionService::consume()` (the last-resort row-lock guard against two
concurrent bookings spending the same last consultation) threw a plain
`DomainException` → raw 500. It now throws
`BusinessException(ErrorCode::SubscriptionExhausted)` → 422 with the
envelope. New error code: `SUBSCRIPTION_EXHAUSTED`.

## 8. PHP platform pinned to 8.3

`composer.json` advertised `php: ^8.3` but the lock file contained Symfony
8.x (`>=8.4.1`), so a fresh clone could not install on PHP 8.3.
`config.platform.php` is now pinned to `8.3` and the lock file was
re-resolved (Symfony 8.1 → 7.4, which Laravel 13 fully supports), keeping the
plan's "PHP 8.3+" promise true.

## 10. Refund vs. consume race, and the 503 booking body

- `SubscriptionService::consume()` re-checks `isActive()` (status active and
  inside `starts_at`..`ends_at`) under the row lock, not only the remaining
  quota, and throws 422 `SUBSCRIPTION_INACTIVE`. `cancel()` takes the same
  row lock. Before this, a booking quoted just before an admin marked the
  refund could still consume a consultation of the cancelled subscription.
- `POST /client/bookings` answering 503 `PAYMENT_PENDING_CONFIRMATION` now
  includes `data: {booking, payment}` (the rest of the error envelope is
  unchanged), so the wizard can poll the booking instead of resubmitting.
  `payment.requires_action` is true only when there is a `transaction_url`
  to redirect to.

Regression tests: `SubscriptionServiceTest::test_consume_refuses_a_subscription_cancelled_after_the_quote`,
`test_consume_refuses_a_subscription_past_its_end_even_if_still_marked_active`,
and the 503 body assertions in
`GatewayOutageTest::test_a_charge_timeout_is_reconciled_by_the_webhook_via_the_metadata_payment_id`.
