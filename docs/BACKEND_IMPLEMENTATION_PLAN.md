# GCMC Consultancy Platform — Backend Implementation Plan (Laravel 13, Modular)

> **Audience:** an AI coding agent (or junior developer) that will build the whole backend **step by step**.
> **Rule #1:** Follow this document in order. Do not skip phases. Do not "improve" names, routes, columns or response shapes. The frontend guide and the Postman collection depend on them being exactly as written here.
> **Rule #2:** A phase is DONE only when `php artisan test` is fully green **and** the phase's acceptance checklist is ticked.
> **Rule #3:** If something in this document conflicts with the official package docs (because a package version changed), follow the package docs for the *syntax* but keep the *behaviour* described here. Write a note in `docs/DECISIONS.md` explaining the difference.

---

## Table of Contents

0. [How to work with this plan](#0-how-to-work-with-this-plan)
1. [Business summary](#1-business-summary)
2. [Tech stack and exact versions](#2-tech-stack-and-exact-versions)
3. [Actors, guards, glossary](#3-actors-guards-glossary)
4. [Assumptions and open questions](#4-assumptions-and-open-questions)
5. [Architecture and conventions](#5-architecture-and-conventions)
6. [Authentication design (two guards)](#6-authentication-design-two-guards)
7. [Roles and permissions catalogue](#7-roles-and-permissions-catalogue)
8. [Database schema (every table, every column)](#8-database-schema)
9. [Business rules (state machines and algorithms)](#9-business-rules)
10. [API catalogue (every endpoint)](#10-api-catalogue)
11. [Services, actions, jobs, notifications](#11-services-actions-jobs-notifications)
12. [Testing strategy (feature + unit for every API)](#12-testing-strategy)
13. [Implementation phases (step by step)](#13-implementation-phases)
14. [Postman collection (final deliverable)](#14-postman-collection)
15. [Frontend guide (final deliverable)](#15-frontend-guide)
16. [Definition of Done (final checklist)](#16-definition-of-done)

---

## 0. How to work with this plan

1. Read sections 1–12 **completely** before writing any code. They are the specification.
2. Section 13 is the ordered to-do list. Execute phase by phase.
3. After every phase:
   - Run `./vendor/bin/pint` (code style).
   - Run `php artisan test`. All tests must pass.
   - Commit with message `phase-XX: <short description>`.
4. Never commit secrets. `.env` is git-ignored; keep `.env.example` updated with every new variable.
5. When a section says **MUST**, it is non-negotiable. When it says **SHOULD**, do it unless it is impossible.
6. Every endpoint in section 10 **MUST** have:
   - A FormRequest class for validation if it accepts input.
   - An API Resource class for the output.
   - Feature tests covering these cases: success, validation failure (422), unauthenticated (401), no permission or not the owner (403), and not found (404) where it makes sense.
   - Unit tests for every Service/Action class that contains business logic.
7. Any business error (not a validation error) is thrown as `Modules\Core\Exceptions\BusinessException` with an `error_code` from section 5.7. Never `return response()->json([...], 400)` by hand inside controllers.

---

## 1. Business summary

The company (GCMC — "Office of Specialists in Governance and Compliance for Management Consultancy") sells **consultancy packages** to companies. A client company buys a package, books a session with a consultant, and the session happens online in **Google Meet**. After the session, the consultant uploads a **report**. The client receives an email with a link to the report.

There are **two front-end applications** that use this backend:

### 1.1 Dashboard A — Admin and Consultant dashboard (guard `admin`)

These people log in here: **admins** (staff) and **consultants**. They are stored in the same `users` table.

Pages and what the backend must support:

| Page | Who | What it does |
|---|---|---|
| Login / Forgot password | everyone in `users` | Login with email + password, get a token |
| Roles & Permissions | admin | List roles. Create a role and select many permissions. Edit or delete a role. Click a role to see the users who have it. |
| Users | admin | List all users (admins and consultants). Create a user and choose his role(s). Edit, delete, activate/deactivate. Assign roles to a user. |
| Consultants | admin | List consultants. Create a consultant (photo, **name\***, **email\***, phone, title, specialization, bio). The consultant gets the role `consultant` automatically. |
| Consultant details | admin | Tabs: **Profile**, **Availability** (working days and the time ranges in each day, editable), **Time off**, **Clients** (clients who booked him), **Bookings** (with a filter: pending/completed/cancelled), **Pending reports** (completed bookings that do not have a report yet), **Reports** (reports he uploaded). |
| Bookings | admin: all; consultant: only his own | List, filter, view details (consultant, client, package, location, payment, Google Meet link). Actions: complete, cancel, upload report. |
| Reports | admin: all; consultant: only his own | List reports with the consultant who wrote it and the client it is for. Download the file. |
| Clients | admin: all; consultant: only clients who booked him | List and view a client with his bookings and reports. |
| Packages | admin | CRUD of the 3 packages (Iron, Silver, Gold) and any future ones. |
| Payments | admin | List payments and view their details. |
| Profile | everyone in `users` | View/update own profile, change avatar, change password. |
| My availability | consultant | A consultant manages his own weekly availability and time off. |
| Dashboard home | admin / consultant | Statistics cards (counts). Consultants only see their own numbers. |

**Consultant restriction (MUST):** a consultant can only see **his own** bookings, **his own** clients, **his own** reports and **his own** profile/availability. He can **never** see other consultants, other consultants' bookings/clients/reports, or the users/roles pages (unless an admin gives him those permissions; and even then the data scoping in section 9.8 still applies).

### 1.2 Dashboard B — Client dashboard (guard `client`)

Clients are companies. They are stored in the `clients` table (separate from `users`).

| Page | What it does |
|---|---|
| Register / Login / Forgot password | Register with full name, email, phone, company name, password, password confirmation. |
| Profile | View/update profile, change avatar, change password. |
| My bookings | List bookings, view details (with Google Meet link), cancel (see rules). |
| My reports | List reports and download them. |
| My packages | List subscriptions (active/expired) and how many consultations are left. |
| My locations | CRUD of company locations/branches. |
| My payment methods | List saved cards, add a new card, delete, set as default. |
| New booking (wizard) | 6 steps. See 1.3. |

### 1.3 The booking wizard (client side, 6 steps, from the screenshots)

1. **Package:** the client chooses a package on the public pricing page (Iron 1,900 SAR/month, Silver 4,500 SAR/month, Gold 9,800 SAR/month). Public API, no login needed.
2. **Account:** the client registers or logs in. After this step all calls use the client token.
3. **Consultant:** a list of active consultants with photo, name, title and specialization, plus search by name or specialization.
4. **Date and time:** the client picks a date. The API returns the **available 30-minute slots** for that consultant on that date. Example: the consultant works 10:00–12:00, so the slots are `10:00, 10:30, 11:00, 11:30`. Slots that are already booked, in the past, or inside time off are not returned.
5. **Location:** the client chooses one of his saved company locations or adds a new one.
6. **Payment:** the client picks a saved card or enters a new card (and can tick "save card for future payments").
7. **Confirmation:** the booking is created and paid. A Google Meet event is created. The client (and the consultant) receive an email with the date, time and Meet link.

The booking summary sidebar in the screenshots (package, consultant, date/time, location, payment method, total) is built by the frontend from the selections. The backend also offers a `POST /client/bookings/quote` endpoint that returns the final total and whether payment is needed (see 9.4).

### 1.4 Booking life cycle (short version; full version in section 9.1)

```
client submits wizard
        │
        ▼
 pending_payment ──(payment failed / 15 min timeout)──► cancelled
        │ payment succeeded (or covered by subscription)
        ▼
     pending  ──(admin/consultant/client cancels)──► cancelled
        │ consultant/admin clicks "complete" (after the session start time)
        ▼
    completed  (report_status = pending)  → shows in "Pending reports" tab
        │ consultant/admin uploads a report
        ▼
    completed  (report_status = uploaded) → shows in "Reports" tab, client gets an email
```

---

## 2. Tech stack and exact versions

These versions were checked on Packagist when this plan was written. Use the latest **patch** of each major version.

| Item | Version | Docs |
|---|---|---|
| PHP | **8.3+** (8.4 recommended) | — |
| Laravel framework | **13.x** | https://laravel.com/docs |
| Laravel Sanctum (API tokens) | **4.x** | https://laravel.com/docs/sanctum |
| nwidart/laravel-modules | **13.x** (this version matches Laravel 13; the v6 docs linked in the request are old, so read the latest docs) | https://laravelmodules.com/docs |
| spatie/laravel-permission | **8.x** | https://spatie.be/docs/laravel-permission/v8/introduction |
| spatie/laravel-medialibrary | **11.x** | https://spatie.be/docs/laravel-medialibrary/v11/introduction |
| wikimedia/composer-merge-plugin | latest (required by laravel-modules to autoload each module's composer.json) | — |
| google/apiclient | **2.x** (Google Calendar API → creates the Google Meet link) | https://developers.google.com/calendar/api |
| Database | MySQL 8 (production/local), SQLite in-memory (tests) | — |
| Queue | `database` driver locally, Redis in production (optional) | — |
| Mail | `log` locally, SMTP/SES in production | — |
| Tests | PHPUnit (Laravel's default test runner, `php artisan test`) | — |
| Code style | Laravel Pint | — |
| Postman collection | Postman Collection **v2.1** format, checked with **newman** | — |

**PHP extensions required:** `pdo_mysql`, `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, `gd` or `imagick` (media conversions), `exif`, `bcmath`, `intl`, `zip`.

---

## 3. Actors, guards, glossary

| Term | Meaning |
|---|---|
| **User** | A row in `users`. Can log in to Dashboard A. `type` is `admin` (staff) or `consultant`. |
| **Admin** | A user with `type = admin`. The seeded super admin has the role `admin`, which has **all** permissions. |
| **Consultant** | A user with `type = consultant` and the role `consultant`. |
| **Client** | A row in `clients`. A company account that logs in to Dashboard B. |
| **Guard `admin`** | A Sanctum guard whose provider is `users`. Every `/api/v1/admin/*` route uses `auth:admin`. |
| **Guard `client`** | A Sanctum guard whose provider is `clients`. Every `/api/v1/client/*` route uses `auth:client`. |
| **Package** | A product the client buys (Iron/Silver/Gold). It has a monthly price and a monthly limit of consultations. |
| **Subscription** | A row in `client_subscriptions` created when a client pays for a package. It lasts 30 days and tracks used consultations. |
| **Availability** | Weekly recurring working time ranges of a consultant (for example Sunday 10:00–12:00 and 14:00–16:00). |
| **Time off** | A specific date (whole day or a time range) when the consultant is not available. |
| **Slot** | A 30-minute start time generated from availability, for example `10:30`. |
| **Booking** | A reserved slot: client + consultant + package + date/time + location + payment + Meet link. |
| **Report** | A file (PDF/DOC/DOCX) uploaded by the consultant for a completed booking. It is stored with Spatie Media Library. |
| **Payment** | One charge attempt for a booking, done through the payment gateway. |
| **Payment method** | A saved (tokenized) card of a client. The backend **never** stores card numbers or CVV. |
| **Halalas** | Money is stored as an **integer number of halalas** (1 SAR = 100 halalas). 1,900 SAR = `190000`. |

---

## 4. Assumptions and open questions

These are decisions this plan made because the request did not cover them. They are built so they are easy to change later (mostly config values). **Open questions for the product owner** are marked with ❓. The implementer MUST use the default written here and MUST NOT wait for an answer.

| # | Topic | Default used in this plan | ❓ Question to confirm |
|---|---|---|---|
| A1 | Package payment model | A package is a **30-day subscription**. The first booking with a package charges the full package price and creates a subscription. The next bookings with the **same package** while the subscription is active and still has consultations left cost **0** and skip payment. Gold has unlimited consultations (`consultations_limit = null`). | ❓ Is the package paid once per month (subscription), or is the package price charged on every booking? |
| A2 | Session length | 30 minutes (`BOOKING_DURATION_MINUTES=30`), slot step 30 minutes (`BOOKING_SLOT_MINUTES=30`). | ❓ Is a consultation 30 minutes or 60 minutes? |
| A3 | Time zone | All times are **Asia/Riyadh** (`APP_TIMEZONE=Asia/Riyadh`). Saudi Arabia has no daylight saving time, so times are stored in local time. | — |
| A4 | Payment gateway | **Moyasar** (a common Saudi gateway: mada, Visa, Mastercard, Apple Pay) behind a `PaymentGateway` interface, plus a `fake` driver for local development and tests. | ❓ Which gateway will be used: Moyasar, Tap, HyperPay, or another one? |
| A5 | Card saving | Cards are tokenized **on the frontend** with the gateway's JS library. The backend receives only a token. | — |
| A6 | Google Meet | The Google Calendar API with a service account + domain-wide delegation impersonating a company Google Workspace user (for example `bookings@gcmc.sa`). A `fake` driver is used locally and in tests. | ❓ Does the company have Google Workspace? (Needed for automatic Meet links.) |
| A7 | Minimum notice | A slot must start at least 60 minutes from now (`BOOKING_MIN_NOTICE_MINUTES=60`). | ❓ |
| A8 | Booking horizon | Clients can book up to 60 days ahead (`BOOKING_MAX_ADVANCE_DAYS=60`). | ❓ |
| A9 | Payment hold | A `pending_payment` booking holds the slot for 15 minutes (`BOOKING_PAYMENT_HOLD_MINUTES=15`), then it is cancelled automatically. | — |
| A10 | Client cancellation | A client can cancel a `pending` booking up to 24 hours before it starts (`BOOKING_CLIENT_CANCEL_HOURS=24`). The consultation is returned to the subscription quota. **No automatic refund**: `refund_status` is set to `requested` and an admin handles it manually. | ❓ Refund policy? |
| A11 | Complete button | A booking can be marked completed only when `now >= starts_at`. | — |
| A12 | One report per booking | Each booking has at most one report. Uploading again **replaces** the file. | ❓ Can there be several reports per booking? |
| A13 | Consultant password | When an admin creates a consultant or user without a password, a random password is generated and a "set your password" email (password reset link) is sent. | — |
| A14 | Location meaning | The meeting is online (Google Meet), so the location is the client company branch the booking belongs to. It is stored on the booking for the invoice/report. | ❓ Are some sessions in person at the location? |
| A15 | Languages | API messages come in Arabic (`ar`, the default) or English (`en`) depending on the `Accept-Language` header. Packages have `name_ar`/`name_en`, etc. | — |
| A16 | Consultant default permissions | Seeded as listed in 7.3. The admin can change them later from the Roles page. | ❓ Final consultant permissions (you said you will decide later). |
| A17 | Documents review | The packages say "review N documents monthly". The **document review** feature is **out of scope** for this plan; only the limit is stored on the package (`documents_limit`) for display. | ❓ Should clients upload documents for review? |
| A18 | Invoices/VAT | Out of scope. Prices are shown as final. | ❓ Is VAT (15%) included? Are invoices needed? |

---
## 5. Architecture and conventions

### 5.1 Modules (nwidart/laravel-modules)

Create exactly these modules. The order matters, because it is also the dependency order.

| # | Module | Owns (models) | Responsibility |
|---|---|---|---|
| 1 | `Core` | — | Shared code: API response trait, base controller, `BusinessException`, error codes, exception rendering, locale middleware, `Money` helper, shared enums, pagination helper. |
| 2 | `AccessControl` | (uses Spatie `Role`, `Permission`) | Permission registry, roles CRUD, permissions list, role→users, roles & permissions seeders. |
| 3 | `Users` | `User` | Admin/consultant authentication, users CRUD, assigning roles, profile for `users`. |
| 4 | `Consultants` | `ConsultantAvailability`, `ConsultantTimeOff` | Consultant CRUD (consultants are `User` with `type=consultant`), availability, time off, slot generation, public consultant listing. |
| 5 | `Clients` | `Client`, `ClientLocation` | Client authentication, client profile, locations, admin list of clients. |
| 6 | `Packages` | `Package`, `ClientSubscription` | Packages CRUD, public packages, subscriptions and quota. |
| 7 | `Payments` | `Payment`, `PaymentMethod` | Gateway interface + drivers (fake, moyasar), saved cards, charging, webhook, admin payments list. |
| 8 | `Bookings` | `Booking` | Booking wizard endpoints (quote/create), life cycle (complete/cancel), meeting provider (Google Meet), booking emails, scheduled expiry. |
| 9 | `Reports` | `Report` | Report upload/replace/download, report-ready email, signed download link. |
| 10 | `Dashboard` | — | Statistics endpoints. |

### 5.2 Folder layout inside every module (laravel-modules v13 default)

With laravel-modules v11 and newer, classes are inside `app/`, but the namespace does **not** contain `App`.
Example: the file `Modules/Bookings/app/Models/Booking.php` has the namespace `Modules\Bookings\Models`.

```
Modules/Bookings/
├── app/
│   ├── Actions/                 # single-purpose business actions (CreateBookingAction, ...)
│   ├── Enums/                   # BookingStatus, ReportStatus, ...
│   ├── Events/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/           # controllers for /api/v1/admin/*
│   │   │   └── Client/          # controllers for /api/v1/client/*
│   │   ├── Requests/
│   │   │   ├── Admin/
│   │   │   └── Client/
│   │   └── Resources/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Models/
│   ├── Notifications/
│   ├── Policies/
│   ├── Providers/
│   │   ├── BookingsServiceProvider.php
│   │   ├── EventServiceProvider.php
│   │   └── RouteServiceProvider.php
│   └── Services/
├── config/config.php
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── lang/ (ar, en)
├── routes/api.php
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
└── module.json
```

Delete the `routes/web.php`, `resources/views`, `vite.config.js` and `package.json` files the generator creates. This is an API-only project. Also remove the web route registration from each module's `RouteServiceProvider`.

### 5.3 URL structure

All routes start with `/api/v1`.

| Prefix | Middleware | Used by |
|---|---|---|
| `/api/v1/public/*` | `api`, `throttle:60,1` | Anyone (wizard step 1 and 3–4, packages, consultants, slots) |
| `/api/v1/admin/auth/*` | `api`, `throttle:10,1` for login | Dashboard A login, forgot/reset password |
| `/api/v1/admin/*` | `api`, `auth:admin`, `active.user` + `permission:<name>,admin` per route | Dashboard A |
| `/api/v1/client/auth/*` | `api`, `throttle:10,1` | Dashboard B register/login |
| `/api/v1/client/*` | `api`, `auth:client`, `active.client` | Dashboard B |
| `/api/v1/webhooks/*` | `api` (no auth; signature/secret check inside) | Payment gateway |

Route names use dots: `admin.bookings.index`, `client.bookings.store`, `public.packages.index`.

Every module's `routes/api.php` looks like this (example for Bookings):

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Bookings\Http\Controllers\Admin\BookingController as AdminBookingController;
use Modules\Bookings\Http\Controllers\Client\BookingController as ClientBookingController;

Route::prefix('v1')->group(function () {
    Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'active.user'])->group(function () {
        Route::get('bookings', [AdminBookingController::class, 'index'])
            ->middleware('permission:view-bookings,admin')->name('bookings.index');
        // ...
    });

    Route::prefix('client')->name('client.')->middleware(['auth:client', 'active.client'])->group(function () {
        Route::get('bookings', [ClientBookingController::class, 'index'])->name('bookings.index');
        // ...
    });
});
```

The module `RouteServiceProvider` already adds the `api` prefix and the `api` middleware. Check that the final URL is `/api/v1/...` with `php artisan route:list --path=api/v1`.

### 5.4 Response envelope (MUST be identical everywhere)

**Success (single object):**

```json
{
  "success": true,
  "message": "Booking created successfully.",
  "data": { "id": 15, "...": "..." }
}
```

**Success (paginated list):**

```json
{
  "success": true,
  "message": "OK",
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 42,
    "last_page": 3
  },
  "links": {
    "first": "http://localhost:8000/api/v1/admin/bookings?page=1",
    "last": "http://localhost:8000/api/v1/admin/bookings?page=3",
    "prev": null,
    "next": "http://localhost:8000/api/v1/admin/bookings?page=2"
  }
}
```

**Validation error (422):**

```json
{
  "success": false,
  "message": "The email field is required.",
  "error_code": "VALIDATION_ERROR",
  "errors": { "email": ["The email field is required."] }
}
```

**Business error (usually 422 or 409):**

```json
{
  "success": false,
  "message": "This time slot is no longer available.",
  "error_code": "SLOT_NOT_AVAILABLE",
  "errors": {}
}
```

**Other errors:**

| HTTP | error_code | When |
|---|---|---|
| 401 | `UNAUTHENTICATED` | No token, wrong token, or a token of the other guard |
| 403 | `FORBIDDEN` | Missing permission (Spatie `UnauthorizedException`) or a policy denied the action |
| 403 | `ACCOUNT_DISABLED` | `is_active = false` |
| 404 | `NOT_FOUND` | Model not found / route not found |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method |
| 429 | `TOO_MANY_REQUESTS` | Throttled |
| 500 | `SERVER_ERROR` | Anything else (hide details when `APP_DEBUG=false`) |

Implement it with:

- `Modules\Core\Traits\ApiResponse` with the methods `success($data = null, string $message = 'OK', int $status = 200)`, `created($data, $message)`, `paginated(ResourceCollection|LengthAwarePaginator $collection, string $message = 'OK')`, `noContent(string $message)` (returns **200** with `data: null`, not 204, so the envelope is always present).
- `Modules\Core\Http\Controllers\ApiController` (abstract, `use ApiResponse;`). Every controller extends it.
- Exception rendering in `bootstrap/app.php` → `->withExceptions(function (Exceptions $exceptions) { ... })`. Use `$exceptions->render(...)` for each exception type **only when** `$request->is('api/*')`. Also make `shouldRenderJsonWhen` return true for `api/*`.

### 5.5 Controller rules

- Controllers are **thin**: validate with a FormRequest → call a Service/Action → return a Resource inside the envelope.
- Never put queries with business rules in controllers. Put them in the model scopes or services.
- Use route model binding (`{booking}`), and use policies (`$this->authorize('view', $booking)`) for ownership.
  Add `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` to `ApiController` (Laravel 11+ removed it from the base controller).

### 5.6 Listing conventions (for every `index` endpoint)

| Query param | Meaning | Example |
|---|---|---|
| `page` | page number | `page=2` |
| `per_page` | 1–100, default 15 | `per_page=50` |
| `search` | free text search (fields are listed per endpoint) | `search=ahmad` |
| `sort` | field name; prefix `-` for descending. Only the fields listed per endpoint are allowed; otherwise use the default | `sort=-created_at` |
| filters | listed per endpoint | `status=pending&consultant_id=3&date_from=2026-09-01&date_to=2026-09-30` |

Implement one reusable helper: `Modules\Core\Support\QueryFilters::apply(Builder $query, Request $request, array $searchable, array $sortable, string $defaultSort = '-id')`.

### 5.7 Error codes (`Modules\Core\Enums\ErrorCode`, string-backed enum)

```
VALIDATION_ERROR, UNAUTHENTICATED, FORBIDDEN, ACCOUNT_DISABLED, NOT_FOUND, METHOD_NOT_ALLOWED,
TOO_MANY_REQUESTS, SERVER_ERROR,
INVALID_CREDENTIALS,
ROLE_PROTECTED,               // trying to edit/delete the "admin" role or delete the "consultant" role
ROLE_HAS_USERS,               // deleting a role that still has users
CANNOT_DELETE_SELF,
LAST_ADMIN,                   // removing the admin role from / deleting the last active admin
CONSULTANT_INACTIVE,
CONSULTANT_HAS_FUTURE_BOOKINGS,
CLIENT_HAS_FUTURE_BOOKINGS,
AVAILABILITY_OVERLAP,
SLOT_NOT_AVAILABLE,
PACKAGE_INACTIVE,
LOCATION_NOT_OWNED,
PAYMENT_METHOD_REQUIRED,
PAYMENT_METHOD_NOT_OWNED,
PAYMENT_FAILED,
PAYMENT_ALREADY_PROCESSED,
BOOKING_INVALID_STATUS,       // e.g. completing a cancelled booking
BOOKING_NOT_STARTED,          // completing before starts_at
BOOKING_CANCEL_WINDOW_PASSED,
REPORT_NOT_ALLOWED,           // uploading a report for a booking that is not completed
MEETING_CREATION_FAILED,
PACKAGE_HAS_SUBSCRIPTIONS
```

`BusinessException` constructor: `__construct(ErrorCode $code, ?string $message = null, int $status = 422)`. If `$message` is null, use `__('core::errors.' . $code->value)` (translate every code in `Modules/Core/lang/{ar,en}/errors.php`).

### 5.8 Other conventions

- **Enums:** PHP 8.1 backed enums, cast on the models (`protected function casts(): array`).
- **Money:** integer halalas in the DB. Resources return both: `"price": 190000, "price_formatted": "1,900.00 SAR"`. Helper: `Modules\Core\Support\Money::format(int $halalas, string $currency = 'SAR'): string`.
- **Dates in responses:** `starts_at` → ISO 8601 with offset (`2026-09-23T09:00:00+03:00`). Also return `date` (`2026-09-23`) and `time` (`09:00`) on bookings, for the UI.
- **Locale:** `Modules\Core\Http\Middleware\SetLocaleFromHeader` reads `Accept-Language` (`ar`|`en`, default `ar`). Add it to the `api` middleware group in `bootstrap/app.php`.
- **Translatable fields:** stored as `*_ar` and `*_en` columns. Resources return both **and** a `name` field in the current locale.
- **Soft deletes:** `users`, `clients`, `packages`, `bookings` use `SoftDeletes`.
- **Mass assignment:** every model defines `$fillable`. Never use `$guarded = []`.
- **Strictness:** in `AppServiceProvider::boot()` call `Model::shouldBeStrict(! app()->isProduction());`.
- **IDs:** auto-increment `bigint`. Also give `bookings` a unique `reference` (`BK-2026-000015`) to show to clients.

---

## 6. Authentication design (two guards)

### 6.1 Installation

```bash
php artisan install:api   # installs Sanctum, creates routes/api.php and the personal_access_tokens migration
```

### 6.2 `config/auth.php`

```php
'defaults' => [
    'guard' => env('AUTH_GUARD', 'admin'),
    'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
],

'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'admin' => ['driver' => 'sanctum', 'provider' => 'users'],
    'client' => ['driver' => 'sanctum', 'provider' => 'clients'],
],

'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => Modules\Users\Models\User::class],
    'clients' => ['driver' => 'eloquent', 'model' => Modules\Clients\Models\Client::class],
],

'passwords' => [
    'users' => [
        'provider' => 'users',
        'table' => 'password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
    'clients' => [
        'provider' => 'clients',
        'table' => 'client_password_reset_tokens',
        'expire' => 60,
        'throttle' => 60,
    ],
],
```

**Why this works:** Sanctum's guard checks that the token's owner (`tokenable`) is an instance of the guard provider's model. So a **client token on an admin route returns 401**, and an admin token on a client route also returns 401. There MUST be a feature test for both cases.

In `config/sanctum.php` set `'guard' => []` (token-only API, no session cookie fallback) and `'expiration' => null` (tokens do not expire; logout deletes them).

### 6.3 Models

```php
// Modules/Users/app/Models/User.php
class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, SoftDeletes;

    protected string $guard_name = 'admin';   // REQUIRED for Spatie with the sanctum guard
    // ...
}

// Modules/Clients/app/Models/Client.php
class Client extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;
    // Clients do NOT use Spatie roles. All clients have the same abilities.
}
```

Both models override `sendPasswordResetNotification($token)` so the email link points to the right frontend:

- users → `config('app.admin_frontend_url') . "/reset-password?token={$token}&email={$email}"`
- clients → `config('app.client_frontend_url') . "/reset-password?token={$token}&email={$email}"`

Add to `config/app.php`: `'admin_frontend_url' => env('ADMIN_FRONTEND_URL', 'http://localhost:3000')` and `'client_frontend_url' => env('CLIENT_FRONTEND_URL', 'http://localhost:3001')`.

### 6.4 Login response (both guards)

```json
{
  "success": true,
  "message": "Logged in successfully.",
  "data": {
    "token": "1|p8Yx....",
    "token_type": "Bearer",
    "user": { "...": "the same object as GET /me" }
  }
}
```

Token name: `admin-dashboard` or `client-dashboard`. Login accepts an optional `device_name`, which is used as the token name when it is present.

### 6.5 `GET /api/v1/admin/auth/me` (MUST exist, used by the frontend to manage permissions)

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "id": 1,
    "type": "admin",
    "name": "Super Admin",
    "email": "admin@gcmc.sa",
    "phone": "0550000000",
    "avatar_url": "http://localhost:8000/storage/1/avatar.jpg",
    "title": null,
    "specialization": null,
    "is_active": true,
    "roles": ["admin"],
    "permissions": ["view-users", "create-users", "..."],
    "created_at": "2026-09-23T14:00:00+03:00"
  }
}
```

`permissions` = `$user->getAllPermissions()->pluck('name')->values()` (direct permissions + permissions from roles). For the `admin` role this is the full list.

### 6.6 Middlewares (register the aliases in `bootstrap/app.php`)

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        'active.user' => \Modules\Users\Http\Middleware\EnsureUserIsActive::class,
        'active.client' => \Modules\Clients\Http\Middleware\EnsureClientIsActive::class,
    ]);
    $middleware->api(prepend: [\Modules\Core\Http\Middleware\SetLocaleFromHeader::class]);
})
```

(Check the Spatie v8 docs for the exact middleware class names. If they changed, use the new names.)

The `active.*` middleware returns 403 `ACCOUNT_DISABLED` when `is_active` is false. Login also refuses inactive accounts with the same code.

---

## 7. Roles and permissions catalogue

### 7.1 Single source of truth

Create `Modules\AccessControl\Support\PermissionRegistry` with a static method `all(): array` that returns permissions grouped by group. The seeder, the `GET /admin/permissions` endpoint and the tests all use this class. **Never hard-code permission names in more than one place**: use the constants/registry.

Naming: `{action}-{resource}` in kebab-case, plural resource.

| Group (`group` key) | Permissions |
|---|---|
| `dashboard` | `view-dashboard` |
| `roles` | `view-roles`, `create-roles`, `update-roles`, `delete-roles` |
| `permissions` | `view-permissions` |
| `users` | `view-users`, `create-users`, `update-users`, `delete-users`, `assign-roles` |
| `consultants` | `view-consultants`, `create-consultants`, `update-consultants`, `delete-consultants` |
| `availability` | `view-availability`, `manage-availability` |
| `clients` | `view-clients`, `update-clients`, `delete-clients` |
| `packages` | `view-packages`, `create-packages`, `update-packages`, `delete-packages` |
| `bookings` | `view-bookings`, `complete-bookings`, `cancel-bookings`, `manage-meetings` |
| `reports` | `view-reports`, `upload-reports`, `download-reports`, `delete-reports` |
| `payments` | `view-payments`, `refund-payments` |

Total: 34 permissions. All use `guard_name = 'admin'`.

The permissions table has an extra column `group` (added by our own migration, see 8.2), so the UI can show permissions grouped with check boxes.

### 7.2 Roles

| Role | guard | Permissions | Protected? |
|---|---|---|---|
| `admin` | admin | **all** (re-synced on every seeder run) | Yes: cannot be updated (name or permissions) or deleted → `ROLE_PROTECTED` |
| `consultant` | admin | see 7.3 | Its name cannot change and it cannot be deleted (`ROLE_PROTECTED`); its **permissions can be edited** |

Also register a `Gate::before` in `AccessControlServiceProvider::boot()`:

```php
Gate::before(fn ($user, $ability) => ($user instanceof User && $user->hasRole('admin')) ? true : null);
```

### 7.3 Default consultant permissions (editable later)

`view-dashboard`, `view-bookings`, `complete-bookings`, `cancel-bookings`, `view-reports`, `upload-reports`, `download-reports`, `view-clients`, `view-availability`, `manage-availability`

### 7.4 Seeders

- `RolesAndPermissionsSeeder` (AccessControl): runs `app()[PermissionRegistrar::class]->forgetCachedPermissions()`; `Permission::firstOrCreate(['name' => ..., 'guard_name' => 'admin'], ['group' => ...])` for every permission in the registry; deletes permissions that are no longer in the registry; creates the roles; `admin->syncPermissions(Permission::all())`; gives the consultant role its default permissions **only when it is first created** (so admin changes are kept).
- `SuperAdminSeeder` (Users): `User::firstOrCreate(['email' => env('SUPER_ADMIN_EMAIL', 'admin@gcmc.sa')], [... 'type' => 'admin', 'password' => env('SUPER_ADMIN_PASSWORD', 'Password@123'), 'is_active' => true])` → `assignRole('admin')`.
- Root `DatabaseSeeder` calls, in order: `RolesAndPermissionsSeeder`, `SuperAdminSeeder`, `PackagesSeeder`, and **only when not in production** `DemoDataSeeder` (consultants + availability + a demo client + locations; see 13, Phase 11).

---
## 8. Database schema

### 8.0 Migration order (IMPORTANT)

Laravel runs **all** migrations (root and modules) sorted by **file name**. Foreign keys need the referenced table to exist first, so use **exactly** these file names and prefixes:

| File (module) | Creates |
|---|---|
| `database/migrations/0001_01_01_000000_create_users_table.php` (root, **edit** the default one) | `users`, `password_reset_tokens`, `sessions` |
| `database/migrations/0001_01_01_000001_create_cache_table.php` (root default) | cache |
| `database/migrations/0001_01_01_000002_create_jobs_table.php` (root default) | jobs, job_batches, failed_jobs |
| `..._create_personal_access_tokens_table.php` (root, from `install:api`) | personal_access_tokens |
| `..._create_permission_tables.php` (root, published by Spatie) | roles, permissions, model_has_*, role_has_* |
| `..._create_media_table.php` (root, published by Media Library) | media |
| `..._create_notifications_table.php` (root, `php artisan make:notifications-table`) | notifications |
| `Modules/AccessControl/database/migrations/2026_01_01_000100_add_group_to_permissions_table.php` | `permissions.group` |
| `Modules/Consultants/database/migrations/2026_01_01_000200_create_consultant_availabilities_table.php` | |
| `Modules/Consultants/database/migrations/2026_01_01_000210_create_consultant_time_offs_table.php` | |
| `Modules/Clients/database/migrations/2026_01_01_000300_create_clients_table.php` | `clients`, `client_password_reset_tokens` |
| `Modules/Clients/database/migrations/2026_01_01_000310_create_client_locations_table.php` | |
| `Modules/Packages/database/migrations/2026_01_01_000400_create_packages_table.php` | |
| `Modules/Packages/database/migrations/2026_01_01_000410_create_client_subscriptions_table.php` | |
| `Modules/Payments/database/migrations/2026_01_01_000500_create_payment_methods_table.php` | |
| `Modules/Bookings/database/migrations/2026_01_01_000600_create_bookings_table.php` | |
| `Modules/Payments/database/migrations/2026_01_01_000700_create_payments_table.php` | (after bookings) |
| `Modules/Reports/database/migrations/2026_01_01_000800_create_reports_table.php` | |

The published vendor migrations get today's timestamp. If that timestamp is **later** than `2026_01_01`, rename the published files to `2025_12_31_0000xx_...` so they run before the module migrations. Check with `php artisan migrate:fresh --seed` (MySQL) **and** with the test suite (SQLite).

### 8.1 `users` (edit the root default migration)

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| type | string(20) | `admin` \| `consultant`, index, default `admin` |
| name | string(150) | required |
| email | string(191) | unique |
| phone | string(20) nullable | |
| title | string(150) nullable | consultant job title, for example "مستشار حوكمة" |
| specialization | string(150) nullable | for example "الحوكمة المؤسسية" |
| bio | text nullable | |
| is_active | boolean | default true, index |
| email_verified_at | timestamp nullable | |
| password | string | hashed (cast `'password' => 'hashed'`) |
| last_login_at | timestamp nullable | |
| remember_token | rememberToken | |
| timestamps, softDeletes | | |

Media collection on User: `avatar` (single file, disk `public`, mimes jpg/jpeg/png/webp, max 2 MB, conversion `thumb` 200×200 `nonQueued()`).

Model helpers: `isAdmin(): bool` (`type === UserType::Admin`), `isConsultant(): bool`, scope `consultants()`, scope `staff()` (type admin), scope `active()`, accessor `avatar_url` (`getFirstMediaUrl('avatar')` or `null`).

Relations (on User as consultant): `availabilities()` hasMany, `timeOffs()` hasMany, `consultantBookings()` hasMany(Booking, `consultant_id`), `reports()` hasMany(Report, `consultant_id`).

### 8.2 `permissions.group` (AccessControl)

`$table->string('group', 50)->nullable()->after('guard_name')->index();`

Use your own `Modules\AccessControl\Models\Permission extends Spatie\Permission\Models\Permission` (with `group` in `$fillable`), and set it in `config/permission.php` → `'models' => ['permission' => Modules\AccessControl\Models\Permission::class, 'role' => Modules\AccessControl\Models\Role::class]`. `Role` also extends the Spatie Role (it is used to add an `isProtected()` helper).

### 8.3 `consultant_availabilities`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| consultant_id | foreignId → users.id | cascadeOnDelete, index |
| day_of_week | unsignedTinyInteger | 0 = Sunday … 6 = Saturday (same as Carbon `dayOfWeek`) |
| start_time | time | for example `10:00:00` |
| end_time | time | greater than start_time |
| timestamps | | |

Index: (`consultant_id`, `day_of_week`). One day can have several rows (several ranges), and ranges on the same day MUST NOT overlap.

### 8.4 `consultant_time_offs`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| consultant_id | foreignId → users.id | cascadeOnDelete |
| date | date | index |
| start_time | time nullable | null together with end_time = the whole day |
| end_time | time nullable | |
| reason | string(255) nullable | |
| timestamps | | |

### 8.5 `clients`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| name | string(150) | contact person's full name ("Full Name" in the form) |
| email | string(191) | unique |
| phone | string(20) | required, Saudi format `05XXXXXXXX` (regex `^05\d{8}$`) |
| company_name | string(191) | required |
| is_active | boolean | default true |
| email_verified_at | timestamp nullable | |
| password | string | hashed |
| last_login_at | timestamp nullable | |
| remember_token | | |
| timestamps, softDeletes | | |

`client_password_reset_tokens`: `email` (primary string), `token` string, `created_at` timestamp nullable. Same structure as `password_reset_tokens`.

Media collection on Client: `avatar` (the same as User).

### 8.6 `client_locations`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| client_id | foreignId → clients.id | cascadeOnDelete |
| name | string(150) | for example "الفرع الرئيسي — الرياض" |
| city | string(100) nullable | |
| address | string(255) | for example "طريق العروبة، حي العليا، برج المملكة، الدور ١٨" |
| latitude | decimal(10,7) nullable | |
| longitude | decimal(10,7) nullable | |
| is_default | boolean | default false |
| timestamps, softDeletes | | |

### 8.7 `packages`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| slug | string(50) unique | `iron`, `silver`, `gold` |
| name_ar, name_en | string(100) | |
| description_ar, description_en | string(500) nullable | |
| features | json | array of `{ "ar": "...", "en": "..." }` |
| price | unsignedInteger | halalas |
| currency | string(3) | default `SAR` |
| billing_period_days | unsignedSmallInteger | default 30 |
| consultations_limit | unsignedSmallInteger nullable | null = unlimited |
| documents_limit | unsignedSmallInteger nullable | null = unlimited (display only, A17) |
| is_featured | boolean | default false ("Most distinguished" badge) |
| is_active | boolean | default true |
| sort_order | unsignedSmallInteger | default 0 |
| timestamps, softDeletes | | |

Seed data (`PackagesSeeder`, `updateOrCreate` by slug):

| slug | name_en / name_ar | price | consultations_limit | documents_limit | featured | features (en) |
|---|---|---|---|---|---|---|
| iron | Iron Package / الباقة الحديدية | 190000 | 2 | 2 | no | Two consultations monthly; Review two documents monthly; Support via email; Quarterly follow-up report |
| silver | Silver Package / الباقة الفضية | 450000 | 5 | 8 | no | 5 consultations monthly; Review up to 8 documents monthly; Support via phone and email; Monthly performance report; Quarterly training session |
| gold | Gold Package / الباقة الذهبية | 980000 | null | null | **yes** | Unlimited consultations; Unlimited document reviews; Dedicated advisor for your establishment; 24/7 support; Weekly performance reports; Attendance at board meetings |

Descriptions (en): Iron "For start-up establishments beginning their governance and compliance journey."; Silver "For growing establishments that need deeper consultancy support."; Gold "Comprehensive support for establishments aspiring to leadership." Write the Arabic versions too.

### 8.8 `client_subscriptions`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| client_id | foreignId → clients.id | cascadeOnDelete |
| package_id | foreignId → packages.id | restrictOnDelete |
| status | string(20) | `active` \| `expired` \| `cancelled`, index |
| starts_at | dateTime | |
| ends_at | dateTime | starts_at + billing_period_days |
| consultations_limit | unsignedSmallInteger nullable | a copy of the package value at purchase time |
| consultations_used | unsignedSmallInteger | default 0 |
| price_paid | unsignedInteger | halalas |
| timestamps | | |

Helpers: `isActive()` (status active and `now()` between starts_at and ends_at), `remaining(): ?int` (null = unlimited), `hasRemaining(): bool`.

### 8.9 `payment_methods`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| client_id | foreignId → clients.id | cascadeOnDelete |
| gateway | string(30) | `fake` \| `moyasar` |
| gateway_token | string(191) | the token from the gateway (never a card number). **Hidden** in JSON |
| brand | string(30) | `visa` \| `mastercard` \| `mada` \| `amex` |
| last_four | string(4) | |
| exp_month | unsignedTinyInteger | |
| exp_year | unsignedSmallInteger | |
| holder_name | string(150) nullable | |
| is_default | boolean | default false |
| timestamps, softDeletes | | |

Unique (`client_id`, `gateway`, `gateway_token`).

### 8.10 `bookings`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| reference | string(30) unique | `BK-{Y}-{id padded to 6}`, set in the model `created` event |
| client_id | foreignId → clients.id | restrictOnDelete |
| consultant_id | foreignId → users.id | restrictOnDelete |
| package_id | foreignId → packages.id | restrictOnDelete |
| client_subscription_id | foreignId nullable → client_subscriptions.id | nullOnDelete |
| client_location_id | foreignId nullable → client_locations.id | nullOnDelete |
| location_snapshot | json nullable | `{name, city, address}` copied at booking time |
| starts_at | dateTime | index |
| ends_at | dateTime | |
| status | string(30) | `pending_payment` \| `pending` \| `completed` \| `cancelled`, index |
| report_status | string(20) | `none` \| `pending` \| `uploaded`, default `none`, index |
| amount | unsignedInteger | halalas (0 when covered by a subscription) |
| currency | string(3) | `SAR` |
| payment_status | string(20) | `unpaid` \| `paid` \| `not_required` \| `failed` \| `refunded` |
| refund_status | string(20) | `none` \| `requested` \| `refunded`, default `none` |
| expires_at | dateTime nullable | only for `pending_payment` (A9) |
| meeting_provider | string(30) nullable | `google_meet` \| `fake` |
| meeting_status | string(20) | `none` \| `pending` \| `created` \| `failed`, default `none` |
| meeting_url | string(255) nullable | the Google Meet link |
| meeting_event_id | string(191) nullable | the Google Calendar event id |
| client_notes | text nullable | notes written by the client in the wizard (optional) |
| completed_at | dateTime nullable | |
| completion_notes | text nullable | optional notes when completing |
| completed_by | foreignId nullable → users.id | |
| cancelled_at | dateTime nullable | |
| cancelled_by_type | string(20) nullable | `user` \| `client` \| `system` |
| cancelled_by_id | unsignedBigInteger nullable | |
| cancellation_reason | string(500) nullable | |
| timestamps, softDeletes | | |

Indexes: (`consultant_id`, `starts_at`), (`client_id`, `status`), (`status`, `expires_at`).

Scopes: `blocking()` = `status = pending` OR (`status = pending_payment` AND `expires_at > now()`); `forConsultant($id)`; `forClient($id)`; `visibleTo(User $user)` (see 9.8); `upcoming()`; `filter(array $filters)`.

Relations: `client`, `consultant` (User), `package`, `subscription`, `location`, `payments` (hasMany), `latestPayment` (hasOne latestOfMany), `report` (hasOne).

### 8.11 `payments`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| booking_id | foreignId → bookings.id | cascadeOnDelete |
| client_id | foreignId → clients.id | |
| payment_method_id | foreignId nullable → payment_methods.id | nullOnDelete |
| gateway | string(30) | |
| gateway_payment_id | string(191) nullable | index |
| amount | unsignedInteger | halalas |
| currency | string(3) | |
| status | string(20) | `initiated` \| `paid` \| `failed` \| `refunded` |
| transaction_url | string(500) nullable | 3-D Secure redirect URL (when status = initiated) |
| failure_reason | string(500) nullable | |
| card_brand | string(30) nullable | |
| card_last_four | string(4) nullable | |
| gateway_response | json nullable | the raw response (**never** returned by the API) |
| paid_at | dateTime nullable | |
| timestamps | | |

### 8.12 `reports`

| Column | Type | Rules |
|---|---|---|
| id | bigIncrements | |
| booking_id | foreignId → bookings.id | unique, cascadeOnDelete |
| consultant_id | foreignId → users.id | |
| client_id | foreignId → clients.id | |
| title | string(191) | |
| summary | text nullable | |
| uploaded_by | foreignId → users.id | |
| client_notified_at | dateTime nullable | |
| first_downloaded_at | dateTime nullable | first download by the client |
| timestamps, softDeletes | | |

Media collection `report_file`: `singleFile()`, disk **`local`** (private, not public), mimes `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, max **20 MB**.

---
## 9. Business rules

### 9.1 Booking state machine

Enums (`Modules\Bookings\Enums`):

```php
enum BookingStatus: string { case PendingPayment = 'pending_payment'; case Pending = 'pending'; case Completed = 'completed'; case Cancelled = 'cancelled'; }
enum ReportStatus: string  { case None = 'none'; case Pending = 'pending'; case Uploaded = 'uploaded'; }
enum PaymentStatus: string { case Unpaid = 'unpaid'; case Paid = 'paid'; case NotRequired = 'not_required'; case Failed = 'failed'; case Refunded = 'refunded'; }
enum MeetingStatus: string { case None = 'none'; case Pending = 'pending'; case Created = 'created'; case Failed = 'failed'; }
enum RefundStatus: string  { case None = 'none'; case Requested = 'requested'; case Refunded = 'refunded'; }
```

Allowed transitions (anything else → `BusinessException(BOOKING_INVALID_STATUS)`):

| From | To | Trigger | Side effects |
|---|---|---|---|
| — | `pending_payment` | client creates a booking that needs payment | `expires_at = now + 15 min`, `payment_status = unpaid`, a `payments` row is created and the gateway is charged |
| — | `pending` | client creates a booking covered by an active subscription (amount 0) | `payment_status = not_required`, `subscription.consultations_used++`, meeting job dispatched |
| `pending_payment` | `pending` | payment becomes `paid` (synchronously, by the verify endpoint or by the webhook) | `payment_status = paid`, `expires_at = null`, subscription created (9.4), `consultations_used = 1`, `CreateMeetingJob` dispatched |
| `pending_payment` | `cancelled` | payment `failed` | `payment_status = failed`, `cancelled_by_type = system`, reason `payment_failed` |
| `pending_payment` | `cancelled` | `expires_at` passed (scheduled command) | `cancelled_by_type = system`, reason `payment_timeout` |
| `pending` | `completed` | user with `complete-bookings` (the consultant of the booking or an admin), **only if `now >= starts_at`** | `completed_at`, `completed_by`, `report_status = pending` |
| `pending` | `cancelled` | user with `cancel-bookings` (always allowed) or the client (only ≥ 24h before `starts_at`) | cancel details, give the consultation back (`consultations_used--`, not below 0), `refund_status = requested` if `payment_status = paid`, delete the meeting event (job), notify the client and the consultant |
| `completed` + `report_status=pending` | `completed` + `report_status=uploaded` | report uploaded | `ReportReadyNotification` to the client |

Put every transition in **one** class, `Modules\Bookings\Services\BookingStateMachine`, with the methods `markPaid(Booking, Payment)`, `markPaymentFailed(Booking, Payment, string $reason)`, `expire(Booking)`, `complete(Booking, User $by)`, `cancel(Booking, User|Client|null $by, ?string $reason)`, `markReportUploaded(Booking)`. Every method runs inside `DB::transaction` and re-reads the booking with `lockForUpdate()`. Unit tests for every transition and every forbidden transition are REQUIRED.

### 9.2 Availability rules

- The weekly schedule is replaced as a whole with `PUT .../availability` (easier for the UI than editing single rows).
- `start_time` and `end_time` use the `H:i` format and the minutes must be `00` or `30` (multiples of `BOOKING_SLOT_MINUTES`).
- `end_time > start_time`. The minimum range length = `BOOKING_DURATION_MINUTES`.
- Ranges in the same day must not overlap (touching is OK: 10:00–12:00 and 12:00–14:00). Otherwise → 422 `AVAILABILITY_OVERLAP`, with `errors` keyed like `days.0.ranges.1`.
- Changing availability does **not** cancel existing bookings. The response includes `warnings.conflicting_bookings_count` = the number of future `pending` bookings that are now outside the availability, so the admin knows.
- Time off: `date >= today`. If `start_time` is given then `end_time` is required, and the reverse. Time off does not cancel bookings either (the same warning is returned).

### 9.3 Slot generation algorithm (`Modules\Consultants\Services\SlotService`)

Public method: `getSlots(User $consultant, CarbonImmutable $date): array` returns a list of `['time' => '10:00', 'starts_at' => ISO, 'ends_at' => ISO]`.

```
INPUT: consultant, date (Asia/Riyadh)
CONFIG: step = booking.slot_minutes (30), duration = booking.duration_minutes (30),
        minNotice = booking.min_notice_minutes (60), maxAdvance = booking.max_advance_days (60)

1. If consultant is not active OR consultant.type != consultant → return [].
2. If date < today OR date > today + maxAdvance → return [].
3. ranges = availabilities where day_of_week = date.dayOfWeek, ordered by start_time.
4. If time off exists for date with start_time = null → return [] (whole day off).
5. offRanges = time offs for date with times.
6. busy = bookings of consultant where blocking() AND starts_at < endOfDay AND ends_at > startOfDay
          → list of [starts_at, ends_at].
7. earliest = now() + minNotice.
8. slots = []
   for each range in ranges:
       cursor = date at range.start_time
       while cursor + duration <= date at range.end_time:
           slotEnd = cursor + duration
           if cursor >= earliest
              and no overlap(cursor, slotEnd, offRanges)
              and no overlap(cursor, slotEnd, busy):
                  slots[] = cursor
           cursor = cursor + step
9. Return the slots sorted and without duplicates.

overlap(aStart, aEnd, bStart, bEnd) = aStart < bEnd AND bStart < aEnd
```

Also: `getAvailableDates(User $consultant, CarbonImmutable $month): array` returns the dates of that month (between today and the horizon) that have at least one slot. It is used by the date picker to disable empty days. Load all data for the month with **3 queries** (availabilities, time offs, bookings), not one query per day.

`isSlotAvailable(User $consultant, CarbonImmutable $startsAt): bool` = `in_array(startsAt->format('H:i'), getSlots(consultant, startsAt->startOfDay())->pluck('time'))`. It is used when the booking is created.

**Unit tests for `SlotService` (REQUIRED, use `Carbon::setTestNow()`):**
1. 10:00–12:00 → `10:00, 10:30, 11:00, 11:30`.
2. Two ranges 10:00–11:00 and 14:00–15:00 → 4 slots.
3. A booked 10:30 slot (status pending) is removed.
4. A `pending_payment` booking with `expires_at` in the future is removed; one with `expires_at` in the past is **not** removed.
5. A cancelled booking does not block.
6. A whole-day time off → empty.
7. A time off 11:00–12:00 → `10:00, 10:30`.
8. Today at 10:15 with 60-minute notice → the first slot is 11:30 (for a range 10:00–12:00: 11:30 only).
9. A date in the past → empty; a date after the horizon → empty.
10. A day with no availability → empty.
11. An inactive consultant → empty.
12. Duration 60 and step 30, range 10:00–12:00 → `10:00, 10:30, 11:00` (change the config inside the test).

### 9.4 Pricing and subscriptions (`Modules\Packages\Services\SubscriptionService`)

`quote(Client $client, Package $package): array`:

```
active = client subscriptions where package_id = package.id AND status = active
         AND starts_at <= now AND ends_at > now, latest first
if active exists AND active.hasRemaining():
    return [ requires_payment: false, amount: 0, subscription: active, remaining_before: active.remaining() ]
else:
    return [ requires_payment: true, amount: package.price, subscription: null ]
```

`activateFromPayment(Client, Package, Payment): ClientSubscription` creates a subscription with `starts_at = now`, `ends_at = now + billing_period_days`, `consultations_limit = package.consultations_limit`, `consultations_used = 0`, `price_paid = payment.amount`. (The state machine then increments `consultations_used` to 1 for the booking that paid.)

`consume(ClientSubscription)` does `consultations_used++` with `lockForUpdate` and throws if nothing is left. `release(ClientSubscription)` does `consultations_used--` (not below 0).

Scheduled command `subscriptions:expire` (daily, 00:05): sets `status = expired` where `ends_at <= now` and status is active.

### 9.5 Booking creation flow (`Modules\Bookings\Actions\CreateBookingAction`)

Input (validated in `StoreBookingRequest`):

```
package_id          required, exists, active
consultant_id       required, exists in users where type=consultant and is_active
date                required, date_format:Y-m-d, after_or_equal:today
time                required, date_format:H:i
client_location_id  required, exists and belongs to the client
payment_method_id   nullable, exists and belongs to the client
card_token          nullable, string       (a new card tokenized by the frontend)
save_card           boolean, default false (only with card_token)
client_notes        nullable, string, max:1000
```

Steps:

```
1. DB::transaction:
   a. Lock the consultant row: User::whereKey(consultant_id)->lockForUpdate()->first()
      (this serializes concurrent bookings for the same consultant → no double booking)
   b. startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', "$date $time", 'Asia/Riyadh')
   c. If !SlotService->isSlotAvailable(consultant, startsAt) → BusinessException(SLOT_NOT_AVAILABLE, status 409)
   d. quote = SubscriptionService->quote(client, package)
   e. If quote.requires_payment AND no payment_method_id AND no card_token → BusinessException(PAYMENT_METHOD_REQUIRED)
   f. Create the booking:
        status = requires_payment ? pending_payment : pending
        payment_status = requires_payment ? unpaid : not_required
        amount = quote.amount, expires_at = requires_payment ? now + hold : null
        location_snapshot = the location's name/city/address
        client_subscription_id = quote.subscription?.id
   g. If NOT requires_payment: SubscriptionService->consume(subscription)
2. After commit:
   - If NOT requires_payment → dispatch CreateMeetingJob(booking) and return the booking (status pending).
   - If requires_payment → PaymentService->chargeBooking(booking, paymentMethod|cardToken, saveCard)
       result paid      → BookingStateMachine->markPaid()   → response status "pending"
       result initiated → return the booking + payment.transaction_url (the frontend redirects for 3-D Secure)
       result failed    → BookingStateMachine->markPaymentFailed() → throw BusinessException(PAYMENT_FAILED, 402)
```

Response of `POST /client/bookings` (201):

```json
{
  "success": true,
  "message": "Booking created successfully.",
  "data": {
    "booking": { "...BookingResource..." },
    "payment": {
      "id": 9,
      "status": "initiated",
      "requires_action": true,
      "transaction_url": "https://api.moyasar.com/v1/transaction_auths/..../form"
    }
  }
}
```

`payment` is `null` when payment is not required. When the payment is `paid`, `requires_action` is `false` and `transaction_url` is `null`.

### 9.6 Payment flow (`Modules\Payments`)

**Interface:**

```php
namespace Modules\Payments\Contracts;

interface PaymentGateway
{
    /** Charge an amount using a token (saved card token or a one-time token from the frontend). */
    public function charge(ChargeRequest $request): ChargeResult;

    /** Fetch the latest status of a payment from the gateway (used by verify + webhook). */
    public function fetch(string $gatewayPaymentId): ChargeResult;

    /** Read card details for a token so we can save it as a payment method. */
    public function tokenDetails(string $token): CardDetails;

    public function name(): string; // 'fake' | 'moyasar'
}
```

DTOs (readonly classes in `Modules\Payments\DTO`):

- `ChargeRequest(int $amount, string $currency, string $token, string $description, string $callbackUrl, array $metadata)`
- `ChargeResult(string $status /* initiated|paid|failed */, ?string $gatewayPaymentId, ?string $transactionUrl, ?string $failureReason, ?string $cardBrand, ?string $cardLastFour, array $raw)`
- `CardDetails(string $brand, string $lastFour, int $expMonth, int $expYear, ?string $holderName)`

**Drivers:**

- `FakeGateway` (default in `.env.example` and **always** in tests). Behaviour depends on the token:
  - `tok_fake_success` or any token that starts with `tok_fake_ok` → `paid`
  - `tok_fake_3ds` → `initiated` with `transactionUrl = config('app.url') . '/fake-3ds/{id}'`; `fetch()` then returns `paid`
  - `tok_fake_declined` → `failed` with the reason `"Card declined"`
  - `tokenDetails('tok_fake_success')` → `visa`, `4242`, `12`, `now()->year + 2`, `"Test Card"`
- `MoyasarGateway` (uses the `Http` facade, basic auth with the secret key):
  - charge → `POST https://api.moyasar.com/v1/payments` with `amount`, `currency`, `description`, `callback_url`, `source: { type: "token", token }`, `metadata`.
  - fetch → `GET /v1/payments/{id}`; map Moyasar `status`: `paid` → paid, `initiated` → initiated, `failed` → failed. `source.transaction_url` → transactionUrl.
  - tokenDetails → `GET /v1/tokens/{id}` (brand, last_four, month, year, name).
  - Test it with `Http::fake()` only. Never call the real API in tests.
- Bind with `config('payments.driver')` in `PaymentsServiceProvider::register()`:
  `$this->app->bind(PaymentGateway::class, fn () => match (config('payments.driver')) { 'moyasar' => app(MoyasarGateway::class), default => app(FakeGateway::class) });`

**Config `Modules/Payments/config/config.php`:**

```php
return [
    'driver' => env('PAYMENT_GATEWAY', 'fake'),
    'currency' => 'SAR',
    'callback_url' => env('PAYMENT_CALLBACK_URL', env('CLIENT_FRONTEND_URL', 'http://localhost:3001').'/bookings/payment-callback'),
    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'base_url' => 'https://api.moyasar.com/v1',
    ],
];
```

**`PaymentService::chargeBooking(Booking $booking, ?PaymentMethod $method, ?string $cardToken, bool $saveCard): Payment`:**

1. If `$method` is given, check it belongs to the booking's client (else `PAYMENT_METHOD_NOT_OWNED`) and use `$method->gateway_token`.
2. If `$cardToken` is given and `$saveCard` is true → `PaymentMethodService::storeFromToken($client, $cardToken)` first, then charge with that method.
3. Create a `payments` row with status `initiated`.
4. Call `gateway->charge()`. Store the result on the payment (status, gateway ids, card brand/last4, raw response).
5. If `paid` → `paid_at = now`.

**Verify endpoint** `POST /client/payments/{payment}/verify` (after the 3-D Secure redirect): owner check → if the payment is already `paid`/`failed` return it as it is (idempotent) → `gateway->fetch()` → update → if paid call `markPaid`, if failed call `markPaymentFailed`. Return the booking + payment.

**Webhook** `POST /api/v1/webhooks/payments/moyasar`: check `secret_token` in the body equals `config('payments.moyasar.webhook_secret')` (else 401) → find the payment by `gateway_payment_id` → the same update logic as verify (idempotent: if it is already processed, return 200 and do nothing). Always return `200 {"success": true}`.

**Race rule:** if a payment becomes `paid` **after** the booking already expired or was cancelled, set the booking `refund_status = requested` and log a warning. Do not revive the booking (the slot may be taken).

### 9.7 Meeting creation (`Modules\Bookings\Contracts\MeetingProvider`)

```php
interface MeetingProvider
{
    /** @return MeetingResult with eventId and joinUrl */
    public function create(Booking $booking): MeetingResult;
    public function cancel(Booking $booking): void;
    public function name(): string;
}
```

- `FakeMeetingProvider`: returns `eventId = 'fake-'.Str::uuid()`, `joinUrl = 'https://meet.google.com/fak-'.Str::lower(Str::random(4)).'-'.Str::lower(Str::random(3))`.
- `GoogleMeetProvider` (`google/apiclient`):

```php
$client = new \Google\Client();
$client->setAuthConfig(config('bookings.google.credentials_path'));   // service-account JSON
$client->setScopes([\Google\Service\Calendar::CALENDAR]);
$client->setSubject(config('bookings.google.impersonate'));          // e.g. bookings@gcmc.sa
$service = new \Google\Service\Calendar($client);

$event = new \Google\Service\Calendar\Event([
    'summary' => "GCMC Consultation {$booking->reference} - {$booking->client->company_name}",
    'description' => "Consultant: {$booking->consultant->name}\nPackage: {$booking->package->name_en}",
    'start' => ['dateTime' => $booking->starts_at->toRfc3339String(), 'timeZone' => 'Asia/Riyadh'],
    'end' => ['dateTime' => $booking->ends_at->toRfc3339String(), 'timeZone' => 'Asia/Riyadh'],
    'attendees' => [['email' => $booking->client->email], ['email' => $booking->consultant->email]],
    'conferenceData' => ['createRequest' => [
        'requestId' => (string) Str::uuid(),
        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
    ]],
]);
$created = $service->events->insert(config('bookings.google.calendar_id', 'primary'), $event, [
    'conferenceDataVersion' => 1,
    'sendUpdates' => 'all',
]);
return new MeetingResult($created->getId(), $created->getHangoutLink());
```

- Config `Modules/Bookings/config/config.php`:

```php
return [
    'slot_minutes' => (int) env('BOOKING_SLOT_MINUTES', 30),
    'duration_minutes' => (int) env('BOOKING_DURATION_MINUTES', 30),
    'min_notice_minutes' => (int) env('BOOKING_MIN_NOTICE_MINUTES', 60),
    'max_advance_days' => (int) env('BOOKING_MAX_ADVANCE_DAYS', 60),
    'payment_hold_minutes' => (int) env('BOOKING_PAYMENT_HOLD_MINUTES', 15),
    'client_cancel_hours' => (int) env('BOOKING_CLIENT_CANCEL_HOURS', 24),
    'meeting_driver' => env('MEETING_DRIVER', 'fake'),   // fake | google
    'google' => [
        'credentials_path' => env('GOOGLE_SERVICE_ACCOUNT_JSON', storage_path('app/private/google-service-account.json')),
        'impersonate' => env('GOOGLE_CALENDAR_IMPERSONATE'),
        'calendar_id' => env('GOOGLE_CALENDAR_ID', 'primary'),
    ],
];
```

  The slot config lives in the Bookings module, but `SlotService` is in the Consultants module and reads `config('bookings.*')`. This is fine (Consultants depends on the config only).

- `CreateMeetingJob` (queued, `tries = 3`, `backoff = [60, 300, 900]`): sets `meeting_status = pending` → calls the provider → saves `meeting_url`, `meeting_event_id`, `meeting_provider`, `meeting_status = created` → sends `BookingConfirmedNotification` to the client and `NewBookingNotification` to the consultant. In `failed()`: `meeting_status = failed` and send the confirmation email **without** a link ("the link will be sent later"). An admin can retry with `POST /admin/bookings/{id}/meeting`.
- `CancelMeetingJob`: calls `provider->cancel()`; errors are logged and ignored.

### 9.8 Data visibility (scoping) — MUST be applied on every admin endpoint that returns bookings/clients/reports/payments

```php
// Booking::scopeVisibleTo(Builder $q, User $user)
if ($user->isConsultant()) { $q->where('consultant_id', $user->id); }
// admins (type=admin) see everything.

// Report::scopeVisibleTo → the same with reports.consultant_id
// Client::scopeVisibleTo(User $user) → consultants: whereHas('bookings', fn ($b) => $b->where('consultant_id', $user->id))
// Payment::scopeVisibleTo → consultants: whereHas('booking', fn ($b) => $b->where('consultant_id', $user->id))
```

Policies (`BookingPolicy`, `ReportPolicy`, `ClientPolicy`) check the same rule for single records → **403** if a consultant opens another consultant's record.

A consultant is **never** allowed on `/admin/consultants/*` for another consultant, even with the `view-consultants` permission (`ConsultantPolicy::view` returns `$user->isAdmin() || $user->id === $consultant->id`). His own data is available at `/admin/profile`, `/admin/my/availability`, `/admin/my/time-offs`, and through the scoped `/admin/bookings`, `/admin/reports`, `/admin/clients`.

### 9.9 Report flow (`Modules\Reports\Actions\UploadReportAction`)

1. The booking must be `completed` (else `REPORT_NOT_ALLOWED`). The user must pass `BookingPolicy::uploadReport` (admin, or the booking's consultant) and have the `upload-reports` permission.
2. `Report::updateOrCreate(['booking_id' => ...], [...])` → `addMedia($file)->toMediaCollection('report_file')` (the single-file collection replaces the old file).
3. `BookingStateMachine::markReportUploaded()` → `report_status = uploaded`.
4. Send `ReportReadyNotification` to the client (queued) → set `client_notified_at`. On a **replace**, send it again only if the request has `notify_client = true` (default true).
5. The email contains:
   - A button link to the client dashboard: `{CLIENT_FRONTEND_URL}/reports/{report_id}`
   - A direct download link valid for 7 days: `URL::temporarySignedRoute('public.reports.signed-download', now()->addDays(7), ['report' => $id])`

**Download** endpoints stream the file: `return response()->download($media->getPath(), $media->file_name)` (or `$media->toResponse($request)`). The header `Content-Disposition: attachment` is REQUIRED. The first client download sets `first_downloaded_at`.

### 9.10 Users and consultants management rules

- Creating a **user** from the Users page: `type = admin` by default. The request may send `type: consultant`; then the `consultant` role is added automatically as well.
- Creating a **consultant** from the Consultants page: `type = consultant` + role `consultant` automatically (you cannot remove it through the consultant endpoints).
- `PUT /admin/users/{id}/roles` → `syncRoles($roles)`. If the user is a consultant, `consultant` is always kept in the list.
- Nobody can delete himself (`CANNOT_DELETE_SELF`) or deactivate himself.
- The last active user with the `admin` role cannot be deleted, deactivated, or lose the `admin` role (`LAST_ADMIN`).
- Deleting a consultant that has future `pending` bookings → `CONSULTANT_HAS_FUTURE_BOOKINGS` (409). The admin must cancel them first.
- Deactivating a user deletes all his tokens (`$user->tokens()->delete()`).
- Deleting a role with users → `ROLE_HAS_USERS` (409).
- Whenever roles/permissions change, Spatie clears its cache automatically; the tests must still call `forgetCachedPermissions()` in `setUp`.

### 9.11 Notifications (all `ShouldQueue`, mail channel + database channel)

| Notification | To | When | Content |
|---|---|---|---|
| `ClientWelcomeNotification` | client | registered | welcome text + dashboard link |
| `StaffAccountCreatedNotification` | user/consultant | created by an admin | login email + "set your password" link (password broker token) |
| `ResetPasswordNotification` (custom, one per guard) | user/client | forgot password | frontend reset link |
| `BookingConfirmedNotification` | client | booking becomes `pending` + meeting created (or failed) | reference, consultant, date, time (Riyadh), package, location, **Google Meet link**, amount paid |
| `NewBookingNotification` | consultant | the same moment | client company, date, time, Meet link |
| `BookingCancelledNotification` | client + consultant | cancelled (not by payment timeout) | reference, reason |
| `PaymentFailedNotification` | client | payment failed | retry link to the wizard |
| `ReportReadyNotification` | client | report uploaded | dashboard link + 7-day signed download link |

Mail templates: Markdown mail components, in Arabic by default (`->locale('ar')`), with the company name and logo.

---
## 10. API catalogue

### 10.0 Common rules for all endpoints

- Headers sent by the frontend: `Accept: application/json`, `Accept-Language: ar|en`, `Authorization: Bearer {token}` (for protected routes).
- **File uploads** use `multipart/form-data`. PHP does not read multipart bodies on `PUT`, so all endpoints that take files are `POST` (separate `/avatar` endpoints for updates).
- "Perm" = the Spatie permission checked by the route middleware (`permission:<perm>,admin`). "Policy" = the extra ownership check.
- Unless stated otherwise, a list returns the paginated envelope (5.4) and a single item returns `data: {Resource}`.
- Every endpoint below has an ID (for example `B-03`). Use the ID in test method names and in the Postman request descriptions, so they are easy to find.

### 10.1 API Resources (output shapes). Build them exactly like this

**`UserResource`** (Users) — used for users and for `me`:

```json
{
  "id": 1, "type": "admin", "name": "Super Admin", "email": "admin@gcmc.sa", "phone": "0550000000",
  "title": null, "specialization": null, "bio": null,
  "avatar_url": null, "avatar_thumb_url": null,
  "is_active": true, "last_login_at": "2026-09-23T14:00:00+03:00",
  "roles": ["admin"],                  // names, always loaded
  "permissions": ["..."],               // ONLY in /me and /profile responses (use $this->when)
  "created_at": "2026-09-01T10:00:00+03:00", "updated_at": "..."
}
```

**`ConsultantResource`** (Consultants) — admin side:

```json
{
  "id": 5, "name": "أ. أحمد العتيبي", "email": "ahmad@gcmc.sa", "phone": "0551111111",
  "title": "مستشار حوكمة", "specialization": "الحوكمة المؤسسية", "bio": "...",
  "avatar_url": "...", "avatar_thumb_url": "...", "is_active": true,
  "roles": ["consultant"],
  "stats": { "pending_bookings": 3, "completed_bookings": 10, "pending_reports": 1, "reports": 9, "clients": 7 },  // only when withCount was loaded
  "working_days": [0, 1, 2, 3, 4],     // distinct day_of_week values, when availabilities are loaded
  "created_at": "..."
}
```

**`PublicConsultantResource`** (for the wizard; NO email/phone): `id, name, title, specialization, bio, avatar_url, avatar_thumb_url, working_days`.

**`AvailabilityResource`** (the weekly schedule, always 7 items, Sunday first):

```json
{
  "days": [
    { "day_of_week": 0, "day_name": "Sunday", "day_name_ar": "الأحد", "is_working": true,
      "ranges": [ { "id": 11, "start_time": "10:00", "end_time": "12:00" }, { "id": 12, "start_time": "14:00", "end_time": "16:00" } ] },
    { "day_of_week": 1, "day_name": "Monday", "day_name_ar": "الاثنين", "is_working": false, "ranges": [] }
  ]
}
```

**`TimeOffResource`**: `id, date, start_time|null, end_time|null, is_full_day, reason, created_at`.

**`SlotResource`**: `{ "time": "10:30", "starts_at": "2026-09-23T10:30:00+03:00", "ends_at": "2026-09-23T11:00:00+03:00" }`.

**`ClientResource`**: `id, name, email, phone, company_name, avatar_url, is_active, last_login_at, created_at` + (admin side, when counted) `bookings_count, reports_count` + (when loaded) `active_subscription: SubscriptionResource|null`.

**`LocationResource`**: `id, name, city, address, latitude, longitude, is_default, created_at`.

**`PackageResource`**:

```json
{
  "id": 1, "slug": "iron", "name": "الباقة الحديدية", "name_ar": "الباقة الحديدية", "name_en": "Iron Package",
  "description": "...", "description_ar": "...", "description_en": "...",
  "features": [ { "ar": "استشارتان شهرياً", "en": "Two consultations monthly" } ],
  "features_localized": ["استشارتان شهرياً", "..."],
  "price": 190000, "price_formatted": "1,900.00 SAR", "currency": "SAR",
  "billing_period_days": 30, "consultations_limit": 2, "documents_limit": 2,
  "is_unlimited": false, "is_featured": false, "is_active": true, "sort_order": 1
}
```

**`SubscriptionResource`**: `id, package: PackageResource(min: id, slug, name, name_ar, name_en), status, starts_at, ends_at, consultations_limit, consultations_used, consultations_remaining (null = unlimited), is_unlimited, price_paid, price_paid_formatted`.

**`PaymentMethodResource`**: `id, brand, last_four, exp_month, exp_year, holder_name, is_default, is_expired, display ("Visa •••• 4242"), created_at`. **Never** return `gateway_token`.

**`PaymentResource`**: `id, booking_id, booking_reference, amount, amount_formatted, currency, status, gateway, card_brand, card_last_four, failure_reason, transaction_url (only when status=initiated), paid_at, created_at` + admin side: `client: {id, name, company_name}`.

**`BookingResource`**:

```json
{
  "id": 15, "reference": "BK-2026-000015",
  "status": "pending", "status_label": "قيد الانتظار",
  "report_status": "none", "payment_status": "paid", "refund_status": "none",
  "date": "2026-09-23", "time": "09:00", "starts_at": "2026-09-23T09:00:00+03:00", "ends_at": "2026-09-23T09:30:00+03:00",
  "duration_minutes": 30,
  "amount": 190000, "amount_formatted": "1,900.00 SAR", "currency": "SAR",
  "package": { "id": 1, "slug": "iron", "name": "...", "name_ar": "...", "name_en": "Iron Package" },
  "consultant": { "id": 5, "name": "...", "title": "...", "specialization": "...", "avatar_thumb_url": "..." },
  "client": { "id": 3, "name": "...", "company_name": "...", "email": "...", "phone": "..." },
  "location": { "id": 2, "name": "الفرع الرئيسي — الرياض", "city": "الرياض", "address": "..." },
  "meeting": { "provider": "google_meet", "status": "created", "url": "https://meet.google.com/abc-defg-hij" },
  "payment": { "...PaymentResource (latest)..." },
  "report": { "id": 4, "title": "...", "file_name": "report.pdf", "uploaded_at": "..." },
  "client_notes": null,
  "can": { "complete": false, "cancel": true, "upload_report": false },
  "expires_at": null, "completed_at": null, "cancelled_at": null, "cancellation_reason": null,
  "created_at": "..."
}
```

- `location` comes from `location_snapshot` (so it still shows after the location is deleted).
- `can` is calculated for the **current** user/client (admin side: permission + policy + state; client side: only `cancel`).
- `meeting.url` is returned only when `status` is `pending` or `completed`.

**`ReportResource`**:

```json
{
  "id": 4, "title": "Governance assessment report", "summary": "...",
  "booking": { "id": 15, "reference": "BK-2026-000015", "date": "2026-09-23", "time": "09:00" },
  "consultant": { "id": 5, "name": "..." },
  "client": { "id": 3, "name": "...", "company_name": "..." },
  "file": { "name": "report.pdf", "size": 204800, "size_human": "200 KB", "mime_type": "application/pdf" },
  "download_url": "http://localhost:8000/api/v1/admin/reports/4/download",   // guard-specific URL
  "client_notified_at": "...", "first_downloaded_at": null, "created_at": "...", "updated_at": "..."
}
```

**`RoleResource`**: `id, name, guard_name, is_protected, permissions_count, users_count, permissions (array of PermissionResource, only on show), created_at`.

**`PermissionResource`**: `id, name, group, label` (`label` = translated from `accesscontrol::permissions.{name}`).

---
### 10.2 Public endpoints (no auth) — module noted in brackets

#### PUB-01 `GET /api/v1/public/packages` [Packages]
- Returns the active packages ordered by `sort_order`. **Not paginated** (`data` is an array).
- Tests: returns only active packages in the right order; inactive packages are hidden; the response has the `price_formatted` and `features` keys.

#### PUB-02 `GET /api/v1/public/packages/{slug}` [Packages]
- Finds by `slug`. 404 if it is not found or inactive.
- Tests: success; 404 for unknown; 404 for inactive.

#### PUB-03 `GET /api/v1/public/consultants` [Consultants]
- Query: `search` (name, title, specialization), `specialization`, `per_page` (default 12).
- Only `type=consultant`, `is_active=true`, and with at least one availability row. `PublicConsultantResource`, paginated.
- Tests: hides inactive consultants; hides admins; search by name works; search by specialization works; email/phone are NOT in the response.

#### PUB-04 `GET /api/v1/public/consultants/{consultant}` [Consultants]
- 404 if the user is not an active consultant.

#### PUB-05 `GET /api/v1/public/consultants/{consultant}/available-dates?month=2026-09` [Consultants]
- `month` required, `date_format:Y-m`.
- Response: `{ "month": "2026-09", "dates": ["2026-09-23", "2026-09-24"] }`.
- Tests: only dates with slots; past dates excluded; validation 422 for a bad month.

#### PUB-06 `GET /api/v1/public/consultants/{consultant}/slots?date=2026-09-23` [Consultants]
- `date` required, `date_format:Y-m-d`.
- Response: `{ "date": "2026-09-23", "timezone": "Asia/Riyadh", "slot_minutes": 30, "duration_minutes": 30, "slots": [SlotResource...] }`.
- Tests: the 10:00–12:00 example returns the 4 slots; booked slots are hidden; 422 without a date; 404 for an unknown consultant.

#### PUB-07 `GET /api/v1/public/reports/{report}/signed-download` [Reports]
- Middleware `signed` (route name `public.reports.signed-download`). Streams the file and sets `first_downloaded_at` if it is empty.
- Tests: a valid signature downloads the file (200 + `Content-Disposition` attachment); an invalid/expired signature → 403.

#### PUB-08 `GET /api/v1/public/meta` [Core]
- Returns the enum values and labels for the frontend: `booking_statuses`, `report_statuses`, `payment_statuses`, `days_of_week`, and the `booking` config (`slot_minutes`, `duration_minutes`, `max_advance_days`, `min_notice_minutes`, `client_cancel_hours`), plus `payment_gateway` (`driver` and the `publishable_key`).
- Tests: the structure has all keys.

### 10.3 Admin dashboard — authentication [Users]

#### ADM-AUTH-01 `POST /api/v1/admin/auth/login`
- Body: `email` required|email, `password` required|string, `device_name` nullable|string|max:100.
- Wrong credentials → 422 `INVALID_CREDENTIALS` (with `errors.email`). Inactive → 403 `ACCOUNT_DISABLED`. Sets `last_login_at`.
- Response: 6.4.
- Tests: success returns a token + roles + permissions; wrong password; unknown email; inactive user; validation; throttled after 10 attempts (429); **a client's credentials cannot log in here**.

#### ADM-AUTH-02 `POST /api/v1/admin/auth/logout` — auth:admin
- Deletes the current token only. Response `data: null`, message "Logged out successfully.".
- Tests: the token cannot be used after logout (401); 401 without a token.

#### ADM-AUTH-03 `GET /api/v1/admin/auth/me` — auth:admin
- Response: 6.5 (`UserResource` + `permissions`).
- Tests: the admin gets all 34 permissions; a consultant gets exactly the consultant permissions; **a client token → 401**.

#### ADM-AUTH-04 `POST /api/v1/admin/auth/forgot-password`
- Body: `email` required|email. Always returns 200 with the same message (do not reveal whether the email exists). Uses `Password::broker('users')->sendResetLink()`.
- Tests: `Notification::fake()` → sent to an existing user; not sent to an unknown email; the response is the same in both cases.

#### ADM-AUTH-05 `POST /api/v1/admin/auth/reset-password`
- Body: `token`, `email`, `password` (min 8, mixed case, numbers), `password_confirmation`. Deletes all old tokens of the user.
- Tests: a valid token resets the password (can log in with the new one); an invalid token → 422.

### 10.4 Admin dashboard — profile [Users] (auth:admin, no permission needed)

| ID | Method & URL | Body | Notes |
|---|---|---|---|
| ADM-PRF-01 | `GET /api/v1/admin/profile` | — | `UserResource` + `permissions` |
| ADM-PRF-02 | `PUT /api/v1/admin/profile` | `name` required, `phone` nullable, `email` required unique (ignore self); consultants also: `title`, `specialization`, `bio` | |
| ADM-PRF-03 | `POST /api/v1/admin/profile/avatar` | multipart `avatar` required image mimes:jpg,jpeg,png,webp max:2048 | Replaces the old avatar |
| ADM-PRF-04 | `DELETE /api/v1/admin/profile/avatar` | — | |
| ADM-PRF-05 | `PUT /api/v1/admin/profile/password` | `current_password` required current_password:admin, `password` confirmed Password::defaults() | Deletes the **other** tokens, keeps the current one |

Tests for each: success, validation, 401. For the avatar: `Storage::fake('public')`, check `getFirstMedia('avatar')` exists and that a PDF is rejected. For the password: a wrong current password → 422.

### 10.5 Access control [AccessControl] (auth:admin)

#### ACL-01 `GET /api/v1/admin/permissions` — Perm: `view-roles|view-permissions`
- Not paginated. Grouped:

```json
{ "success": true, "message": "OK", "data": [
  { "group": "users", "label": "المستخدمون", "permissions": [ { "id": 7, "name": "view-users", "group": "users", "label": "عرض المستخدمين" } ] }
] }
```
- Tests: the count equals `PermissionRegistry` count; 403 for a consultant without permission.

#### ACL-02 `GET /api/v1/admin/roles` — Perm: `view-roles`
- Query: `search` (name), `per_page`. `withCount(['permissions', 'users'])`.
- Tests: the list includes `admin` and `consultant` with correct counts.

#### ACL-03 `POST /api/v1/admin/roles` — Perm: `create-roles`
- Body: `name` required|string|max:100|unique:roles,name (for guard admin)|regex:`/^[a-z0-9-]+$/`, `permissions` required|array|min:1, `permissions.*` string|exists:permissions,name (guard admin).
- Creates the role with `guard_name=admin` and `syncPermissions`. 201.
- Tests: success with 3 permissions; duplicate name 422; unknown permission 422; empty permissions 422; 403 without permission.

#### ACL-04 `GET /api/v1/admin/roles/{role}` — Perm: `view-roles`
- `RoleResource` with the `permissions` list.

#### ACL-05 `PUT /api/v1/admin/roles/{role}` — Perm: `update-roles`
- Body: `name` sometimes (the same rules, ignoring self), `permissions` sometimes|array. `admin` → 403 `ROLE_PROTECTED`. `consultant`: renaming → 403 `ROLE_PROTECTED`, changing permissions is allowed.
- Tests: update name + permissions; `admin` is protected; consultant rename is protected; consultant permissions can change, and the consultant's `/me` shows the new permissions right away.

#### ACL-06 `DELETE /api/v1/admin/roles/{role}` — Perm: `delete-roles`
- `admin`/`consultant` → `ROLE_PROTECTED`; a role with users → 409 `ROLE_HAS_USERS`.
- Tests: all three cases + success.

#### ACL-07 `GET /api/v1/admin/roles/{role}/users` — Perm: `view-roles`
- Paginated `UserResource` of the users with this role. Query: `search`.
- Tests: returns only the users that have the role.

### 10.6 Users [Users] (auth:admin)

#### USR-01 `GET /api/v1/admin/users` — Perm: `view-users`
- Query: `search` (name, email, phone), `type` (admin|consultant), `role` (role name), `is_active` (0|1), `sort` (name, created_at, last_login_at), `per_page`.
- Admins and consultants together. Consultants who call this route (if an admin gave them the permission) still get the list; scoping is about bookings/clients/reports only.
- Tests: filters by type, role, search, is_active; pagination meta is present; 403 without permission.

#### USR-02 `POST /api/v1/admin/users` — Perm: `create-users` (multipart allowed)
- Body: `name` required|max:150, `email` required|email|unique:users, `phone` nullable|string|max:20, `password` nullable|confirmed|Password::defaults(), `type` in:admin,consultant (default admin), `roles` required|array|min:1, `roles.*` exists:roles,name (guard admin), `is_active` boolean, `avatar` nullable|image|max:2048, consultant fields (`title`, `specialization`, `bio`) nullable.
- If there is no password → a random one + `StaffAccountCreatedNotification`. 201 `UserResource`.
- Tests: create an admin with the role `admin`; create with a custom role; no password → notification sent; duplicate email 422; unknown role 422; the avatar is saved.

#### USR-03 `GET /api/v1/admin/users/{user}` — Perm: `view-users`

#### USR-04 `PUT /api/v1/admin/users/{user}` — Perm: `update-users`
- Body: the same as create, but all `sometimes`; `password` optional. It does **not** change roles (use USR-06).
- Tests: update name/email; email unique ignores self.

#### USR-05 `DELETE /api/v1/admin/users/{user}` — Perm: `delete-users`
- Soft delete + delete tokens. `CANNOT_DELETE_SELF`, `LAST_ADMIN`, and for consultants `CONSULTANT_HAS_FUTURE_BOOKINGS`.
- Tests: all error cases + success (the deleted user cannot log in).

#### USR-06 `PUT /api/v1/admin/users/{user}/roles` — Perm: `assign-roles`
- Body: `roles` required|array|min:1, `roles.*` exists (guard admin). `syncRoles`. Keep `consultant` for consultants. `LAST_ADMIN` check.
- Response: `UserResource` with roles + `permissions`.
- Tests: assign two roles; removing `admin` from the last admin → 422 `LAST_ADMIN`; the consultant role is kept for consultants.

#### USR-07 `PATCH /api/v1/admin/users/{user}/status` — Perm: `update-users`
- Body: `is_active` required|boolean. Deactivating deletes the tokens. Cannot deactivate self (`CANNOT_DELETE_SELF` is reused with the message "You cannot deactivate your own account") and `LAST_ADMIN`.
- Tests: deactivate → the user's old token gets 403/401; self → 422.

#### USR-08 `POST /api/v1/admin/users/{user}/avatar` — Perm: `update-users`
- multipart `avatar`.

### 10.7 Consultants [Consultants] (auth:admin)

Route binding `{consultant}` MUST resolve only `User` rows with `type = consultant` (use `Route::bind('consultant', fn ($id) => User::consultants()->findOrFail($id))` in the module's RouteServiceProvider). Every route also runs `ConsultantPolicy` (admin → all; a consultant → only himself).

#### CON-01 `GET /api/v1/admin/consultants` — Perm: `view-consultants`
- Query: `search` (name, email, phone, specialization), `is_active`, `specialization`, `sort` (name, created_at), `per_page`.
- With counts (`stats` in `ConsultantResource`) and `working_days`.
- A consultant calling this (if he has the permission) gets only **himself** in the list.
- Tests: list; search; the stats counts are correct; a consultant sees only himself.

#### CON-02 `POST /api/v1/admin/consultants` — Perm: `create-consultants` (multipart)
- Body: `name` **required**|max:150, `email` **required**|email|unique:users, `phone` nullable|max:20, `photo` nullable|image|mimes:jpg,jpeg,png,webp|max:2048, `title` nullable|max:150, `specialization` nullable|max:150, `bio` nullable|max:2000, `password` nullable|confirmed, `is_active` boolean default true, `availability` nullable (the same structure as CON-09, to create the schedule in one call).
- Sets `type = consultant` and assigns the role `consultant` **automatically** (the request cannot choose roles here). The photo is saved in the `avatar` collection. Sends `StaffAccountCreatedNotification` if no password was given.
- Tests: success with only name + email (the role `consultant` is assigned; type is consultant); with a photo (media saved); missing name → 422; missing email → 422; duplicate email → 422; with availability → the rows are created.

#### CON-03 `GET /api/v1/admin/consultants/{consultant}` — Perm: `view-consultants`
- `ConsultantResource` + `availability` (AvailabilityResource) + `stats`.
- Tests: an admin can view; consultant A viewing consultant B → 403; consultant A viewing himself → 200; an admin user id (not a consultant) → 404.

#### CON-04 `PUT /api/v1/admin/consultants/{consultant}` — Perm: `update-consultants`
- Body: `name`, `email` (unique ignore), `phone`, `title`, `specialization`, `bio`, `is_active` (all `sometimes`).

#### CON-05 `DELETE /api/v1/admin/consultants/{consultant}` — Perm: `delete-consultants`
- 409 `CONSULTANT_HAS_FUTURE_BOOKINGS` if there are future pending bookings. Soft delete.

#### CON-06 `PATCH /api/v1/admin/consultants/{consultant}/status` — Perm: `update-consultants`
- Body `is_active`. An inactive consultant disappears from the public list and has no slots.

#### CON-07 `POST /api/v1/admin/consultants/{consultant}/photo` — Perm: `update-consultants`
- multipart `photo`.

#### CON-08 `GET /api/v1/admin/consultants/{consultant}/availability` — Perm: `view-availability`
- `AvailabilityResource` (always 7 days).

#### CON-09 `PUT /api/v1/admin/consultants/{consultant}/availability` — Perm: `manage-availability`
- Body:

```json
{
  "days": [
    { "day_of_week": 0, "ranges": [ { "start_time": "10:00", "end_time": "12:00" }, { "start_time": "14:00", "end_time": "16:00" } ] },
    { "day_of_week": 1, "ranges": [ { "start_time": "09:00", "end_time": "17:00" } ] },
    { "day_of_week": 5, "ranges": [] }
  ]
}
```

- Rules: `days` required|array|max:7; `days.*.day_of_week` required|integer|between:0,6|distinct; `days.*.ranges` present|array; `days.*.ranges.*.start_time` required|date_format:H:i; `days.*.ranges.*.end_time` required|date_format:H:i. Check these in the `after()` hook of the FormRequest (wildcard `after:` rules are not reliable for nested arrays): end_time > start_time, the minutes are 00 or 30 (multiples of `BOOKING_SLOT_MINUTES`), the range is at least `BOOKING_DURATION_MINUTES` long, and no overlap in the same day → `AVAILABILITY_OVERLAP`.
- Days **not sent** are cleared (the full week is replaced). Everything runs in a transaction: delete the old rows, insert the new ones.
- Response: `AvailabilityResource` + `warnings: { conflicting_bookings_count: 0 }`.
- Tests: replace the schedule; overlap → 422; 10:15 → 422; end before start → 422; a duplicate day → 422; conflicting bookings are counted; a consultant updates his own schedule via this route → 200, another consultant's → 403.

#### CON-10 `GET /api/v1/admin/consultants/{consultant}/time-offs` — Perm: `view-availability`
- Query: `from`, `to` (dates; default: from today). Paginated `TimeOffResource`.

#### CON-11 `POST /api/v1/admin/consultants/{consultant}/time-offs` — Perm: `manage-availability`
- Body: `date` required|date_format:Y-m-d|after_or_equal:today, `start_time` nullable|required_with:end_time|date_format:H:i, `end_time` nullable|required_with:start_time|after:start_time, `reason` nullable|max:255.
- Response 201 + `warnings.conflicting_bookings_count`.

#### CON-12 `DELETE /api/v1/admin/consultants/{consultant}/time-offs/{timeOff}` — Perm: `manage-availability`
- 404 if the time off belongs to another consultant (`scopeBindings()`).

#### CON-13 `GET /api/v1/admin/consultants/{consultant}/slots?date=` — Perm: `view-availability`
- The same output as PUB-06 (a preview for the admin).

#### CON-14 `GET /api/v1/admin/consultants/{consultant}/clients` — Perm: `view-clients`
- The distinct clients who have at least one booking with this consultant (any status except `pending_payment`). `ClientResource` with `bookings_count` (with this consultant only) and `last_booking_at`. Query: `search`.
- Tests: only his clients are returned; the counts are correct.

#### CON-15 `GET /api/v1/admin/consultants/{consultant}/bookings` — Perm: `view-bookings`
- Query: `status` (pending|completed|cancelled|pending_payment), `report_status`, `date_from`, `date_to`, `search` (reference, client name/company), `sort` (starts_at default `-starts_at`).
- The "Pending bookings" tab = `?status=pending`.

#### CON-16 `GET /api/v1/admin/consultants/{consultant}/pending-reports` — Perm: `view-reports`
- The bookings with `status=completed` and `report_status=pending` (`BookingResource`). This is the list the consultant has to write reports for.

#### CON-17 `GET /api/v1/admin/consultants/{consultant}/reports` — Perm: `view-reports`
- `ReportResource` list of this consultant. Query: `search` (title, client), `date_from`, `date_to`.

#### CON-18 `GET /api/v1/admin/consultants/{consultant}/stats` — Perm: `view-consultants`
- `{ pending_bookings, completed_bookings, cancelled_bookings, pending_reports, reports, clients, upcoming_bookings: [BookingResource x5] }`.

### 10.8 Consultant self-service [Consultants] (auth:admin, only `type=consultant`, else 403)

| ID | Method & URL | Perm | Behaviour |
|---|---|---|---|
| MY-01 | `GET /api/v1/admin/my/availability` | `view-availability` | the same as CON-08 for the logged-in consultant |
| MY-02 | `PUT /api/v1/admin/my/availability` | `manage-availability` | the same as CON-09 |
| MY-03 | `GET /api/v1/admin/my/time-offs` | `view-availability` | the same as CON-10 |
| MY-04 | `POST /api/v1/admin/my/time-offs` | `manage-availability` | the same as CON-11 |
| MY-05 | `DELETE /api/v1/admin/my/time-offs/{timeOff}` | `manage-availability` | 404 if not his |
| MY-06 | `GET /api/v1/admin/my/slots?date=` | `view-availability` | the same as CON-13 |

Reuse the same controller methods: the `my` controller resolves `$consultant = $request->user()` and calls the same service. Tests: an admin (type admin) calling `/my/*` → 403; a consultant → 200 with his own data.

### 10.9 Clients (admin side) [Clients] (auth:admin, scoped with `visibleTo`)

| ID | Method & URL | Perm | Notes |
|---|---|---|---|
| ADM-CL-01 | `GET /api/v1/admin/clients` | `view-clients` | Query: `search` (name, email, phone, company_name), `is_active`, `consultant_id` (admin only), `has_active_subscription` (0/1), `sort` (name, company_name, created_at). With `bookings_count`, `reports_count`, `active_subscription`. Consultants see only their clients. |
| ADM-CL-02 | `GET /api/v1/admin/clients/{client}` | `view-clients` | + `locations`, `active_subscription`, counts. Policy: a consultant must have a booking with this client, else 403. |
| ADM-CL-03 | `PUT /api/v1/admin/clients/{client}` | `update-clients` | `name`, `email`, `phone`, `company_name` |
| ADM-CL-04 | `PATCH /api/v1/admin/clients/{client}/status` | `update-clients` | `is_active`; deactivating deletes the client's tokens |
| ADM-CL-05 | `DELETE /api/v1/admin/clients/{client}` | `delete-clients` | Soft delete; 409 `CLIENT_HAS_FUTURE_BOOKINGS` if the client has future pending bookings |
| ADM-CL-06 | `GET /api/v1/admin/clients/{client}/bookings` | `view-bookings` | scoped (a consultant sees only his bookings with this client) |
| ADM-CL-07 | `GET /api/v1/admin/clients/{client}/reports` | `view-reports` | scoped |
| ADM-CL-08 | `GET /api/v1/admin/clients/{client}/subscriptions` | `view-clients` | `SubscriptionResource` list |


Tests: an admin sees all clients; consultant A sees only the clients who booked A; consultant A opening client X (no booking with A) → 403; search works; deactivate works.

### 10.10 Packages (admin side) [Packages] (auth:admin)

| ID | Method & URL | Perm | Body |
|---|---|---|---|
| PKG-01 | `GET /api/v1/admin/packages` | `view-packages` | Query: `is_active`, `search`. Includes inactive packages. With `subscriptions_count`. |
| PKG-02 | `POST /api/v1/admin/packages` | `create-packages` | `slug` required\|alpha_dash\|unique, `name_ar` required, `name_en` required, `description_ar`/`description_en` nullable, `features` array, `features.*.ar` required, `features.*.en` required, `price` required\|integer\|min:0 (halalas), `billing_period_days` integer\|min:1 default 30, `consultations_limit` nullable\|integer\|min:1, `documents_limit` nullable\|integer\|min:0, `is_featured` boolean, `is_active` boolean, `sort_order` integer |
| PKG-03 | `GET /api/v1/admin/packages/{package}` | `view-packages` | |
| PKG-04 | `PUT /api/v1/admin/packages/{package}` | `update-packages` | the same, all `sometimes`. Changing the price does NOT change existing subscriptions. |
| PKG-05 | `DELETE /api/v1/admin/packages/{package}` | `delete-packages` | 409 `PACKAGE_HAS_SUBSCRIPTIONS` if there are active subscriptions; otherwise soft delete |
| PKG-06 | `PATCH /api/v1/admin/packages/{package}/status` | `update-packages` | `is_active` |

### 10.11 Bookings (admin side) [Bookings] (auth:admin, scoped)

#### BKG-01 `GET /api/v1/admin/bookings` — Perm: `view-bookings`
- Query: `status`, `report_status`, `payment_status`, `consultant_id` (ignored for consultants), `client_id`, `package_id`, `date_from`, `date_to`, `search` (reference, client name, company, consultant name), `sort` (starts_at, created_at, amount; default `-starts_at`), `per_page`.
- Eager load: client, consultant.media, package, latestPayment, report.media. No N+1 (test with `DB::enableQueryLog()`: the query count does not grow with the number of bookings).
- Tests: an admin sees all; a consultant sees only his; every filter; search by reference.

#### BKG-02 `GET /api/v1/admin/bookings/{booking}` — Perm: `view-bookings` + BookingPolicy::view
- Full `BookingResource` with all payments (`payments` array) and the report.
- Tests: an admin views any booking; a consultant views his own; another consultant's → 403.

#### BKG-03 `POST /api/v1/admin/bookings/{booking}/complete` — Perm: `complete-bookings` + policy (admin or the booking's consultant)
- Body: optional `notes` nullable|max:1000, stored in `bookings.completion_notes`.
- The state machine `complete()`. Errors: `BOOKING_INVALID_STATUS` (not pending), `BOOKING_NOT_STARTED`.
- Response: `BookingResource` (status completed, report_status pending).
- Tests: success after the start time; before the start time → 422; a cancelled booking → 422; another consultant → 403; after completing, the booking appears in CON-16 pending reports.

#### BKG-04 `POST /api/v1/admin/bookings/{booking}/cancel` — Perm: `cancel-bookings` + policy
- Body: `reason` required|string|max:500.
- Allowed statuses: `pending`, `pending_payment`. Side effects as in 9.1 (quota released, refund requested if paid, `CancelMeetingJob`, notifications).
- Tests: success; the quota is released; `refund_status = requested` when paid; `CancelMeetingJob` is pushed (`Queue::fake()`); a completed booking → 422; notifications sent.

#### BKG-05 `POST /api/v1/admin/bookings/{booking}/meeting` — Perm: `manage-meetings`
- (Re)creates the Google Meet event: if `meeting_event_id` exists, cancel the old one first. Runs **synchronously** here so the admin sees the result. Failure → 502 `MEETING_CREATION_FAILED`. Only for `pending` bookings.
- Body: `notify_client` boolean default true (resend the confirmation email with the new link).
- Tests: with the fake provider → the url is set; a provider exception → 502 and `meeting_status = failed`.

#### BKG-06 `GET /api/v1/admin/bookings/calendar?from=2026-09-01&to=2026-09-30` — Perm: `view-bookings`
- Not paginated. Max range 62 days (else 422). Returns the minimal items for a calendar view: `id, reference, status, starts_at, ends_at, consultant {id, name}, client {id, company_name}`. Scoped. Filter `consultant_id`.

#### BKG-07 `POST /api/v1/admin/bookings/{booking}/mark-refunded` — Perm: `refund-payments`
- Only when `refund_status = requested`. Sets `refund_status = refunded`, `payment_status = refunded`, and the latest paid payment `status = refunded`. Body: `note` nullable.

### 10.12 Reports (admin side) [Reports] (auth:admin, scoped)

#### RPT-01 `GET /api/v1/admin/reports` — Perm: `view-reports`
- Query: `consultant_id` (admin only), `client_id`, `search` (title, booking reference, client company, consultant name), `date_from`, `date_to` (on created_at), `sort` (created_at, title).
- This is the "Reports" page: each row shows the consultant who made it, the client it is for, and a download link.

#### RPT-02 `GET /api/v1/admin/reports/{report}` — Perm: `view-reports` + ReportPolicy::view

#### RPT-03 `POST /api/v1/admin/bookings/{booking}/report` — Perm: `upload-reports` + BookingPolicy::uploadReport (multipart)
- Body: `title` required|max:191, `summary` nullable|max:5000, `file` required|file|mimes:pdf,doc,docx|max:20480, `notify_client` boolean default true.
- Creates the report or replaces it (9.9). 201 on create, 200 on replace.
- Tests: `Storage::fake('local')`, `Notification::fake()`; upload for a completed booking → the report is created, the file is stored, `report_status = uploaded`, `ReportReadyNotification` is sent to the client with the signed URL; the booking is not completed → 422 `REPORT_NOT_ALLOWED`; an exe file → 422; another consultant → 403; replacing keeps one report row.

#### RPT-04 `GET /api/v1/admin/reports/{report}/download` — Perm: `download-reports` + policy
- Streams the file. Tests: 200 + header `content-disposition` contains `attachment`; another consultant → 403.

#### RPT-05 `DELETE /api/v1/admin/reports/{report}` — Perm: `delete-reports`
- Deletes the report and its media; the booking `report_status` goes back to `pending`.

#### RPT-06 `POST /api/v1/admin/reports/{report}/notify-client` — Perm: `upload-reports` + policy
- Sends `ReportReadyNotification` again.

### 10.13 Payments (admin side) [Payments] (auth:admin, scoped)

| ID | Method & URL | Perm | Notes |
|---|---|---|---|
| PAY-01 | `GET /api/v1/admin/payments` | `view-payments` | Query: `status`, `client_id`, `booking_id`, `date_from`, `date_to`, `search` (booking reference, gateway_payment_id, client company). `PaymentResource` + client. |
| PAY-02 | `GET /api/v1/admin/payments/{payment}` | `view-payments` | + booking summary. **Never** returns `gateway_response`. |

### 10.14 Dashboard [Dashboard] (auth:admin)

#### DSH-01 `GET /api/v1/admin/dashboard/stats` — Perm: `view-dashboard`
- For an admin:

```json
{ "success": true, "message": "OK", "data": {
  "bookings": { "total": 120, "pending": 14, "completed": 90, "cancelled": 16, "today": 3 },
  "reports": { "total": 85, "pending": 5 },
  "clients": { "total": 60, "new_this_month": 8 },
  "consultants": { "total": 6, "active": 6 },
  "revenue": { "this_month": 1520000, "this_month_formatted": "15,200.00 SAR", "total": 9850000, "total_formatted": "98,500.00 SAR" },
  "upcoming_bookings": [ "BookingResource x5" ]
} }
```
- For a consultant: the same keys, but every number is scoped to him, and `consultants` and `revenue` are **omitted**.
- Tests: the numbers are correct for the admin; a consultant only gets his numbers and no revenue key.

### 10.15 Client dashboard — authentication [Clients]

#### CLI-AUTH-01 `POST /api/v1/client/auth/register`
- Body (from the wizard screenshot): `name` required|max:150 ("Full Name"), `email` required|email|unique:clients, `phone` required|regex:`/^05\d{8}$/`, `company_name` required|max:191, `password` required|confirmed|min:8, `password_confirmation`, `device_name` nullable.
- Creates the client, sends `ClientWelcomeNotification`, returns the token (201) with the same shape as login.
- Tests: success returns a token; duplicate email 422; a bad phone 422; password confirmation mismatch 422; the client can call `/client/auth/me` with the token; **the same email can exist in `users` and `clients` without conflict**.

#### CLI-AUTH-02 `POST /api/v1/client/auth/login` — same behaviour as ADM-AUTH-01 but for clients.
- Tests: success; wrong password; inactive → 403; **an admin's credentials cannot log in here**.

#### CLI-AUTH-03 `POST /api/v1/client/auth/logout` — auth:client
#### CLI-AUTH-04 `GET /api/v1/client/auth/me` — auth:client
- `ClientResource` + `active_subscriptions` + `default_location` + `default_payment_method`.
- Tests: an **admin token → 401**.
#### CLI-AUTH-05 `POST /api/v1/client/auth/forgot-password` — broker `clients`
#### CLI-AUTH-06 `POST /api/v1/client/auth/reset-password` — broker `clients`

### 10.16 Client profile [Clients] (auth:client)

| ID | Method & URL | Body |
|---|---|---|
| CLI-PRF-01 | `GET /api/v1/client/profile` | — |
| CLI-PRF-02 | `PUT /api/v1/client/profile` | `name`, `email` (unique ignore self), `phone`, `company_name` |
| CLI-PRF-03 | `POST /api/v1/client/profile/avatar` | multipart `avatar` |
| CLI-PRF-04 | `DELETE /api/v1/client/profile/avatar` | — |
| CLI-PRF-05 | `PUT /api/v1/client/profile/password` | `current_password` (guard client), `password` confirmed |

### 10.17 Client locations [Clients] (auth:client) — wizard step 5

| ID | Method & URL | Body / Notes |
|---|---|---|
| CLI-LOC-01 | `GET /api/v1/client/locations` | Not paginated; the default location first |
| CLI-LOC-02 | `POST /api/v1/client/locations` | `name` required\|max:150, `city` nullable\|max:100, `address` required\|max:255, `latitude` nullable\|numeric\|between:-90,90, `longitude` nullable\|numeric\|between:-180,180, `is_default` boolean. The first location is the default automatically. Setting `is_default` unsets the others. 201 |
| CLI-LOC-03 | `GET /api/v1/client/locations/{location}` | 404 if not his (scope the binding to the client, **not** 403, so ids do not leak) |
| CLI-LOC-04 | `PUT /api/v1/client/locations/{location}` | the same fields (`sometimes`) |
| CLI-LOC-05 | `DELETE /api/v1/client/locations/{location}` | soft delete; if it was the default, the newest remaining one becomes the default |
| CLI-LOC-06 | `PATCH /api/v1/client/locations/{location}/default` | set as the default |

Tests: CRUD; a client cannot see/edit/delete another client's location (404); the default logic.

### 10.18 Client payment methods [Payments] (auth:client) — wizard step 6

| ID | Method & URL | Body / Notes |
|---|---|---|
| CLI-PM-01 | `GET /api/v1/client/payment-methods` | not paginated; the default first |
| CLI-PM-02 | `POST /api/v1/client/payment-methods` | `token` required\|string (made by the frontend with the gateway JS). Backend: `gateway->tokenDetails($token)` → save brand/last4/exp. A duplicate token → returns the existing one. `is_default` boolean. 201 |
| CLI-PM-03 | `DELETE /api/v1/client/payment-methods/{paymentMethod}` | soft delete; 404 if not his |
| CLI-PM-04 | `PATCH /api/v1/client/payment-methods/{paymentMethod}/default` | |

Tests: add with `tok_fake_success` → brand visa, last4 4242; `gateway_token` is not in the JSON; another client's card → 404.

### 10.19 Client subscriptions [Packages] (auth:client)

| ID | Method & URL | Notes |
|---|---|---|
| CLI-SUB-01 | `GET /api/v1/client/subscriptions` | Query: `status`. `SubscriptionResource` list |
| CLI-SUB-02 | `GET /api/v1/client/subscriptions/active` | Not paginated: the active subscriptions with the remaining counts |

### 10.20 Client bookings [Bookings] (auth:client) — the wizard

#### CLI-BKG-01 `POST /api/v1/client/bookings/quote`
- Body: `package_id` required, `consultant_id` nullable, `date` nullable, `time` nullable.
- Response:

```json
{ "success": true, "message": "OK", "data": {
  "package": { "PackageResource" },
  "requires_payment": true,
  "amount": 190000, "amount_formatted": "1,900.00 SAR", "currency": "SAR",
  "subscription": null,
  "slot_available": true
} }
```
- `slot_available` is only calculated when consultant_id + date + time are sent (else `null`).
- Tests: no subscription → requires payment with the package price; an active subscription with remaining quota → `requires_payment=false`, amount 0; a used-up subscription → requires payment; Gold unlimited → always free while active.

#### CLI-BKG-02 `POST /api/v1/client/bookings`
- Body and flow: 9.5. Response: 9.5.
- Errors: 409 `SLOT_NOT_AVAILABLE`, 422 `PAYMENT_METHOD_REQUIRED`, 404/422 `PAYMENT_METHOD_NOT_OWNED`, 422 `LOCATION_NOT_OWNED`, 422 `PACKAGE_INACTIVE`, 422 `CONSULTANT_INACTIVE`, 402 `PAYMENT_FAILED`.
- Tests (REQUIRED, all with `Queue::fake()` / `Notification::fake()`):
  1. With `tok_fake_success` → 201, status `pending`, payment_status `paid`, a subscription is created with used = 1, `CreateMeetingJob` is pushed.
  2. With a saved payment method → the same.
  3. With `tok_fake_3ds` → 201, status `pending_payment`, `payment.requires_action = true`, `transaction_url` is present.
  4. With `tok_fake_declined` → 402 `PAYMENT_FAILED`, the booking is cancelled, the slot is free again.
  5. An active subscription with remaining quota → no payment needed, status `pending`, used + 1, no `payments` row.
  6. The slot is already booked → 409.
  7. A slot outside availability → 409.
  8. A time in the past → 409 or 422.
  9. Another client's location → 422 `LOCATION_NOT_OWNED`.
  10. Another client's payment method → `PAYMENT_METHOD_NOT_OWNED`.
  11. No payment info when payment is needed → 422 `PAYMENT_METHOD_REQUIRED`.
  12. `save_card=true` with a card token → a `payment_methods` row is created.
  13. An inactive consultant → 422.
  14. An admin token → 401.
  15. Running the job synchronously (not faked) sets `meeting_url` and sends `BookingConfirmedNotification` with the link.

#### CLI-BKG-03 `GET /api/v1/client/bookings`
- Query: `status`, `date_from`, `date_to`, `upcoming` (1 = starts_at >= now), `sort` (default `-starts_at`). Only the client's own bookings.

#### CLI-BKG-04 `GET /api/v1/client/bookings/{booking}`
- 404 if not his. Includes the meeting link and the report (if uploaded).

#### CLI-BKG-05 `POST /api/v1/client/bookings/{booking}/cancel`
- Body: `reason` nullable|max:500. Only `pending`, and only if `starts_at - now >= client_cancel_hours` → else 422 `BOOKING_CANCEL_WINDOW_PASSED`. A `pending_payment` booking can always be cancelled by the client.
- Tests: success more than 24h before; less than 24h → 422; another client's → 404; the quota is released.

### 10.21 Client payments [Payments] (auth:client)

| ID | Method & URL | Notes |
|---|---|---|
| CLI-PAY-01 | `GET /api/v1/client/payments` | the client's payment history (`PaymentResource`) |
| CLI-PAY-02 | `POST /api/v1/client/payments/{payment}/verify` | 9.6 verify. Response `{ booking: BookingResource, payment: PaymentResource }`. 404 if not his. Idempotent |

Tests: the 3ds flow → verify → the booking becomes `pending` and the meeting job is pushed; verifying twice does not create two subscriptions.

### 10.22 Client reports [Reports] (auth:client)

| ID | Method & URL | Notes |
|---|---|---|
| CLI-RPT-01 | `GET /api/v1/client/reports` | Query: `search` (title, consultant name), `date_from`, `date_to`. Only his reports. `download_url` points to CLI-RPT-03 |
| CLI-RPT-02 | `GET /api/v1/client/reports/{report}` | 404 if not his |
| CLI-RPT-03 | `GET /api/v1/client/reports/{report}/download` | streams the file; sets `first_downloaded_at` |

### 10.23 Client dashboard home [Dashboard] (auth:client)

#### CLI-DSH-01 `GET /api/v1/client/dashboard`
```json
{ "success": true, "message": "OK", "data": {
  "next_booking": { "BookingResource or null" },
  "bookings": { "upcoming": 2, "completed": 5, "cancelled": 1 },
  "reports": { "total": 5, "unread": 1 },
  "active_subscriptions": [ "SubscriptionResource" ]
} }
```
`unread` = reports with `first_downloaded_at = null`.

### 10.24 Webhooks [Payments]

#### WHK-01 `POST /api/v1/webhooks/payments/moyasar`
- 9.6. Tests: a wrong secret → 401; a valid paid event → the booking becomes pending; the same event twice → processed once.

### 10.25 Endpoint count check

When everything is done, `php artisan route:list --path=api/v1 --json | jq length` must show **about 120 routes**. Every one MUST appear in the Postman collection (section 14) and in the frontend guide (section 15). Write a test `tests/Feature/RouteCoverageTest.php` that loads the Postman collection JSON, collects all `{method} {path}` pairs, and asserts that every `api/v1` route from `Route::getRoutes()` is present (path params `{id}` vs `:id`/`{{var}}` normalised).

---
## 11. Services, actions, jobs, notifications

A full list of the classes to create. File paths are relative to `Modules/<Module>/app/`.

### Core
- `Traits/ApiResponse.php`, `Http/Controllers/ApiController.php`
- `Exceptions/BusinessException.php`, `Enums/ErrorCode.php`
- `Exceptions/ApiExceptionRenderer.php` (called from `bootstrap/app.php`; one method per exception type)
- `Http/Middleware/SetLocaleFromHeader.php`
- `Support/Money.php`, `Support/QueryFilters.php`
- `Http/Controllers/MetaController.php` (PUB-08)
- `lang/{ar,en}/errors.php`, `lang/{ar,en}/messages.php`

### AccessControl
- `Models/Role.php`, `Models/Permission.php`
- `Support/PermissionRegistry.php` (permission constants + groups + default consultant permissions)
- `Services/RoleService.php` (`create`, `update`, `delete` with the protected-role rules)
- `Http/Controllers/Admin/RoleController.php`, `PermissionController.php`
- `Http/Requests/Admin/StoreRoleRequest.php`, `UpdateRoleRequest.php`
- `Http/Resources/RoleResource.php`, `PermissionResource.php`
- `Database/Seeders/RolesAndPermissionsSeeder.php`
- `lang/{ar,en}/permissions.php` (a label for every permission and group)

### Users
- `Models/User.php`, `Enums/UserType.php`
- `Http/Middleware/EnsureUserIsActive.php`
- `Services/AdminAuthService.php` (`login`, `logout`)
- `Services/UserService.php` (`create`, `update`, `delete`, `syncRoles`, `setActive`, `ensureNotLastAdmin`)
- `Services/AvatarService.php` (shared helper for the `avatar` collection; used by Users, Consultants, Clients)
- `Http/Controllers/Admin/AuthController.php`, `ProfileController.php`, `UserController.php`
- Requests: `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`, `UpdateProfileRequest`, `UpdatePasswordRequest`, `AvatarRequest`, `StoreUserRequest`, `UpdateUserRequest`, `SyncUserRolesRequest`, `UpdateStatusRequest`
- `Http/Resources/UserResource.php`
- `Notifications/StaffAccountCreatedNotification.php`, `Notifications/UserResetPasswordNotification.php`
- `Database/Factories/UserFactory.php` (states: `admin()`, `consultant()`, `inactive()`)
- `Database/Seeders/SuperAdminSeeder.php`

### Consultants
- `Models/ConsultantAvailability.php`, `Models/ConsultantTimeOff.php`
- `Services/ConsultantService.php` (`create` with auto role, `update`, `delete`)
- `Services/AvailabilityService.php` (`getWeek`, `replaceWeek`, `countConflictingBookings`)
- `Services/TimeOffService.php`
- `Services/SlotService.php` (9.3)
- `Policies/ConsultantPolicy.php`
- Controllers: `Admin/ConsultantController.php`, `Admin/ConsultantAvailabilityController.php`, `Admin/ConsultantTimeOffController.php`, `Admin/ConsultantRelationsController.php` (clients/bookings/pending-reports/reports/stats), `Admin/MyAvailabilityController.php`, `Public/PublicConsultantController.php`
- Requests: `StoreConsultantRequest`, `UpdateConsultantRequest`, `ReplaceAvailabilityRequest`, `StoreTimeOffRequest`, `SlotsRequest`, `AvailableDatesRequest`
- Resources: `ConsultantResource`, `PublicConsultantResource`, `AvailabilityResource`, `TimeOffResource`, `SlotResource`
- Factories: `ConsultantAvailabilityFactory`, `ConsultantTimeOffFactory`
- `Database/Seeders/DemoConsultantsSeeder.php`

### Clients
- `Models/Client.php`, `Models/ClientLocation.php`
- `Http/Middleware/EnsureClientIsActive.php`
- `Services/ClientAuthService.php`, `Services/ClientService.php`, `Services/LocationService.php`
- `Policies/ClientPolicy.php` (admin side)
- Controllers: `Client/AuthController.php`, `Client/ProfileController.php`, `Client/LocationController.php`, `Admin/ClientController.php`
- Resources: `ClientResource`, `LocationResource`
- Notifications: `ClientWelcomeNotification`, `ClientResetPasswordNotification`
- Factories: `ClientFactory`, `ClientLocationFactory`
- `Database/Seeders/DemoClientSeeder.php`

### Packages
- `Models/Package.php`, `Models/ClientSubscription.php`, `Enums/SubscriptionStatus.php`
- `Services/PackageService.php`, `Services/SubscriptionService.php` (9.4)
- `Console/ExpireSubscriptionsCommand.php` (`subscriptions:expire`)
- Controllers: `Admin/PackageController.php`, `Public/PublicPackageController.php`, `Client/SubscriptionController.php`
- Resources: `PackageResource`, `SubscriptionResource`
- Factories: `PackageFactory` (states `iron()`, `silver()`, `gold()`), `ClientSubscriptionFactory`
- `Database/Seeders/PackagesSeeder.php`

### Payments
- `Contracts/PaymentGateway.php`, `DTO/ChargeRequest.php`, `DTO/ChargeResult.php`, `DTO/CardDetails.php`
- `Gateways/FakeGateway.php`, `Gateways/MoyasarGateway.php`
- `Models/Payment.php`, `Models/PaymentMethod.php`, `Enums/PaymentRecordStatus.php` (`initiated|paid|failed|refunded`)
- `Services/PaymentService.php` (`chargeBooking`, `verify`, `handleWebhook`, `applyResult`)
- `Services/PaymentMethodService.php` (`storeFromToken`, `setDefault`, `delete`)
- Controllers: `Client/PaymentMethodController.php`, `Client/PaymentController.php`, `Admin/PaymentController.php`, `Webhooks/MoyasarWebhookController.php`
- Resources: `PaymentResource`, `PaymentMethodResource`
- Notifications: `PaymentFailedNotification`
- Factories: `PaymentFactory`, `PaymentMethodFactory`

### Bookings
- `Models/Booking.php` + the enums in 9.1
- `Contracts/MeetingProvider.php`, `DTO/MeetingResult.php`, `Meetings/FakeMeetingProvider.php`, `Meetings/GoogleMeetProvider.php`
- `Services/BookingStateMachine.php`, `Services/BookingQueryService.php` (filters + scoping for every list), `Services/BookingPermissions.php` (calculates the `can` object)
- `Actions/CreateBookingAction.php`, `Actions/QuoteBookingAction.php`
- `Jobs/CreateMeetingJob.php`, `Jobs/CancelMeetingJob.php`
- `Console/ExpirePendingPaymentBookingsCommand.php` (`bookings:expire-pending`, every minute)
- `Policies/BookingPolicy.php` (`view`, `complete`, `cancel`, `uploadReport`, `manageMeeting`)
- Controllers: `Admin/BookingController.php`, `Client/BookingController.php`
- Requests: `Client/QuoteBookingRequest`, `Client/StoreBookingRequest`, `Client/CancelBookingRequest`, `Admin/CompleteBookingRequest`, `Admin/CancelBookingRequest`, `Admin/RegenerateMeetingRequest`, `Admin/CalendarRequest`, `BookingIndexRequest`
- Resources: `BookingResource`, `BookingCalendarResource`
- Notifications: `BookingConfirmedNotification`, `NewBookingNotification`, `BookingCancelledNotification`
- Factory: `BookingFactory` (states `pendingPayment()`, `pending()`, `completed()`, `cancelled()`, `withReportPending()`, `past()`, `future()`)

### Reports
- `Models/Report.php`
- `Actions/UploadReportAction.php`, `Services/ReportDownloadService.php`
- `Policies/ReportPolicy.php`
- Controllers: `Admin/ReportController.php`, `Client/ReportController.php`, `Public/SignedReportDownloadController.php`
- Requests: `UploadReportRequest`, `ReportIndexRequest`
- Resources: `ReportResource`
- Notifications: `ReportReadyNotification`
- Factory: `ReportFactory` (with the state `withFile()` that attaches a fake PDF)

### Dashboard
- `Services/AdminStatsService.php`, `Services/ClientDashboardService.php`
- Controllers: `Admin/DashboardController.php`, `Client/DashboardController.php`

### Scheduler (`routes/console.php`)

```php
Schedule::command('bookings:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:expire')->dailyAt('00:05');
Schedule::command('queue:prune-failed --hours=168')->daily();
```

---

## 12. Testing strategy

### 12.1 Setup

`phpunit.xml` (root):

```xml
<testsuites>
    <testsuite name="Unit">
        <directory>tests/Unit</directory>
        <directory>Modules/*/tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
        <directory>tests/Feature</directory>
        <directory>Modules/*/tests/Feature</directory>
    </testsuite>
</testsuites>
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_TIMEZONE" value="Asia/Riyadh"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="PAYMENT_GATEWAY" value="fake"/>
    <env name="MEETING_DRIVER" value="fake"/>
    <env name="FILESYSTEM_DISK" value="local"/>
</php>
```

(If PHPUnit does not accept the wildcard `Modules/*/tests/Unit` directory, list every module folder explicitly.)

Every module test extends the root `Tests\TestCase`. The root `tests/TestCase.php` has these helpers (write them once and use them everywhere):

```php
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::preventStrayRequests();
        Storage::fake('public');
        Storage::fake('local');
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-20 09:00:00', 'Asia/Riyadh')); // a Sunday
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function createAdmin(array $attrs = []): User { /* factory()->admin() + assignRole('admin') */ }
    protected function createConsultant(array $attrs = [], bool $withDefaultAvailability = true): User
    { /* factory()->consultant() + role + Sun–Thu 09:00–17:00 availability */ }
    protected function createStaffWithPermissions(array $permissions): User { /* a new role with exactly these permissions */ }
    protected function createClient(array $attrs = []): Client { /* + one default location */ }

    protected function actingAsAdmin(?User $user = null): User
    { $user ??= $this->createAdmin(); Sanctum::actingAs($user, ['*'], 'admin'); return $user; }

    protected function actingAsConsultant(?User $user = null): User
    { $user ??= $this->createConsultant(); Sanctum::actingAs($user, ['*'], 'admin'); return $user; }

    protected function actingAsClient(?Client $client = null): Client
    { $client ??= $this->createClient(); Sanctum::actingAs($client, ['*'], 'client'); return $client; }

    protected function assertApiSuccess(TestResponse $r, int $status = 200): TestResponse
    { return $r->assertStatus($status)->assertJsonPath('success', true)->assertJsonStructure(['success', 'message', 'data']); }

    protected function assertApiError(TestResponse $r, int $status, string $code): TestResponse
    { return $r->assertStatus($status)->assertJsonPath('success', false)->assertJsonPath('error_code', $code); }

    protected function assertPaginated(TestResponse $r): TestResponse
    { return $r->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page'], 'links']); }
}
```

**Note on Sanctum and multiple guards in one test:** `Sanctum::actingAs` sets the user on a guard. If a test switches between the client and the admin in the same test, call `$this->app['auth']->forgetGuards()` between the requests.

### 12.2 What every endpoint's feature test file MUST contain

File name: `Modules/<Module>/tests/Feature/<Area>/<EndpointGroup>Test.php` (for example `Modules/Bookings/tests/Feature/Admin/CompleteBookingTest.php`).

Minimum per endpoint:

| Case | Example method name |
|---|---|
| Happy path (status, envelope, JSON structure, DB state) | `test_bkg03_consultant_can_complete_his_booking_after_start()` |
| Validation (one test per important rule, or a data provider) | `test_bkg03_validation_errors(array $payload, string $field)` |
| 401 without a token | `test_bkg03_requires_authentication()` |
| 401 with the other guard's token | `test_bkg03_rejects_client_token()` |
| 403 without the permission | `test_bkg03_requires_complete_bookings_permission()` |
| 403/404 not the owner (scoping) | `test_bkg03_consultant_cannot_complete_other_consultants_booking()` |
| 404 unknown id | `test_bkg03_returns_404_for_unknown_booking()` |
| Business errors (every `error_code` the endpoint can return) | `test_bkg03_cannot_complete_before_start_time()` |
| Side effects (notifications, jobs, media, events) | `test_bkg03_booking_appears_in_pending_reports()` |

The test cases listed per endpoint in section 10 are **additional** required cases.

### 12.3 Unit tests (REQUIRED)

| Class | Cases |
|---|---|
| `SlotService` | the 12 cases in 9.3 |
| `BookingStateMachine` | every allowed transition + every forbidden one + side effects (quota, refund flag, jobs) |
| `SubscriptionService` | quote (4 cases in CLI-BKG-01), activate, consume (with and without a limit), release (not below 0), expire command |
| `CreateBookingAction` | a concurrency simulation: two calls for the same slot → the second one throws `SLOT_NOT_AVAILABLE` |
| `PaymentService` | paid / initiated / failed results; verify is idempotent; webhook is idempotent; the late-payment race rule |
| `FakeGateway` | every magic token |
| `MoyasarGateway` | with `Http::fake()`: the request payload is correct (amount in halalas, token source, callback_url); status mapping; tokenDetails mapping; an HTTP error → `failed` |
| `GoogleMeetProvider` | mock `Google\Service\Calendar` (inject a factory closure so it can be mocked); checks that `conferenceDataVersion = 1` and the attendees are correct |
| `AvailabilityService` | replaceWeek; the overlap detection; conflicting bookings count |
| `PermissionRegistry` | names are unique; every name matches `^[a-z]+(-[a-z]+)+$`; the default consultant permissions exist in the registry |
| `RoleService` | the protected role rules |
| `UserService` | the last-admin rule; the self-delete rule; the consultant role is kept on sync |
| `Money` | `format(190000) === '1,900.00 SAR'`, `format(0)`, `format(5)` → `0.05 SAR` |
| `QueryFilters` | search, allowed sort, forbidden sort ignored, per_page is limited to 100 |
| Booking `reference` | the format `BK-2026-000015` |

### 12.4 End-to-end feature test (REQUIRED): `tests/Feature/FullBookingJourneyTest.php`

One long test that walks through the whole business, only through HTTP calls:

1. The admin logs in (real login, not `actingAs`) → gets a token.
2. The admin creates a role `supervisor` with `view-bookings`, `view-reports` → assigns it to a new user.
3. The admin creates a consultant (photo + name + email) → the consultant has the role `consultant`.
4. The admin sets the consultant's availability (Sunday 10:00–12:00).
5. Public: list the packages → choose `iron`.
6. The client registers → gets a token.
7. Public: list the consultants → the new consultant is present; get the slots for next Sunday → `10:00, 10:30, 11:00, 11:30`.
8. The client adds a location and a card (`tok_fake_success`).
9. The client quotes → `requires_payment = true`, amount 190000.
10. The client books 10:30 with the saved card → `pending`, paid, a Meet URL is set (queue sync), the confirmation email contains the Meet URL (`Notification::assertSentTo`).
11. Public slots for that day → `10:00, 11:00, 11:30` (10:30 is gone).
12. The client quotes again → `requires_payment = false` (1 of 2 used).
13. The consultant logs in → `/admin/bookings` shows 1 booking; `/admin/consultants/{other}` → 403.
14. Time travel to after the session → the consultant completes → the booking is in pending reports.
15. The consultant uploads a PDF → the report is in `/admin/reports` (admin) with the consultant and client names; `ReportReadyNotification` is sent to the client with the signed link.
16. The client lists the reports → 1; downloads it → 200 attachment; the signed link works without a token.
17. The supervisor user logs in → can list bookings, but `POST /admin/users` → 403.

### 12.5 Commands

```bash
php artisan test                         # everything
php artisan test --testsuite=Unit
php artisan test Modules/Bookings        # one module
php artisan test --filter=bkg03          # one endpoint
php artisan test --parallel              # faster (needs brianium/paratest)
php artisan test --coverage --min=80     # needs Xdebug or PCOV; target ≥ 80% on Modules/*/app
```

---
## 13. Implementation phases

Every phase ends with: `./vendor/bin/pint && php artisan test` green → tick the acceptance list → commit.

### Phase 0 — Environment check

```bash
php -v                      # must be >= 8.3
php -m | grep -Ei 'pdo_mysql|pdo_sqlite|mbstring|openssl|fileinfo|gd|exif|bcmath|intl|zip'
composer -V                 # Composer 2.x
mysql --version             # MySQL 8 (for local dev; tests use SQLite)
node -v && npm -v           # only needed for newman (Phase 13)
```

Install anything that is missing before you continue.

### Phase 1 — Project skeleton and packages

1. Create Laravel 13 in the repository root (keep the existing `README.md` and `docs/`):

```bash
composer create-project laravel/laravel:^13.0 /tmp/gcmc-app
rsync -a --exclude README.md /tmp/gcmc-app/ ./
php artisan key:generate
php artisan install:api                  # Sanctum + routes/api.php + personal_access_tokens migration
```

2. Install the packages:

```bash
composer require nwidart/laravel-modules:^13.0 spatie/laravel-permission:^8.0 spatie/laravel-medialibrary:^11.0 google/apiclient:^2.18
composer require --dev brianium/paratest
php artisan vendor:publish --provider="Nwidart\Modules\LaravelModulesServiceProvider"
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-migrations"
php artisan vendor:publish --provider="Spatie\MediaLibrary\MediaLibraryServiceProvider" --tag="medialibrary-config"
php artisan make:notifications-table
php artisan storage:link
```

3. `composer.json` — add the merge plugin for modules (read the laravel-modules "Installation" docs page and follow it exactly):

```json
"extra": {
    "laravel": { "dont-discover": [] },
    "merge-plugin": { "include": ["Modules/*/composer.json"] },
    "google/apiclient-services": ["Calendar"]
},
"config": {
    "allow-plugins": { "wikimedia/composer-merge-plugin": true }
},
"scripts": {
    "pre-autoload-dump": "Google\\Task\\Composer::cleanup"
}
```

   Then `composer require wikimedia/composer-merge-plugin` and `composer dump-autoload`. (The `google/apiclient-services` + `cleanup` script keeps only the Calendar service, which removes a very large number of unused files.)

4. Create the modules in this order:

```bash
php artisan module:make Core AccessControl Users Consultants Clients Packages Payments Bookings Reports Dashboard
php artisan module:list      # all 10 must be "Enabled"
```

   In each module: delete `routes/web.php`, `resources/views`, `package.json`, `vite.config.js`; remove the web route mapping from the module's `RouteServiceProvider`; delete the generated example controller.

5. Config:
   - `config/app.php`: `timezone` → `env('APP_TIMEZONE', 'Asia/Riyadh')`, `locale` → `ar`, `fallback_locale` → `en`, add `admin_frontend_url` and `client_frontend_url`.
   - `config/auth.php`: section 6.2. `config/sanctum.php`: `guard => []`.
   - `config/permission.php`: the custom models (8.2); `teams => false`.
   - `config/media-library.php`: `disk_name => env('MEDIA_DISK', 'public')`, `max_file_size => 1024 * 1024 * 20`.
   - `config/cors.php`: `paths => ['api/*']`, `allowed_origins` from `env('CORS_ALLOWED_ORIGINS')` (comma list of the two frontends).
6. `.env.example` (all variables used by this plan):

```dotenv
APP_NAME="GCMC API"
APP_ENV=local
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Riyadh
APP_LOCALE=ar
ADMIN_FRONTEND_URL=http://localhost:3000
CLIENT_FRONTEND_URL=http://localhost:3001
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:3001

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gcmc
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@gcmc.sa
MAIL_FROM_NAME="${APP_NAME}"
MEDIA_DISK=public

SUPER_ADMIN_EMAIL=admin@gcmc.sa
SUPER_ADMIN_PASSWORD=Password@123

BOOKING_SLOT_MINUTES=30
BOOKING_DURATION_MINUTES=30
BOOKING_MIN_NOTICE_MINUTES=60
BOOKING_MAX_ADVANCE_DAYS=60
BOOKING_PAYMENT_HOLD_MINUTES=15
BOOKING_CLIENT_CANCEL_HOURS=24

PAYMENT_GATEWAY=fake
PAYMENT_CALLBACK_URL=http://localhost:3001/bookings/payment-callback
MOYASAR_SECRET_KEY=
MOYASAR_PUBLISHABLE_KEY=
MOYASAR_WEBHOOK_SECRET=

MEETING_DRIVER=fake
GOOGLE_SERVICE_ACCOUNT_JSON=
GOOGLE_CALENDAR_IMPERSONATE=
GOOGLE_CALENDAR_ID=primary
```

7. `phpunit.xml`: section 12.1. `tests/TestCase.php`: write only the empty skeleton now; the helpers are added in Phase 3.

**Acceptance:** `php artisan module:list` shows 10 enabled modules · `php artisan migrate:fresh` works on MySQL · `php artisan test` runs (the default example tests may be deleted) · `composer dump-autoload` shows no PSR-4 warnings.

### Phase 2 — Core module

1. `ErrorCode` enum, `BusinessException`, `ApiResponse` trait, `ApiController`, `Money`, `QueryFilters`, `SetLocaleFromHeader`.
2. Exception rendering in `bootstrap/app.php` for: `ValidationException`, `AuthenticationException`, `AuthorizationException`, `AccessDeniedHttpException`, `Spatie\Permission\Exceptions\UnauthorizedException`, `ModelNotFoundException`/`NotFoundHttpException`, `MethodNotAllowedHttpException`, `ThrottleRequestsException`, `BusinessException`, `Throwable` (500).
3. Translations `Modules/Core/lang/{ar,en}/errors.php` + `messages.php` (every `ErrorCode`, and the success messages used by the controllers such as `created`, `updated`, `deleted`, `logged_in`, `logged_out`).
4. PUB-08 `MetaController` (it can return a placeholder for the booking enums until Phase 9; complete it in Phase 9).
5. Tests: `Modules/Core/tests/Unit/MoneyTest.php`, `QueryFiltersTest.php`; `Modules/Core/tests/Feature/ExceptionRenderingTest.php` (register test-only routes inside the test that throw each exception and assert the envelope + code); `LocaleTest.php` (`Accept-Language: en` gives an English message).

**Acceptance:** every error type returns the 5.4 envelope with the right code · unknown `api/*` URL → 404 JSON (not HTML).

### Phase 3 — Access control, users model, admin authentication

1. Edit the root `users` migration (8.1); `permissions.group` migration (8.2).
2. `User` model moved to `Modules/Users/app/Models/User.php` (delete `app/Models/User.php` and `database/factories/UserFactory.php`; point `config/auth.php` to the new class). `UserType` enum. `UserFactory` with states. Add `newFactory()` to the model so it finds the module factory.
3. `PermissionRegistry`, `Role`/`Permission` models, `RolesAndPermissionsSeeder`, `SuperAdminSeeder`, root `DatabaseSeeder`.
4. `Gate::before` for the `admin` role.
5. Middleware aliases (6.6) and `EnsureUserIsActive`.
6. Endpoints ADM-AUTH-01..05, ADM-PRF-01..05.
7. Complete the `tests/TestCase.php` helpers (12.1) — the client helpers can be added in Phase 5.
8. Tests: all the ADM-AUTH and ADM-PRF cases + `PermissionRegistryTest` + `RolesAndPermissionsSeederTest` (running the seeder twice does not duplicate anything; admin has all the permissions; after changing the consultant role's permissions, re-seeding keeps the change).

**Acceptance:** `php artisan migrate:fresh --seed` → log in as `admin@gcmc.sa` / `Password@123` with curl → `/me` returns 34 permissions.

### Phase 4 — Roles and users management

1. `RoleService`, `RoleController`, `PermissionController`, requests, resources → ACL-01..07.
2. `UserService`, `UserController` → USR-01..08, `StaffAccountCreatedNotification`.
3. Tests for every endpoint (12.2) + `RoleServiceTest`, `UserServiceTest`.

**Acceptance:** create a role with 3 permissions → assign it to a new user → that user's `/me` returns exactly those 3 permissions · the admin role cannot be changed.

### Phase 5 — Clients

1. Migrations `clients`, `client_password_reset_tokens`, `client_locations`. `Client`, `ClientLocation` models + factories.
2. The `clients` guard/provider/broker (already in config; check them now).
3. CLI-AUTH-01..06, CLI-PRF-01..05, CLI-LOC-01..06.
4. Admin side: ADM-CL-01..05 (06–08 are done after Bookings/Reports/Packages exist; add them in Phases 7, 9 and 10 — write a `// TODO Phase 9` in the route file and remove it later).
5. Add `createClient`/`actingAsClient` to `TestCase`.
6. Tests including the **cross-guard** tests: a client token on `/admin/auth/me` → 401; an admin token on `/client/auth/me` → 401; the same email registered in both tables works.

**Acceptance:** register → login → add 2 locations → the default logic works · cross-guard tests are green.

### Phase 6 — Consultants, availability, time off, slots

1. Migrations `consultant_availabilities`, `consultant_time_offs` + models + factories.
2. `ConsultantService` (auto role), `AvailabilityService`, `TimeOffService`, `SlotService`, `ConsultantPolicy`, the `{consultant}` route binding.
3. Endpoints CON-01..13, MY-01..06, PUB-03..06.
4. CON-14..18 need bookings/reports: create the routes **after** Phase 9/10 (add them to the phase lists there).
5. For `SlotService`, the "busy" part needs the `bookings` table. Implement it through an interface `Modules\Consultants\Contracts\BusyTimeProvider` with a method `busyRanges(int $consultantId, CarbonImmutable $from, CarbonImmutable $to): array`. Bind a `NullBusyTimeProvider` (returns `[]`) now, and replace the binding in Phase 9 with the Bookings implementation. (This keeps the Consultants module independent of Bookings.)
6. Tests: all the cases in 9.3 (slots 1–2, 6–12 now; 3–5 in Phase 9) + the endpoint tests.

**Acceptance:** create a consultant with only name + email → the role `consultant` is assigned · set Sunday 10:00–12:00 → PUB-06 for next Sunday returns `10:00, 10:30, 11:00, 11:30`.

### Phase 7 — Packages and subscriptions

1. Migrations, models, factories, `PackagesSeeder` (8.7 data, Arabic and English).
2. PUB-01..02, PKG-01..06, CLI-SUB-01..02, ADM-CL-08.
3. `SubscriptionService` + the `subscriptions:expire` command + unit tests.

**Acceptance:** PUB-01 returns Iron/Silver/Gold with the prices 190000/450000/980000 and Gold `is_featured = true`.

### Phase 8 — Payments

1. Migration `payment_methods` (the `payments` migration comes in Phase 9, because it needs `bookings`; its file lives in the Payments module with the timestamp `000700`).
2. The `PaymentGateway` contract, DTOs, `FakeGateway`, `MoyasarGateway`, the binding.
3. `PaymentMethodService`, CLI-PM-01..04.
4. Unit tests: `FakeGatewayTest`, `MoyasarGatewayTest` (with `Http::fake`).

**Acceptance:** add a card with `tok_fake_success` → `Visa •••• 4242` · `gateway_token` is never in any response.

### Phase 9 — Bookings (the biggest phase; do it in the sub-steps below and test each one)

- **9a.** Migrations `bookings` and `payments`; models, enums, factories; the booking `reference` generation; `Booking::scopeBlocking`, `scopeVisibleTo`. Bind `BusyTimeProvider` to the bookings implementation → finish slot tests 3–5.
- **9b.** `BookingStateMachine` + unit tests.
- **9c.** `QuoteBookingAction` + CLI-BKG-01.
- **9d.** `PaymentService` (`chargeBooking`, `verify`, `handleWebhook`) + `CreateBookingAction` + CLI-BKG-02 (all 15 tests), CLI-PAY-01..02, WHK-01.
- **9e.** The `MeetingProvider` contract, `FakeMeetingProvider`, `GoogleMeetProvider`, `CreateMeetingJob`, `CancelMeetingJob`, the notifications `BookingConfirmedNotification`, `NewBookingNotification`, `BookingCancelledNotification`, `PaymentFailedNotification`.
- **9f.** Admin: BKG-01..07, PAY-01..02, `BookingPolicy`, `BookingPermissions` (`can`).
- **9g.** Client: CLI-BKG-03..05.
- **9h.** Consultant relation routes: CON-14, CON-15, CON-18; client relations ADM-CL-06.
- **9i.** The `bookings:expire-pending` command + test (a `pending_payment` booking with `expires_at` in the past becomes cancelled with the reason `payment_timeout`; a future one is untouched).
- **9j.** Complete PUB-08 with the booking enums.

**Acceptance:** the manual flow with curl: book → paid → Meet URL (fake) → the email is in `storage/logs/laravel.log` (mail driver `log`) → the slot has disappeared.

### Phase 10 — Reports

1. Migration, model with the `report_file` collection on the `local` disk, factory.
2. `UploadReportAction`, `ReportPolicy`, `ReportReadyNotification` (with both links).
3. RPT-01..06, CLI-RPT-01..03, PUB-07, CON-16, CON-17, ADM-CL-07.
4. Tests (with `Storage::fake('local')`).

**Acceptance:** upload a PDF → the admin reports list shows the consultant + client → the download returns the same bytes (`assertDownload`) → the signed URL works without a token and fails when it is changed.

### Phase 11 — Dashboard and demo data

1. `AdminStatsService`, `ClientDashboardService` → DSH-01, CLI-DSH-01 + tests.
2. `DemoDataSeeder` (not in production), calls:
   - `DemoConsultantsSeeder`: the 6 consultants from the screenshots, each with the role `consultant`, availability Sunday–Thursday 09:00–17:00, password `Password@123`:

| Name | Title | Specialization | Email |
|---|---|---|---|
| أ. أحمد العتيبي | مستشار حوكمة | الحوكمة المؤسسية | ahmad.alotaibi@gcmc.sa |
| د. سارة الدوسري | مستشار امتثال | الامتثال التنظيمي | sara.aldosari@gcmc.sa |
| م. محمد الشهري | مستشار إداري | تحسين الأداء | mohammed.alshehri@gcmc.sa |
| أ. أروى العنزي | مستشار موارد بشرية | استقطاب المواهب | arwa.alanazi@gcmc.sa |
| د. فهد القحطاني | مستشار مالي | إدارة المخاطر | fahad.alqahtani@gcmc.sa |
| أ. نورة السبيعي | مستشار جودة | جودة العمليات | noura.alsubaie@gcmc.sa |

   - `DemoClientSeeder`: client `client@gcmc.sa` / `Password@123`, company "شركة تجريبية", phone `0500000001`, with the 4 locations from the screenshot:

| Name | City | Address |
|---|---|---|
| الفرع الرئيسي — الرياض | الرياض | طريق العروبة، حي العليا، برج المملكة، الدور ١٨ |
| فرع جدة | جدة | طريق الملك عبدالله، حي الصحافة، مركز الإجادة |
| فرع الدمام | الدمام | طريق الملك فهد، حي الفيصلية، برج الأعمال |
| فرع مكة المكرمة | مكة المكرمة | حي العزيزية، شارع إبراهيم الخليل |

     plus a saved fake card (`tok_fake_success`), one completed booking with a report (a small generated PDF), and one upcoming pending booking.

**Acceptance:** `php artisan migrate:fresh --seed` → every dashboard page has data.

### Phase 12 — Hardening

1. `tests/Feature/FullBookingJourneyTest.php` (12.4).
2. N+1 checks: in every index test, create 3 and then 10 records and assert that the query count is the same (`DB::getQueryLog()`). Also enable `Model::preventLazyLoading()` (already enabled by `shouldBeStrict` outside production).
3. Rate limits: login/register/forgot = `throttle:10,1`; public = `throttle:60,1`; authenticated = `throttle:120,1`.
4. Security review: `gateway_token`, `gateway_response`, `password`, `remember_token` are `$hidden`; the report files are on the private disk; every admin list uses `visibleTo`; every client route binding is scoped to the client.
5. Run `php artisan test --parallel` and `php artisan test --coverage --min=80` (if coverage is available).
6. Optional: `composer require --dev larastan/larastan` and fix level 5 issues.

**Acceptance:** all tests green · coverage ≥ 80% (if measurable) · the journey test is green.

### Phase 13 — Postman collection (section 14)

### Phase 14 — Frontend guide (section 15)

### Phase 15 — Final Definition of Done (section 16)

---

## 14. Postman collection

This is a **required final deliverable**. The frontend team (and QA) must be able to import it and call every endpoint with working examples.

### 14.1 Files

```
postman/
├── GCMC-API.postman_collection.json          # Collection v2.1
├── GCMC-Local.postman_environment.json       # the local environment
├── GCMC-Staging.postman_environment.json     # the same variables, empty secrets
└── fixtures/
    ├── avatar.jpg                            # a small valid JPEG (< 50 KB)
    └── report.pdf                            # a small valid PDF (< 50 KB)
```

### 14.2 Environment variables

| Variable | Local value | Set by |
|---|---|---|
| `base_url` | `http://localhost:8000/api/v1` | manual |
| `locale` | `ar` | manual |
| `admin_email` / `admin_password` | `admin@gcmc.sa` / `Password@123` | manual |
| `consultant_email` / `consultant_password` | `ahmad.alotaibi@gcmc.sa` / `Password@123` | manual |
| `client_email` / `client_password` | `client@gcmc.sa` / `Password@123` | manual |
| `admin_token` | — | the test script of "Admin › Auth › Login" |
| `consultant_token` | — | the test script of "Admin › Auth › Login as consultant" |
| `client_token` | — | "Client › Auth › Register" or "Login" |
| `role_id`, `user_id`, `consultant_id`, `time_off_id`, `client_id`, `location_id`, `package_id`, `package_slug`, `payment_method_id`, `booking_id`, `payment_id`, `report_id`, `slot_date`, `slot_time` | — | the test scripts of the create/list requests |
| `new_client_email` | — | a pre-request script: `client+{{$timestamp}}@example.com` |

### 14.3 Collection structure (folders in this exact order; the order is also the newman run order)

```
GCMC API
├── 00 Public
│   ├── Meta (PUB-08)
│   ├── List packages (PUB-01)            → sets package_id, package_slug (iron)
│   ├── Show package (PUB-02)
│   ├── List consultants (PUB-03)         → sets consultant_id (first)
│   ├── Show consultant (PUB-04)
│   ├── Available dates (PUB-05)          → sets slot_date (first date)
│   └── Slots (PUB-06)                    → sets slot_time (first slot)
├── 01 Admin › Auth
│   ├── Login as admin (ADM-AUTH-01)      → sets admin_token
│   ├── Login as consultant               → sets consultant_token
│   ├── Me (ADM-AUTH-03)
│   ├── Forgot password (ADM-AUTH-04)
│   └── Reset password (ADM-AUTH-05)      (example only: disabled in the newman run, see 14.6)
├── 02 Admin › Profile         (ADM-PRF-01..05)
├── 03 Admin › Roles & Permissions (ACL-01..07)  → create role sets role_id; delete it at the end of the folder
├── 04 Admin › Users           (USR-01..08)      → sets user_id
├── 05 Admin › Consultants     (CON-01..18)      → sets consultant_id of the NEW consultant for the later requests
├── 06 Admin › My (consultant) (MY-01..06, uses consultant_token)
├── 07 Admin › Packages        (PKG-01..06)
├── 08 Client › Auth           (CLI-AUTH-01..06) → Register sets client_token
├── 09 Client › Profile        (CLI-PRF-01..05)
├── 10 Client › Locations      (CLI-LOC-01..06)  → sets location_id
├── 11 Client › Payment methods (CLI-PM-01..04)  → sets payment_method_id (token tok_fake_success)
├── 12 Client › Booking wizard
│   ├── Step 1 – Packages (PUB-01)
│   ├── Step 3 – Consultants (PUB-03)
│   ├── Step 4 – Available dates (PUB-05)
│   ├── Step 4 – Slots (PUB-06)
│   ├── Step 5 – Locations (CLI-LOC-01)
│   ├── Step 6 – Payment methods (CLI-PM-01)
│   ├── Quote (CLI-BKG-01)
│   ├── Create booking – saved card (CLI-BKG-02) → sets booking_id, payment_id
│   ├── Create booking – new card 3DS (CLI-BKG-02, token tok_fake_3ds)
│   └── Verify payment (CLI-PAY-02)
├── 13 Client › Bookings, Payments, Subscriptions, Dashboard (CLI-BKG-03..05, CLI-PAY-01, CLI-SUB-01..02, CLI-DSH-01)
├── 14 Admin › Bookings        (BKG-01..07)
├── 15 Admin › Reports         (RPT-01..06)      → upload uses fixtures/report.pdf, sets report_id
├── 16 Client › Reports        (CLI-RPT-01..03)
├── 17 Admin › Clients         (ADM-CL-01..08)
├── 18 Admin › Payments        (PAY-01..02)
├── 19 Admin › Dashboard       (DSH-01)
├── 20 Webhooks                (WHK-01)
└── 99 Logout                  (ADM-AUTH-02, CLI-AUTH-03)
```

The "complete booking" request needs the session start time to have passed. In the newman run, use the **demo** completed booking from `DemoDataSeeder` (a list request with `?status=completed` sets `completed_booking_id`), and use it for the report upload.

### 14.4 Rules for every request (MUST)

1. **Name:** `<Human name> (<Endpoint ID>)`, for example `Create consultant (CON-02)`.
2. **Description:** a Markdown description with: purpose, the required permission, every body field with its rules (copy them from section 10), the possible error codes.
3. **Headers:** `Accept: application/json`, `Accept-Language: {{locale}}` (set them at the collection level so every request gets them).
4. **Auth:** set at the folder level: `Bearer {{admin_token}}` for admin folders, `Bearer {{client_token}}` for client folders, `noauth` for public/webhooks. Folder 06 uses `{{consultant_token}}`.
5. **URL:** `{{base_url}}/admin/consultants/{{consultant_id}}` — never hard-code a host or an id.
6. **Body:** a realistic example with **every** field (Arabic data where the real users will type Arabic). File fields use `formdata` with `"type": "file"` and `"src": "fixtures/avatar.jpg"`.
7. **Query params:** list every supported param; the optional ones are `disabled: true` with a description.
8. **Saved examples (`response` array):** at least
   - one success example (200/201) with a **real** body copied from a real run against the seeded database,
   - one validation error example (422) for requests with a body,
   - one 401 or 403 example for protected requests,
   - every business error listed for that endpoint in section 10 (for example "Slot not available (409)").
9. **Tests script:** at least

```javascript
pm.test("status is 2xx", () => pm.expect(pm.response.code).to.be.within(200, 299));
pm.test("envelope", () => {
  const j = pm.response.json();
  pm.expect(j).to.have.property("success", true);
  pm.expect(j).to.have.property("data");
});
```

   plus the variable saving, for example on login:

```javascript
const j = pm.response.json();
pm.environment.set("admin_token", j.data.token);
```

### 14.5 Example request item (template to copy)

```json
{
  "name": "Create consultant (CON-02)",
  "request": {
    "method": "POST",
    "header": [],
    "url": { "raw": "{{base_url}}/admin/consultants", "host": ["{{base_url}}"], "path": ["admin", "consultants"] },
    "body": {
      "mode": "formdata",
      "formdata": [
        { "key": "name", "value": "أ. خالد المطيري", "type": "text" },
        { "key": "email", "value": "khaled.{{$timestamp}}@gcmc.sa", "type": "text" },
        { "key": "phone", "value": "0551234567", "type": "text" },
        { "key": "title", "value": "مستشار حوكمة", "type": "text" },
        { "key": "specialization", "value": "الحوكمة المؤسسية", "type": "text" },
        { "key": "bio", "value": "خبرة 10 سنوات في الحوكمة", "type": "text" },
        { "key": "is_active", "value": "1", "type": "text" },
        { "key": "photo", "type": "file", "src": "fixtures/avatar.jpg" }
      ]
    },
    "description": "**Permission:** `create-consultants`\n\nCreates a consultant. The role `consultant` is assigned automatically.\n\n| Field | Rules |\n|---|---|\n| name | required, max 150 |\n| email | required, email, unique |\n| phone | optional |\n| photo | optional image jpg/png/webp ≤ 2 MB |\n\n**Errors:** 422 `VALIDATION_ERROR`, 403 `FORBIDDEN`"
  },
  "event": [
    { "listen": "test", "script": { "type": "text/javascript", "exec": [
      "pm.test('201', () => pm.response.to.have.status(201));",
      "const j = pm.response.json();",
      "pm.test('role consultant', () => pm.expect(j.data.roles).to.include('consultant'));",
      "pm.environment.set('consultant_id', j.data.id);"
    ] } }
  ],
  "response": [
    {
      "name": "201 Created",
      "originalRequest": { "method": "POST", "url": { "raw": "{{base_url}}/admin/consultants" } },
      "status": "Created", "code": 201,
      "header": [ { "key": "Content-Type", "value": "application/json" } ],
      "_postman_previewlanguage": "json",
      "body": "{\n  \"success\": true,\n  \"message\": \"تم إنشاء المستشار بنجاح\",\n  \"data\": { \"id\": 12, \"name\": \"أ. خالد المطيري\", \"email\": \"khaled@gcmc.sa\", \"roles\": [\"consultant\"], \"...\": \"...\" }\n}"
    },
    {
      "name": "422 Validation error",
      "originalRequest": { "method": "POST", "url": { "raw": "{{base_url}}/admin/consultants" } },
      "status": "Unprocessable Content", "code": 422,
      "header": [ { "key": "Content-Type", "value": "application/json" } ],
      "_postman_previewlanguage": "json",
      "body": "{\n  \"success\": false,\n  \"message\": \"حقل الاسم مطلوب\",\n  \"error_code\": \"VALIDATION_ERROR\",\n  \"errors\": { \"name\": [\"حقل الاسم مطلوب\"] }\n}"
    }
  ]
}
```

(In the real file, replace `"...": "..."` with the full real response body.)

### 14.6 How to produce the real example bodies and check the collection

1. Start a clean local server:

```bash
cp .env.example .env.postman   # set DB_DATABASE=gcmc_postman, PAYMENT_GATEWAY=fake, MEETING_DRIVER=fake, MAIL_MAILER=log, QUEUE_CONNECTION=sync
php artisan migrate:fresh --seed --env=postman
php artisan serve --env=postman --port=8000
```

2. Run the collection with newman (it must pass 100%):

```bash
npx newman run postman/GCMC-API.postman_collection.json \
  -e postman/GCMC-Local.postman_environment.json \
  --working-dir postman \
  --reporters cli,json --reporter-json-export storage/logs/newman-report.json
```

3. Copy real response bodies from the newman JSON report into the `response` examples (write a small script `scripts/postman-fill-examples.php` or do it by hand; either is fine, but the examples MUST be real responses).
4. Requests that cannot run automatically (reset password with a real token, the Moyasar webhook with a real signature) are kept in the collection with examples, and are skipped in the run by putting them in a sub-folder `Manual only` and running newman with `--folder` for the other folders, or by adding `pm.execution.skipRequest()` in their pre-request script when `pm.environment.get('ci') === 'true'`.
5. `tests/Feature/RouteCoverageTest.php` (10.25) must pass: every route is in the collection.
6. Validate the JSON: `npx newman run` fails on invalid JSON; also check the file with `python3 -m json.tool postman/GCMC-API.postman_collection.json > /dev/null`.

---

## 15. Frontend guide

A second required deliverable: `docs/FRONTEND_API_GUIDE.md`. It is written **after** the backend is done and the Postman collection passes, and it must match the real behaviour. Contents:

1. **Basics:** base URLs per environment, the headers, the response envelope, the error codes table with a suggested UI message per code, pagination/filter/sort params, date/time format, money in halalas and how to show it, the languages.
2. **Authentication per dashboard:** where to store the token, how to attach it, logout, handling 401 (redirect to login) and 403 `ACCOUNT_DISABLED`, forgot/reset password pages (the URLs in the emails).
3. **Permissions in the admin dashboard:** call `/admin/auth/me` after login; hide menu items and buttons using `permissions`; a table **page → required permission** and **button → required permission**; the difference between an admin and a consultant (`type`) and what a consultant sees.
4. **Page-by-page guide** (for each page in 1.1 and 1.2): the endpoints to call, in order, the example request/response, the form fields with the validation rules, and the empty/error states.
5. **Booking wizard guide:** each step → endpoint, what to keep in the state for the summary sidebar, how to build the date picker from PUB-05 and the time buttons from PUB-06, what to do with `requires_payment=false` (skip the payment step), card tokenization with the gateway JS (Moyasar.js example with the `publishable_key` from PUB-08), the 3-D Secure redirect and the callback page that calls CLI-PAY-02, the handling of `SLOT_NOT_AVAILABLE` (reload the slots and ask the user to choose again).
6. **Files:** avatar and report upload with `FormData`, report download with a blob (`Authorization` header) and the file name from `Content-Disposition`.
7. **Status badges:** the colors/labels for `status`, `report_status`, `payment_status`, `meeting.status`.
8. **Postman:** how to import the collection and the environment.

---

## 16. Definition of Done

- [ ] All 10 modules exist and are enabled; no business code in `app/` (except providers).
- [ ] `php artisan migrate:fresh --seed` works on MySQL 8.
- [ ] Every endpoint in section 10 exists with the exact method, URL, permission, validation and response shape.
- [ ] `php artisan route:list --path=api/v1` matches section 10 (no extra undocumented routes).
- [ ] Every endpoint has feature tests for the cases in 12.2 + the extra cases in section 10.
- [ ] Every service/action has unit tests (12.3).
- [ ] `FullBookingJourneyTest` passes.
- [ ] `php artisan test` passes with **0 failures, 0 skipped** (except tests that need real external credentials, which are marked `@group external` and excluded by default).
- [ ] `./vendor/bin/pint --test` passes.
- [ ] No real gateway or Google calls in tests (`Http::preventStrayRequests()` in `TestCase::setUp`).
- [ ] The admin role has all permissions; the consultant sees only his own data (tests prove it).
- [ ] Client and admin tokens do not work on the other guard (tests prove it).
- [ ] Emails: booking confirmation contains the Meet link; the report email contains the dashboard link and the signed link.
- [ ] `postman/GCMC-API.postman_collection.json` + environments + fixtures exist; the newman run passes 100%; every request has real saved examples; `RouteCoverageTest` passes.
- [ ] `docs/FRONTEND_API_GUIDE.md` exists and covers section 15.
- [ ] `README.md` explains: requirements, installation, `.env`, migrate/seed, running the queue worker (`php artisan queue:work`) and the scheduler (`php artisan schedule:work`), running the tests, the Postman import, the demo accounts, and how to switch to the real Moyasar and Google Meet drivers.
- [ ] `docs/DECISIONS.md` lists every place where the implementation had to differ from this plan, and why.
