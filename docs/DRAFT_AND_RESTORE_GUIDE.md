# GCMC Draft (Soft Delete) & Restore Guide

How the two frontends work with **drafted** records ("draft" = soft-deleted)
and how a client keeps using a package after the package was updated or
drafted by an admin.

Read this together with `docs/FRONTEND_API_GUIDE.md` — the base URL, headers,
and the response envelope are the same.

---

## 1. The concept: delete = draft

"Deleting" a main record in the admin dashboard **never destroys data**. It
soft-deletes it (sets `deleted_at`):

- The record disappears from the normal list and cannot log in / be booked.
- All of its data (roles, availability, locations, subscriptions, …) is kept.
- It appears in the **drafted list** (`GET .../trashed`) and can be brought
  back with **restore** (`POST .../{id}/restore`).

There is no force-delete endpoint. Nothing is ever lost.

### 1.1 Which resources support draft + restore

| Resource | Delete = draft? | Drafted list | Restore | Notes |
|---|---|---|---|---|
| **Users** (admins) | ✅ USR-05 | ✅ USR-09 | ✅ USR-10 | Tokens are deleted at draft time; the user logs in again after restore. Roles are kept. |
| **Consultants** | ✅ CON-05 | ✅ CON-19 | ✅ CON-20 | Availability is kept. Blocking rules at draft time: `CONSULTANT_HAS_FUTURE_BOOKINGS`, `CANNOT_DELETE_SELF`, `LAST_ADMIN`. |
| **Clients** | ✅ ADM-CL-05 | ✅ ADM-CL-09 | ✅ ADM-CL-10 | Locations, subscriptions and payment methods are kept. Blocking rule: `CLIENT_HAS_FUTURE_BOOKINGS`. |
| **Packages** | ✅ PKG-05 | ✅ PKG-07 | ✅ PKG-08 | Blocking rule: `PACKAGE_HAS_SUBSCRIPTIONS` (only while ACTIVE subscriptions exist). Existing purchases are never affected — see §4. |
| Reports | ✅ RPT-05 (soft delete) | — | — | Drafting a report also deletes its file and resets the booking to `report_status = pending`, so there is nothing to restore. |
| Client locations / payment methods | ✅ soft delete | — | — | Client-side only; a drafted location still shows on old bookings through the booking's `location_snapshot`. |
| Roles, availability, time-offs, bookings, payments | — hard delete / no delete | — | — | Bookings use *cancel*, not delete. Roles have protection rules instead (`ROLE_PROTECTED`, `ROLE_HAS_USERS`). |

**Restore needs no new permission**: the drafted list uses the resource's
`view-*` permission, restore uses its `delete-*` permission (whoever can
draft a record can un-draft it).

---

## 2. The drafted list endpoints

All are admin-side, paginated (15 per page by default, `per_page` max 100),
sorted by most recently drafted first (`-deleted_at`).

| ID | Endpoint | Permission | Query params |
|---|---|---|---|
| USR-09 | `GET /admin/users/trashed` | `view-users` | `search` (name/email/phone), `type` (`admin`\|`consultant`), `sort`, `per_page` |
| CON-19 | `GET /admin/consultants/trashed` | `view-consultants` | `search` (name/email/phone/specialization), `specialization`, `sort`, `per_page` |
| ADM-CL-09 | `GET /admin/clients/trashed` | `view-clients` | `search` (name/email/phone/company), `sort`, `per_page` |
| PKG-07 | `GET /admin/packages/trashed` | `view-packages` | `search` (name_ar/name_en/slug), `sort`, `per_page` — each row includes `subscriptions_count` |

Notes:

- A drafted consultant/user/client **cannot hold a token**, so these lists are
  effectively admins-only content. A logged-in consultant calling
  `GET /admin/clients/trashed` sees only the clients he had bookings with.
- Every item includes **`deleted_at`** (when it was drafted). Live records
  return `deleted_at: null` everywhere else, so you can safely branch on it.

Example:

```
GET /api/v1/admin/clients/trashed?search=Acme
Authorization: Bearer <admin token>
```

```json
{
  "success": true,
  "message": "OK",
  "data": [
    {
      "id": 3,
      "name": "سعد العتيبي",
      "email": "saad@acme.sa",
      "phone": "0551234567",
      "company_name": "Acme Ltd",
      "avatar_url": null,
      "avatar_thumb_url": null,
      "is_active": true,
      "last_login_at": "2026-09-18T12:00:00+03:00",
      "created_at": "2026-09-01T10:00:00+03:00",
      "updated_at": "2026-09-20T09:00:00+03:00",
      "deleted_at": "2026-09-20T09:00:00+03:00"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1 },
  "links": { "first": "...", "last": "...", "prev": null, "next": null }
}
```

---

## 3. Restore endpoints

| ID | Endpoint | Permission | Success |
|---|---|---|---|
| USR-10 | `POST /admin/users/{id}/restore` | `delete-users` | 200 + the restored `UserResource` (`deleted_at: null`) |
| CON-20 | `POST /admin/consultants/{id}/restore` | `delete-consultants` | 200 + `ConsultantResource` |
| ADM-CL-10 | `POST /admin/clients/{id}/restore` | `delete-clients` | 200 + `ClientResource` |
| PKG-08 | `POST /admin/packages/{id}/restore` | `delete-packages` | 200 + `PackageResource` (with `subscriptions_count`) |

Errors:

| HTTP | `error_code` | When |
|---|---|---|
| 404 | `NOT_FOUND` | Unknown id (or, for consultants, the id belongs to a non-consultant user) |
| 422 | `NOT_DRAFTED` | The record is live, not drafted |
| 401 / 403 | `UNAUTHENTICATED` / `FORBIDDEN` | No token / no permission |

What restore does **not** do (by design):

- It does **not** re-create tokens — the user/consultant/client simply logs
  in again (the password is unchanged).
- It does **not** re-publish a package — `is_active` keeps the value it had.
  Call `PATCH /admin/packages/{id}/status` afterwards if you want it visible
  in the pricing page again.
- It does **not** un-cancel bookings — bookings have their own lifecycle.

Example:

```
POST /api/v1/admin/clients/3/restore
Authorization: Bearer <admin token>
```

```json
{
  "success": true,
  "message": "تمت الاستعادة بنجاح.",
  "data": { "id": 3, "company_name": "Acme Ltd", "deleted_at": null, "...": "..." }
}
```

### 3.1 Suggested UI

- Each admin list page gets a **"المسودات" (Drafts)** tab:
  count badge from `meta.total` of the trashed endpoint.
- Rows in the drafts tab show `deleted_at` and a single **"استعادة"**
  (Restore) button (visible only when the user has the `delete-*`
  permission — you already know the permissions from `/admin/auth/me`).
- Keep the normal delete button on live rows; deleting from the drafts tab
  is not possible (there is no force delete).
- On `NOT_DRAFTED`, silently refresh the list (someone else restored it first).

---

## 4. Packages: purchases are frozen at purchase time

A client who already bought a package **keeps exactly what he paid for**,
no matter what the admin later does to the package:

1. **Price change** → existing subscriptions keep the old price
   (`price_paid`) and quota (`consultations_limit`). A client whose
   subscription expired pays the **new** price for the next purchase.
2. **Quota / name / details change** → existing subscriptions and bookings
   keep showing the old name and the old quota. Every subscription and
   booking stores a `package_snapshot`
   (`{id, slug, name_ar, name_en}`) at purchase time.
3. **Package deactivated or drafted (deleted)** → the client can still:
   - see the subscription with the remaining quota
     (`GET /client/subscriptions/active`),
   - quote a new consultation → `requires_payment: false`, `amount: 0`,
   - **book the remaining consultations** of the package normally.
   Only a **new purchase** of an inactive/drafted package is rejected
   (422 `PACKAGE_INACTIVE`).

So: "the state the client bought keeps applying — updates and deletes on
the package never reflect on purchases that already happened."

### 4.1 Booking the 2nd, 3rd, … consultation of a purchased package

The wizard's step 1 (public pricing list) only shows **active** packages. To
book another consultation inside an already-purchased package, take the
`package_id` from the client's **own subscriptions**, not from the pricing
page:

```
GET /api/v1/client/subscriptions/active        → data[].package.id + consultations_remaining
POST /api/v1/client/bookings/quote             → requires_payment: false, amount: 0
POST /api/v1/client/bookings                   → 201, status: pending, amount: 0, no payment
```

- `POST /client/bookings` needs **no payment fields** when the active
  subscription covers it (`payment: null` in the response).
- Each covered booking consumes one consultation
  (`consultations_used + 1`). Cancelling it gives the consultation back.
- When the quota is exhausted (or the subscription expired), the next quote
  automatically flips to `requires_payment: true` with the package's
  **current** price.

Suggested UI: on the wizard's package step, put the client's active
subscriptions at the top ("لديك استشارتان متبقيتان في الباقة الفضية") and
let him continue with one click; when a package was drafted/deactivated, the
subscription card still works (it carries its own name/price/quota).
