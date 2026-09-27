# GCMC Frontend Implementation Plan (Next.js + React + TypeScript)

> **Audience:** an AI coding agent (or junior developer) that will build the whole frontend **step by step**.
> **Rule #1:** Follow this document in order. Do not skip phases. Do not rename routes, query keys, storage keys, components or field names. The backend API contract is fixed; this document matches it exactly.
> **Rule #2:** A phase is DONE only when `npm run build` passes with no TypeScript errors **and** the phase's acceptance checklist is ticked.
> **Rule #3:** This document is self-contained — **Appendix C (§19) is a complete API reference generated from the Postman collection**, with every endpoint's method, path, auth, query params, body and a real example response. You do not need to open any other file. (Background reading if available: `docs/FRONTEND_API_GUIDE.md`.)
> **Rule #4:** The visual **theme is provided separately** (logo, colors, fonts). Map it into the CSS variables in §3. Never hard-code colors or fonts in components.

---

## Table of Contents

0. [How to work with this plan](#0-how-to-work-with-this-plan)
1. [What you are building](#1-what-you-are-building)
2. [Tech stack and project setup](#2-tech-stack-and-project-setup)
3. [Theme integration (CSS variables contract)](#3-theme-integration-css-variables-contract)
4. [Languages (ar/en) and RTL](#4-languages-aren-and-rtl)
5. [The API layer](#5-the-api-layer)
6. [Authentication](#6-authentication)
7. [Permissions in the admin area](#7-permissions-in-the-admin-area)
8. [Design system — primitives to build first](#8-design-system--primitives-to-build-first)
9. [Route map and layouts](#9-route-map-and-layouts)
10. [Public pages](#10-public-pages)
11. [The booking wizard (core flow)](#11-the-booking-wizard-core-flow)
12. [Client dashboard pages](#12-client-dashboard-pages)
13. [Admin dashboard pages](#13-admin-dashboard-pages)
14. [File uploads and downloads](#14-file-uploads-and-downloads)
15. [Status badges](#15-status-badges)
16. [Error handling master table](#16-error-handling-master-table)
17. [Appendix A — TypeScript types for every API resource](#17-appendix-a--typescript-types-for-every-api-resource)
18. [Appendix B — Zod schemas for every form](#18-appendix-b--zod-schemas-for-every-form)
19. [Appendix C — Complete API reference (from the Postman collection)](#19-appendix-c--complete-api-reference-from-the-postman-collection)
20. [Build phases and acceptance checklists](#20-build-phases-and-acceptance-checklists)
21. [Definition of Done](#21-definition-of-done)

---

## 0. How to work with this plan

1. Read sections 1–9 **completely** before writing any code. They are the foundation.
2. Section 20 is the ordered to-do list. Execute phase by phase.
3. After every phase: run `npm run build` and fix every TypeScript error, then tick the acceptance list.
4. When this document says **MUST**, it is non-negotiable. When it says **SHOULD**, do it unless it is impossible.
5. Never invent endpoints, field names or response shapes. Every API call in this document exists in the backend and in the Postman collection.
6. Money is **integer halalas**; every money field from the API has a `*_formatted` twin. **Always display the `*_formatted` value. Never format money yourself.** (Exception: the admin package form, see §13 Packages — there you convert SAR input to halalas before sending.)
7. All dates/times are **Asia/Riyadh**. The API returns `date` (`2026-09-23`) and `time` (`09:00`) display fields on bookings — use them directly.

---

## 1. What you are building

GCMC sells **consultancy packages** to companies. A client buys a package, books a 30-minute online session with a consultant (Google Meet), and after the session the consultant uploads a report that the client downloads.

You are building **one Next.js application** that hosts three areas:

| Area | Routes | Who | Backend guard |
|---|---|---|---|
| Public site + booking wizard | `/`, `/packages`, `/consultants`, `/book/...` | anyone | none |
| Client dashboard | `/dashboard`, `/bookings`, `/reports`, `/my-packages`, `/locations`, `/payment-methods`, `/profile` | companies (rows in `clients`) | `client` |
| Admin dashboard | `/admin/...` | staff (`type=admin`) and consultants (`type=consultant`) — both are rows in `users` | `admin` |

### 1.1 Backend base URLs

| Environment | Base URL (`NEXT_PUBLIC_API_URL`) |
|---|---|
| Local | `http://localhost:8000/api/v1` |
| Staging | `https://staging-api.gcmc.sa/api/v1` |

### 1.2 URL contract with the backend (MUST)

The backend sends emails and payment redirects to fixed frontend URLs. These routes **MUST exist at exactly these paths**:

| URL | Page | Why |
|---|---|---|
| `/reset-password?token=…&email=…` | client reset password | password-reset email link |
| `/admin/reset-password?token=…&email=…` | admin/consultant reset password | password-reset email link |
| `/reports/[id]` | client report details | report-ready email button |
| `/bookings/payment-callback` | 3-D Secure return page | Moyasar redirects here after 3DS |

The backend `.env` for local development must therefore be:

```dotenv
ADMIN_FRONTEND_URL=http://localhost:3000/admin
CLIENT_FRONTEND_URL=http://localhost:3000
PAYMENT_CALLBACK_URL=http://localhost:3000/bookings/payment-callback
CORS_ALLOWED_ORIGINS=http://localhost:3000
```

### 1.3 Demo accounts (local, after `php artisan migrate:fresh --seed`)

| Who | Email | Password |
|---|---|---|
| Admin | `admin@gcmc.sa` | `Password@123` |
| Consultant | `ahmad.alotaibi@gcmc.sa` | `Password@123` |
| Client | `client@gcmc.sa` | `Password@123` |

### 1.4 The two user types in the admin area

- `admin` — staff. Sees everything (menu items gated by permissions, §7).
- `consultant` — sees **only his own** bookings/clients/reports/payments (the backend scopes the data; another consultant's record returns 403/404). His default menu: Dashboard, Bookings, Reports, Clients, My availability, Profile.

---

## 2. Tech stack and project setup

Use exactly these:

| Item | Version | Why |
|---|---|---|
| Next.js | **15.x** (App Router) | framework |
| React | **19.x** | comes with Next 15 |
| TypeScript | **5.x, `strict: true`** | type safety |
| Tailwind CSS | **v4** (CSS-first config) | styling |
| TanStack Query | **v5** | all server state (fetching, caching, mutations) |
| axios | latest | HTTP client (interceptors for auth/locale/errors) |
| react-hook-form + zod + @hookform/resolvers | latest | every form |
| zustand | latest | small client state (auth stores, wizard store) |
| next-intl | latest | ar/en messages (cookie-based, **no** locale routing) |
| dayjs | latest | date display helpers |

Do **not** add a component library (no MUI, no Ant Design, no shadcn). Build the primitives in §8 — they are small and the theme maps onto them cleanly.

### 2.1 Setup commands

```bash
npx create-next-app@15 gcmc-frontend --typescript --tailwind --eslint --app --src-dir --import-alias "@/*" --use-npm
cd gcmc-frontend
npm i axios @tanstack/react-query zustand react-hook-form zod @hookform/resolvers next-intl dayjs
```

### 2.2 `.env.local`

```dotenv
NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1
```

### 2.3 Rendering model (important simplification)

Tokens live in `localStorage`, so **every page is a client component** (`'use client'` at the top). Next.js is used for routing and bundling only — no server components fetching data, no middleware auth. Route protection happens in client components (§6.4). This keeps one simple mental model: *page → TanStack Query → API → render*.

### 2.4 Folder structure (create exactly this)

```
src/
├── app/
│   ├── layout.tsx                  # <html lang dir>, fonts, <Providers>
│   ├── globals.css                 # theme variables (§3)
│   ├── page.tsx                    # redirects to /packages
│   ├── packages/page.tsx           # public pricing
│   ├── consultants/page.tsx        # public consultant list
│   ├── consultants/[id]/page.tsx   # public consultant profile
│   ├── book/[packageSlug]/page.tsx # the booking wizard
│   ├── login/page.tsx              # client login
│   ├── register/page.tsx           # client register
│   ├── forgot-password/page.tsx    # client
│   ├── reset-password/page.tsx     # client (URL contract!)
│   ├── bookings/
│   │   ├── page.tsx                # client: my bookings
│   │   ├── [id]/page.tsx           # client: booking details
│   │   └── payment-callback/page.tsx  # 3DS return (URL contract!)
│   ├── dashboard/page.tsx          # client home
│   ├── reports/
│   │   ├── page.tsx                # client: my reports
│   │   └── [id]/page.tsx           # client: report details (URL contract!)
│   ├── my-packages/page.tsx        # client: subscriptions
│   ├── locations/page.tsx          # client: locations CRUD
│   ├── payment-methods/page.tsx    # client: saved cards
│   ├── profile/page.tsx            # client profile
│   └── admin/
│       ├── login/page.tsx
│       ├── forgot-password/page.tsx
│       ├── reset-password/page.tsx # URL contract!
│       ├── layout.tsx              # admin shell (sidebar) + RequireAdmin
│       ├── page.tsx                # dashboard home (stats)
│       ├── roles/page.tsx
│       ├── users/page.tsx
│       ├── consultants/page.tsx
│       ├── consultants/[id]/page.tsx   # tabs: profile/availability/time-off/clients/bookings/reports
│       ├── my-availability/page.tsx    # consultant self-service
│       ├── bookings/page.tsx
│       ├── bookings/calendar/page.tsx
│       ├── bookings/[id]/page.tsx
│       ├── reports/page.tsx
│       ├── clients/page.tsx
│       ├── clients/[id]/page.tsx
│       ├── packages/page.tsx
│       ├── payments/page.tsx
│       └── profile/page.tsx
├── components/
│   ├── ui/                         # primitives (§8)
│   ├── forms/                      # shared form pieces (Field, SubmitButton…)
│   ├── layout/                     # Sidebar, Topbar, PublicHeader, Footer
│   ├── auth/                       # RequireAdmin, RequireClient, guards
│   ├── bookings/                   # BookingCard, BookingStatusBadge, CancelDialog…
│   ├── wizard/                     # the 7 wizard step components
│   ├── payments/                   # CardForm (Moyasar/fake), SavedCardRadio…
│   └── consultants/                # AvailabilityEditor, TimeOffManager, SlotPicker…
├── lib/
│   ├── api.ts                      # axios instance + interceptors (§5.1)
│   ├── query-client.ts
│   ├── errors.ts                   # ApiError type + normalize (§5.2)
│   ├── files.ts                    # upload/download helpers (§14)
│   ├── form-data.ts                # nested-object → FormData (bracket notation)
│   ├── permissions.ts              # usePermissions, <Can> (§7)
│   └── utils.ts                    # cn(), small helpers
├── hooks/                          # useDebounce, useToast…
├── stores/
│   ├── admin-auth.ts               # zustand persisted (admin_token)
│   ├── client-auth.ts              # zustand persisted (client_token)
│   └── wizard.ts                   # booking wizard state (§11.1)
├── types/api.ts                    # Appendix A types
├── schemas/                        # Appendix B zod schemas, one file per form
├── i18n/
│   ├── request.ts                  # next-intl config (cookie)
│   └── locale.ts                   # get/set locale cookie helpers
└── messages/
    ├── ar.json
    └── en.json
```

---

## 3. Theme integration (CSS variables contract)

The theme (colors, logo, font) is handed to you separately. Your job: map it into `src/app/globals.css` as CSS variables and Tailwind v4 `@theme` tokens. **Components must only use semantic Tailwind classes** (`bg-primary`, `text-muted`, `border-border`, …) — never hex values.

`globals.css` skeleton (the theme provides the values):

```css
@import "tailwindcss";

@theme {
  --color-primary: <theme>;
  --color-primary-foreground: <theme>;
  --color-secondary: <theme>;
  --color-accent: <theme>;
  --color-background: <theme>;
  --color-surface: <theme>;        /* cards, tables */
  --color-border: <theme>;
  --color-text: <theme>;
  --color-muted: <theme>;          /* secondary text */
  --color-success: <theme>;  --color-success-soft: <theme>;
  --color-warning: <theme>;  --color-warning-soft: <theme>;
  --color-danger:  <theme>;  --color-danger-soft:  <theme>;
  --color-info:    <theme>;  --color-info-soft:    <theme>;
  --radius: 0.75rem;
  --font-sans: "IBM Plex Sans Arabic", ui-sans-serif, system-ui, sans-serif;
}
```

- Default font: **IBM Plex Sans Arabic** via `next/font/google` (covers Arabic + Latin). If the theme specifies another font, use it instead.
- Logo: put the theme logo at `public/logo.svg` and render it through one `<Logo />` component. Never hard-code the company name as an image replacement.
- Status colors (success/warning/danger/info) are used by `<StatusBadge>` (§15). If the theme does not define them, use: green / amber / red / blue (Tailwind `emerald-600`, `amber-600`, `red-600`, `blue-600` + their `50` soft backgrounds).
- Dark mode: only if the theme asks for it. Default: light.

---

## 4. Languages (ar/en) and RTL

The product is **Arabic-first** (`ar` default) with an English switcher.

### 4.1 next-intl, cookie-based (no locale in the URL)

`src/i18n/locale.ts`:

```ts
export type Locale = 'ar' | 'en';
export const LOCALES: Locale[] = ['ar', 'en'];
export const DEFAULT_LOCALE: Locale = 'ar';

export function getLocale(): Locale {
  if (typeof document === 'undefined') return DEFAULT_LOCALE;
  const match = document.cookie.match(/(?:^|;\s*)NEXT_LOCALE=(ar|en)/);
  return (match?.[1] as Locale) ?? DEFAULT_LOCALE;
}

export function setLocale(locale: Locale) {
  document.cookie = `NEXT_LOCALE=${locale};path=/;max-age=31536000`;
}
```

`src/i18n/request.ts` (next-intl server config):

```ts
import { getRequestConfig } from 'next-intl/server';
import { cookies } from 'next/headers';

export default getRequestConfig(async () => {
  const store = await cookies();
  const locale = store.get('NEXT_LOCALE')?.value === 'en' ? 'en' : 'ar';
  return { locale, messages: (await import(`../../messages/${locale}.json`)).default };
});
```

`next.config.ts`: wrap with `createNextIntlPlugin('./src/i18n/request.ts')`.

`app/layout.tsx` (server component) reads the cookie and sets the document direction:

```tsx
const locale = (await cookies()).get('NEXT_LOCALE')?.value === 'en' ? 'en' : 'ar';
<html lang={locale} dir={locale === 'ar' ? 'rtl' : 'ltr'}>
```

Wrap the tree in `<NextIntlClientProvider>` and your `<QueryClientProvider>` inside a `<Providers>` client component.

The **language switcher** (in both headers) calls `setLocale()` then `router.refresh()` and **also** invalidates all TanStack queries (`queryClient.invalidateQueries()`), because API `message`/`*_label` fields come translated from the backend via the `Accept-Language` header (§5.1 reads the same cookie).

### 4.2 Message files

`messages/ar.json` and `messages/en.json` MUST always contain the same keys. Organize by namespace per area, e.g.:

```json
{
  "common": { "save": "حفظ", "cancel": "إلغاء", "confirm": "تأكيد", "delete": "حذف", "edit": "تعديل", "search": "بحث", "loading": "جارٍ التحميل…", "empty": "لا توجد بيانات", "retry": "إعادة المحاولة", "back": "رجوع", "next": "التالي", "logout": "تسجيل الخروج" },
  "nav": { "dashboard": "الرئيسية", "bookings": "الحجوزات", "reports": "التقارير", "clients": "العملاء", "packages": "الباقات", "payments": "المدفوعات", "users": "المستخدمون", "roles": "الأدوار والصلاحيات", "consultants": "المستشارون", "profile": "الملف الشخصي", "my_availability": "مواعيدي" },
  "auth": { "login": "تسجيل الدخول", "email": "البريد الإلكتروني", "password": "كلمة المرور" }
}
```

### 4.3 What is translated where (MUST)

- **From the API** (never re-translate locally): all `*_label` fields (`status_label`, …), permission/role labels, package `name`/`description`/`features_localized`, day names (`day_name`, `day_name_ar`), and every `message` in responses.
- **From the message files**: UI chrome only — menu items, buttons, page titles, form labels, empty states, toasts you trigger yourself.
- Translatable models also return raw `name_ar`/`name_en` etc. — you can flip those client-side without re-fetching if you stored them.

### 4.4 RTL rules

- Tailwind: use **logical properties only** — `ms-*`/`me-*`/`ps-*`/`pe-*`/`text-start`/`text-end`/`start-0`/`end-0`. Never `ml/mr/pl/pr/left/right/text-left/text-right`.
- Directional icons (arrows, chevrons in "next/back") must flip: add class `rtl:-scale-x-100`.
- Phone numbers, card numbers and the `HH:MM` time are always LTR: wrap them in `<bdi>` or add `dir="ltr"`.

---

## 5. The API layer

### 5.1 The axios instance (`src/lib/api.ts`)

```ts
import axios from 'axios';
import { getLocale } from '@/i18n/locale';
import { useAdminAuth } from '@/stores/admin-auth';
import { useClientAuth } from '@/stores/client-auth';

export const api = axios.create({ baseURL: process.env.NEXT_PUBLIC_API_URL });

api.interceptors.request.use((config) => {
  config.headers.Accept = 'application/json';
  config.headers['Accept-Language'] = getLocale();

  const url = config.url ?? '';
  const token = url.startsWith('/admin')
    ? useAdminAuth.getState().token
    : url.startsWith('/client')
      ? useClientAuth.getState().token
      : null;                       // /public/* and /webhooks/* send no token
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});
```

Rules:

- The **guard is chosen by URL prefix**: `/admin/*` → `admin_token`, `/client/*` → `client_token`, everything else → no token. A token of the wrong guard gets a 401 from the backend — never send one.
- **Never set `Content-Type` for FormData** — the browser adds the boundary (§14).
- JSON bodies: `Content-Type: application/json` (axios default for objects — fine).

### 5.2 Error normalization (`src/lib/errors.ts`)

Every API error is normalized to:

```ts
export type ApiError = {
  status: number;                 // HTTP status (0 = network error)
  code: string;                   // error_code, e.g. 'SLOT_NOT_AVAILABLE', or 'NETWORK_ERROR'
  message: string;                // translated, safe to toast
  errors: Record<string, string[]>; // field errors for VALIDATION_ERROR, else {}
};
```

The response interceptor:

1. `401` → clear the token of the guard that was used (by URL prefix) and redirect: admin URL → `/admin/login`, client URL → `/login`. (No refresh tokens exist.)
2. Everything else → `Promise.reject(normalize(error))`.
3. Network failure (no response) → `{ status: 0, code: 'NETWORK_ERROR', … }`.

### 5.3 The response envelope

Success: `{ success: true, message, data }` (+ `meta`/`links` on paginated lists).
Error: `{ success: false, message, error_code, errors }`.

- Branch on **`error_code`** (stable), never on `message` (translated).
- `422 VALIDATION_ERROR` → show `errors[field][0]` under each form field (react-hook-form `setError`); toast `message` as fallback.
- Paginated lists: `data` is the array, `meta = { current_page, per_page, total, last_page }`.

### 5.4 TanStack Query conventions

- Query keys: `['area', 'resource', params…]` — e.g. `['admin', 'bookings', { status, page }]`, `['client', 'locations']`, `['public', 'slots', consultantId, date]`. Consistent keys make invalidation work.
- Default options: `retry: (count, err) => count < 1 && err.status >= 500`, `staleTime: 30_000`, `refetchOnWindowFocus: false`.
- After a mutation, invalidate the affected keys (e.g. creating a location invalidates `['client', 'locations']`).
- Mutations: `onError: (e: ApiError) => …` — `VALIDATION_ERROR` → field errors; anything else → `toast.error(e.message)` plus the code-specific handling in §16.

### 5.5 Pagination / filtering / sorting params

Every list endpoint accepts: `page`, `per_page` (1–100, default 15), `search`, `sort` (`-` prefix = desc), plus per-endpoint filters (listed per page in §12/§13). Keep filter state in the page component (or URL search params with `useSearchParams` — preferred for list pages so filters survive refresh).

---

## 6. Authentication

Two independent auth stores (zustand, persisted to `localStorage`):

```ts
// stores/client-auth.ts — same shape for admin-auth.ts
type ClientAuthState = {
  token: string | null;
  user: ClientMe | null;
  setAuth: (token: string, user: ClientMe) => void;
  setUser: (user: ClientMe) => void;
  clear: () => void;
};
```

- Storage keys: the zustand persist keys are `client_auth` and `admin_auth`. (The raw token keys the API layer reads come from these stores — do not create extra copies.)
- Login/register bodies include `"device_name": "web"`.

### 6.1 Client auth

| Action | Endpoint | Body |
|---|---|---|
| Register | `POST /client/auth/register` | `name`, `email`, `phone` (`05XXXXXXXX`), `company_name`, `password`, `password_confirmation`, `device_name` → 201 `{ token, user }` |
| Login | `POST /client/auth/login` | `email`, `password`, `device_name` → `{ token, user }` |
| Me | `GET /client/auth/me` | → client + `active_subscriptions[]`, `default_location`, `default_payment_method` |
| Logout | `POST /client/auth/logout` | then `clear()` |

### 6.2 Admin auth

| Action | Endpoint | Notes |
|---|---|---|
| Login | `POST /admin/auth/login` | → `{ token, user }`; `user.type` = `admin` \| `consultant`, `user.permissions[]` |
| Me | `GET /admin/auth/me` | call on app boot to re-hydrate; the source of truth for permissions |
| Logout | `POST /admin/auth/logout` | then `clear()` |

Login errors: `422 INVALID_CREDENTIALS` → "بيانات الدخول غير صحيحة" under the email field; `403 ACCOUNT_DISABLED` → blocking alert "الحساب معطّل، تواصل مع الإدارة".

### 6.3 Forgot / reset password (both areas)

```
POST /admin/auth/forgot-password   { email }     → always 200 (never leaks existence)
POST /admin/auth/reset-password    { token, email, password, password_confirmation }
POST /client/auth/forgot-password  { email }
POST /client/auth/reset-password   { token, email, password, password_confirmation }
```

- Forgot page: email field → success screen "check your inbox" (always, even for unknown emails).
- Reset pages live at `/reset-password` (client) and `/admin/reset-password` (admin) — **URL contract**. Read `token` + `email` from the query string, post them with the new password.
- Success → all old tokens are revoked → redirect to the login page with a success toast.
- Expired/invalid token → `422 VALIDATION_ERROR` with the reason under `errors.email` → show "الرابط منتهي، اطلب رابطاً جديداً" with a link back to forgot-password.

### 6.4 Route protection (client components)

```tsx
// components/auth/RequireClient.tsx — same idea for RequireAdmin
export function RequireClient({ children }: { children: React.ReactNode }) {
  const { token, setUser } = useClientAuth();
  const router = useRouter();
  const me = useQuery({
    queryKey: ['client', 'me'],
    queryFn: () => api.get('/client/auth/me').then((r) => r.data.data),
    enabled: !!token,
    retry: false,
  });

  useEffect(() => { if (!token) router.replace('/login'); }, [token, router]);
  // keep the store fresh (default_location, subscriptions…)
  useEffect(() => { if (me.data) setUser(me.data); }, [me.data, setUser]);

  if (!token) return null;
  if (me.isLoading) return <PageLoader />;
  if (me.isError) return null; // the 401 interceptor already redirected
  return <>{children}</>;
}
```

- Wrap the client area pages (or a shared client layout) in `<RequireClient>`, and the whole `/admin/layout.tsx` in `<RequireAdmin>`.
- Admin pages additionally check **page permissions** (§7): if `user.permissions` lacks the page permission, render the 403 empty state instead of the page.

---

## 7. Permissions in the admin area

After login (and on every boot), `GET /admin/auth/me` gives `user.permissions` (flat string array) and `user.type`. The backend enforces everything — hidden UI is for UX only.

```tsx
// lib/permissions.ts
export const usePermissions = () => {
  const user = useAdminAuth((s) => s.user);
  const perms = new Set(user?.permissions ?? []);
  return {
    type: user?.type,                       // 'admin' | 'consultant'
    can: (p: string) => perms.has(p),
    canAny: (...ps: string[]) => ps.some((p) => perms.has(p)),
  };
};

export function Can({ perm, children }: { perm: string; children: React.ReactNode }) {
  const { can } = usePermissions();
  return can(perm) ? <>{children}</> : null;
}
```

### 7.1 Page → required permission (hide the menu item without it)

| Page | Permission |
|---|---|
| Dashboard home | `view-dashboard` |
| Roles & Permissions | `view-roles` (the permissions picker also needs `view-permissions`) |
| Users | `view-users` |
| Consultants | `view-consultants` |
| Consultant details tab: Availability / Time off | `view-availability` |
| Consultant details tab: Clients | `view-clients` |
| Consultant details tab: Bookings | `view-bookings` |
| Consultant details tab: Pending reports / Reports | `view-reports` |
| Bookings | `view-bookings` |
| Reports | `view-reports` |
| Clients | `view-clients` |
| Packages | `view-packages` |
| Payments | `view-payments` |
| Profile | — (everyone) |
| My availability (consultant) | `view-availability` |

### 7.2 Button/action → required permission (hide or disable)

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

### 7.3 Admin vs consultant

- A consultant's lists are scoped server-side; the consultants list returns only himself.
- The dashboard stats response for a consultant has **no `consultants` and no `revenue` keys** — check for their presence before rendering those cards.
- Consultant default permissions: `view-dashboard`, `view-bookings`, `complete-bookings`, `cancel-bookings`, `view-reports`, `upload-reports`, `download-reports`, `view-clients`, `view-availability`, `manage-availability`.

---

## 8. Design system — primitives to build first

Build these in `src/components/ui/` before any page. Keep each one small and typed. Every primitive uses the theme tokens only.

| Component | Props (essentials) | Notes |
|---|---|---|
| `Button` | `variant: 'primary'\|'secondary'\|'outline'\|'danger'\|'ghost'`, `size: 'sm'\|'md'\|'lg'`, `loading?: boolean`, `disabled`, `type` | `loading` shows a spinner and disables |
| `Input` | `label`, `error`, `hint`, `dir?` | register with react-hook-form |
| `Textarea` | same | |
| `Select` | native `<select>` styled, `options: {value,label}[]` | |
| `Checkbox`, `Radio`, `Switch` | `label` | |
| `Field` | wraps label + control + `error` + `hint` | |
| `Card` | `title?`, `actions?` | surface bg |
| `Badge` | `color: 'gray'\|'green'\|'amber'\|'red'\|'blue'\|'purple'` | soft bg + solid text |
| `StatusBadge` | `kind: 'booking'\|'report'\|'payment'\|'bookingPayment'\|'refund'\|'meeting'\|'subscription'`, `value: string`, `label: string` | maps value→color per §15; **label comes from the API** |
| `Modal` | `open`, `onClose`, `title`, `size?` | focus trap, Esc closes, RTL-aware |
| `ConfirmDialog` | `open`, `title`, `body`, `confirmLabel`, `danger?`, `loading?`, `onConfirm` | use for every destructive action |
| `Drawer` | side panel (end side) | role users drawer |
| `Tabs` | `tabs: {key,label}[]`, controlled | used by consultant/client details |
| `Table` + `TableSkeleton` | typed columns config | min-widths, `overflow-x-auto` |
| `Pagination` | `meta: {current_page,last_page,total}`, `onPage` | "Page X of Y (total)" |
| `SearchInput` | debounced 400 ms | |
| `DatePicker` | month grid; `enabledDates?: string[]` (only these selectable), `minDate?`, `onSelect` | custom build; used by the wizard (PUB-05 dates) |
| `SlotPicker` | `slots: Slot[]`, `value`, `onSelect` | one button per `time` |
| `Toast` / `useToast` | `success`, `error`, `info` | top-start corner |
| `Spinner`, `PageLoader` | | |
| `EmptyState` | `icon?`, `title`, `body?`, `action?` | every list needs one |
| `ErrorState` | `onRetry` | query error fallback |
| `Avatar` | `src?`, `name`, `size` | fallback: initials on primary bg |
| `FileDrop` | `accept`, `maxMb`, `onFile`, `error` | drag & drop + click; client-side size/mime check |
| `StatCard` | `label`, `value`, `icon?`, `href?` | dashboard counters |
| `PageHeader` | `title`, `subtitle?`, `actions?` | |
| `Alert` | `color`, `title?`, `body` | inline warnings (e.g. refund requested) |
| `PriceTag` | `formatted: string` | renders the API's `*_formatted` string |
| `CopyButton` | `text` | copy Meet link |

Every list page follows the same skeleton:

```tsx
<PageHeader title={…} actions={…} />
<FiltersBar … />
{isLoading ? <TableSkeleton /> : isError ? <ErrorState onRetry={refetch} /> :
 data.length === 0 ? <EmptyState … /> : <Table … />}
<Pagination meta={meta} onPage={setPage} />
```

---

## 9. Route map and layouts

### 9.1 Complete route table

| Path | Page | Guard | Permission |
|---|---|---|---|
| `/` | redirect to `/packages` | — | — |
| `/packages` | public pricing (wizard step 1) | — | — |
| `/consultants` | public consultant list | — | — |
| `/consultants/[id]` | public consultant profile | — | — |
| `/book/[packageSlug]` | booking wizard (steps 2–7) | client after step 2 | — |
| `/bookings/payment-callback` | 3-D Secure return | client | — |
| `/login`, `/register`, `/forgot-password`, `/reset-password` | client auth | guest | — |
| `/dashboard` | client home | client | — |
| `/bookings` | my bookings | client | — |
| `/bookings/[id]` | booking details | client | — |
| `/reports` | my reports | client | — |
| `/reports/[id]` | report details (email link target) | client | — |
| `/my-packages` | my subscriptions | client | — |
| `/locations` | my locations | client | — |
| `/payment-methods` | my cards | client | — |
| `/profile` | client profile | client | — |
| `/admin/login`, `/admin/forgot-password`, `/admin/reset-password` | admin auth | guest | — |
| `/admin` | dashboard home | admin | `view-dashboard` |
| `/admin/roles` | roles & permissions | admin | `view-roles` |
| `/admin/users` | users | admin | `view-users` |
| `/admin/consultants` | consultants list | admin | `view-consultants` |
| `/admin/consultants/[id]` | consultant details (tabs) | admin | `view-consultants` + tab perms (§7.1) |
| `/admin/my-availability` | consultant self-service | admin (consultant) | `view-availability` |
| `/admin/bookings` | bookings list | admin | `view-bookings` |
| `/admin/bookings/calendar` | bookings calendar | admin | `view-bookings` |
| `/admin/bookings/[id]` | booking details + actions | admin | `view-bookings` |
| `/admin/reports` | reports list | admin | `view-reports` |
| `/admin/clients` | clients list | admin | `view-clients` |
| `/admin/clients/[id]` | client details (tabs) | admin | `view-clients` |
| `/admin/packages` | packages CRUD | admin | `view-packages` |
| `/admin/payments` | payments list | admin | `view-payments` |
| `/admin/profile` | own profile | admin | — |

### 9.2 Layouts

- **PublicLayout** (wraps `/packages`, `/consultants`, auth pages): top bar with `<Logo />`, links (Packages, Consultants), language switcher, "Login" / "Create account" buttons; simple footer. If a client token exists, show "My dashboard" linking to `/dashboard`.
- **ClientLayout** (wraps the client pages): top bar + side/bottom nav with: Dashboard `/dashboard`, My bookings `/bookings`, My reports `/reports` (badge = unread count from CLI-DSH-01), My packages `/my-packages`, Locations `/locations`, Payment methods `/payment-methods`, Profile `/profile`. Wrap in `<RequireClient>`.
- **AdminLayout** (`/admin/layout.tsx`): sidebar + top bar. Wrap in `<RequireAdmin>`. Sidebar items (each hidden without its permission):

| Label key | href | Permission |
|---|---|---|
| `nav.dashboard` | `/admin` | `view-dashboard` |
| `nav.bookings` | `/admin/bookings` | `view-bookings` |
| `nav.reports` | `/admin/reports` | `view-reports` |
| `nav.clients` | `/admin/clients` | `view-clients` |
| `nav.consultants` | `/admin/consultants` | `view-consultants` |
| `nav.my_availability` | `/admin/my-availability` | `view-availability` AND `type === 'consultant'` |
| `nav.packages` | `/admin/packages` | `view-packages` |
| `nav.payments` | `/admin/payments` | `view-payments` |
| `nav.users` | `/admin/users` | `view-users` |
| `nav.roles` | `/admin/roles` | `view-roles` |

Top bar: language switcher, user menu (Profile `/admin/profile`, Logout).

---

## 10. Public pages

### 10.1 `/packages` — pricing (wizard step 1)

- Data: `GET /public/packages` (PUB-01) — not paginated, active only, ordered. Query key `['public','packages']`.
- UI: one pricing card per package: `name`, `description`, `price_formatted` + `/ {billing_period_days} days` label, `features_localized` bullet list, `is_featured` → highlighted card + "most distinguished" badge, `consultations_limit`/`documents_limit` → "N consultations monthly" (`is_unlimited` → "Unlimited").
- CTA per card: "اختر الباقة" → `/book/{slug}`.
- Empty/error states per the standard skeleton.

### 10.2 `/consultants` and `/consultants/[id]`

- List: `GET /public/consultants?search=&specialization=` (PUB-03, paginated, `per_page` default 12). Cards: `avatar_thumb_url`, `name`, `title`, `specialization`. Search box + specialization text filter. **No email/phone is returned — do not expect it.**
- Profile: `GET /public/consultants/{id}` (PUB-04): photo, name, title, specialization, `bio`, `working_days` chips (map 0=Sunday…6=Saturday via `days_of_week` from PUB-08 meta).

### 10.3 App boot: `GET /public/meta` (PUB-08)

Call once on boot (query key `['public','meta']`, `staleTime: Infinity`) and keep it in a small context/store. It returns: `booking_statuses`, `report_statuses`, `payment_statuses`, `days_of_week`, the `booking` config (`slot_minutes`, `duration_minutes`, `max_advance_days`, `min_notice_minutes`, `client_cancel_hours`) and `payment_gateway` (`driver`, `publishable_key`). Use it for: the Moyasar publishable key (§11.5), enum labels, and the cancel-window hint ("you can cancel up to {client_cancel_hours}h before").

---

## 11. The booking wizard (core flow)

Route: `/book/[packageSlug]`. Seven steps; step 1 (package) happened on `/packages`. The wizard keeps its own zustand store (`stores/wizard.ts`).

### 11.1 Wizard store

```ts
type WizardState = {
  packageSlug: string;
  pkg: Package | null;              // full object from PUB-01/02
  consultant: PublicConsultant | null;
  date: string | null;              // YYYY-MM-DD
  time: string | null;              // HH:MM
  location: Location | null;        // or a freshly created one
  paymentMethodId: number | null;   // saved card
  cardToken: string | null;         // new card token (from §11.5)
  saveCard: boolean;
  clientNotes: string;
  quote: Quote | null;              // CLI-BKG-01 result
  // setters + reset()
};
```

Render a **summary sidebar** from the store on every step: package name + `price_formatted`, consultant name/photo, date + time, location name, payment method label, and the quote total (`quote.amount_formatted` or "مشمول بالاشتراك" when `requires_payment === false`).

### 11.2 Steps → endpoints

| # | Step | Endpoint(s) | Notes |
|---|---|---|---|
| 1 | Package | `GET /public/packages` (already chosen on `/packages`) | load the full object by slug (`GET /public/packages/{slug}`, PUB-02) |
| 2 | Account | register/login (§6.1) | if a client token already exists, skip ahead; after this step every call carries the client token |
| 3 | Consultant | `GET /public/consultants?search=&specialization=` | photo, name, title, specialization |
| 4 | Date & time | `GET /public/consultants/{id}/available-dates?month=YYYY-MM` then `GET /public/consultants/{id}/slots?date=` | §11.3 |
| 5 | Location | `GET /client/locations` + `POST /client/locations` | pre-select the `is_default` one; inline "add new location" form |
| 6 | Payment | `GET /client/payment-methods` + quote | §11.4/§11.5; **skipped entirely when `quote.requires_payment === false`** |
| 7 | Confirm | `POST /client/bookings` | §11.6 |

### 11.3 Step 4 — date & time

- **Date picker:** fetch `available-dates?month=YYYY-MM` for the visible month (re-fetch on month change; omit `month` for the current month). Enable exactly the returned `dates` in `<DatePicker>`; disable everything else.
- **Time buttons:** after a date is picked, fetch `slots?date=…` → render one button per slot (`time`). Booked/past/time-off slots are **not returned** — an empty array means "لا توجد مواعيد متاحة في هذا اليوم".
- Changing consultant or date resets `time`.

### 11.4 The quote (drives the sidebar and step 6)

```
POST /client/bookings/quote   (CLI-BKG-01)
{ "package_id", "consultant_id"?, "date"?, "time"? }
→ data { package, requires_payment, amount, amount_formatted, currency,
         subscription, slot_available }
```

- Refresh the quote whenever `package`/`date`/`time` changes (debounced).
- `requires_payment = false` → the subscription covers the booking: show "مشمول بالاشتراك" in the sidebar, **skip step 6**, and submit without card fields.
- `slot_available` is only present when consultant+date+time are sent; when it becomes `false`, mark the chosen slot as gone and ask the user to re-pick.
- `422 SUBSCRIPTION_EXHAUSTED` on submit means the quota ran out mid-race → re-quote (it will flip to `requires_payment: true`) and route the user to step 6.

### 11.5 Step 6 — card tokenization (Moyasar.js)

**Never send card numbers to our API.** Tokenize in the browser and send only the token.

1. Read `payment_gateway` from the PUB-08 meta store.
2. **When `driver === 'moyasar'`:** load Moyasar.js with the `publishable_key`, mount its card form in `<CardForm onToken={(token) => …}>`, and on submit call Moyasar's tokenization to get a token.
3. **When `driver === 'fake'` (local dev):** render a dev-only selector with the three magic tokens — `tok_fake_success` (instant paid), `tok_fake_3ds` (3DS flow), `tok_fake_declined` (declined) — and use the chosen value as the token.
4. The step offers: saved cards (radio list from CLI-PM-01, `display` = "Visa •••• 4242", default pre-selected, `is_expired` cards disabled) **or** "new card" → `<CardForm>` + a "save card for future payments" checkbox (`save_card`).

### 11.6 Step 7 — submit and the 3-D Secure dance

```
POST /client/bookings   (CLI-BKG-02)
{ "package_id", "consultant_id", "date", "time", "client_location_id",
  "payment_method_id"   // saved card, OR:
  "card_token", "save_card": true,
  "client_notes"? }
→ 201 data { booking, payment }
```

Handle every outcome:

| Outcome | What to do |
|---|---|
| `payment === null` (covered by subscription) | success screen |
| `payment.status === 'paid'` | success screen: `booking.reference`, `date`/`time`, consultant, and the Meet link once `booking.meeting.status === 'created'` (poll `GET /client/bookings/{id}` a few times — the meeting is created async) |
| `payment.status === 'initiated'` + `transaction_url` | **3-D Secure:** first `localStorage.setItem('pending_payment', JSON.stringify({ payment_id: payment.id, booking_id: booking.id }))`, then `window.location.href = payment.transaction_url`. (Fake driver dev shortcut: skip the redirect and call verify directly — the fake gateway's fetch always returns paid.) |
| `402 PAYMENT_FAILED` | stay on step 6, show `message`, let the user pick another card |
| `409 SLOT_NOT_AVAILABLE` | re-fetch PUB-06 for the same date, mark the time as gone, ask for a new pick. Do not retry the same payload |
| `422 PAYMENT_METHOD_REQUIRED` | highlight step 6 |
| `422 SUBSCRIPTION_EXHAUSTED` | re-quote → step 6 (§11.4) |
| `503 PAYMENT_PENDING_CONFIRMATION` | the gateway could not be reached; the charge may have gone through. Show a "your payment is being confirmed" screen and poll `GET /client/bookings/{id}` after ~30 s. The charge is idempotent (Moyasar `given_id`) — an accidental retry can never charge twice |

### 11.7 `/bookings/payment-callback` — the 3DS return page

Moyasar redirects here with its own query params (`id`, `status`, `message`). **Do not trust them for the final state** — always verify against our API:

1. Read `pending_payment` from localStorage → `{ payment_id, booking_id }`. If missing, show a generic "check your bookings" link.
2. Call `POST /client/payments/{payment_id}/verify` (CLI-PAY-02). It is idempotent — call it freely on page load.
3. Render from the response: `payment.status === 'paid'` → confirmation (booking `pending`, Meet link when ready); `failed` → "payment failed" + retry link back to the wizard; still `initiated` → poll again after a few seconds (max ~5 tries, then "being confirmed" screen).
4. `503 PAYMENT_PENDING_CONFIRMATION` → the "being confirmed" screen + poll after ~30 s.
5. Clear `pending_payment` from localStorage when done.

---

## 12. Client dashboard pages

All wrapped in `<RequireClient>` + ClientLayout. All data via TanStack Query.

### 12.1 `/dashboard` — home (CLI-DSH-01)

`GET /client/dashboard` →

```json
{ "next_booking": { "…Booking with meeting.url" } | null,
  "bookings": { "upcoming", "completed", "cancelled" },
  "reports": { "total", "unread" },
  "active_subscriptions": [ "…Subscription" ] }
```

UI: a "next booking" hero card (date/time, consultant, **Join meeting** button linking to `meeting.url` when `meeting.status === 'created'`), three stat cards, and active subscription cards (`package.name`, `consultations_remaining` — `null` = "غير محدود", `ends_at`). `reports.unread` also badges the "My reports" nav item.

### 12.2 `/bookings` — my bookings (CLI-BKG-03)

- `GET /client/bookings?status=&sort=` (default `-starts_at`). Status filter tabs: all / `pending` / `completed` / `cancelled` / `pending_payment`; an "upcoming only" toggle (`upcoming=1`).
- Row/card: `reference`, `date` + `time`, consultant name, package name, `<StatusBadge kind="booking">` with `status_label`, `amount_formatted`.
- Click → `/bookings/{id}`.

### 12.3 `/bookings/[id]` — booking details (CLI-BKG-04)

Sections: status + reference header; consultant card; package; date/time (`date`, `time`, `duration_minutes`); location (`location.name/city/address` — from the snapshot, always present); meeting (`meeting.url` + CopyButton when `meeting.status === 'created'`; "جاري إنشاء الرابط" when `pending`; "فشل إنشاء الرابط — سيصلك بالبريد" when `failed`); payment summary (`payment_status` badge, `amount_formatted`, card `brand`/`last_four`); report section (when `report` exists: title + download button → §14.2); `client_notes`.

Actions (use `booking.can` — client side only `cancel` is relevant):

- **Cancel** (`POST /client/bookings/{id}/cancel`, `{ reason? }`): ConfirmDialog with optional reason. Rules: only `pending` (or `pending_payment`), and only ≥ `client_cancel_hours` (24) before start — show the window hint from PUB-08 meta; `422 BOOKING_CANCEL_WINDOW_PASSED` → toast. After a paid booking is cancelled, `refund_status = requested` → show `<Alert>` "سيتم استرداد المبلغ".

### 12.4 `/reports` and `/reports/[id]` (CLI-RPT-01/02/03)

- List: `GET /client/reports?search=&date_from=&date_to=`. Row: `title`, consultant name, booking `date`, `file.size_human`, an unread dot when `first_downloaded_at === null`, download button.
- Details `/reports/[id]` (**URL contract** — the email button lands here): title, summary, booking reference/date, consultant, file card + download button.
- Download: `GET /client/reports/{id}/download` via the blob helper (§14.2). The first download marks it read → invalidate `['client','reports']` and `['client','dashboard']`.

### 12.5 `/my-packages` — subscriptions (CLI-SUB-01/02)

- `GET /client/subscriptions?status=` list. Card per subscription: `package.name`, `status` badge, `starts_at`→`ends_at`, usage bar `consultations_used / consultations_limit` (`is_unlimited` → "غير محدود"), `price_paid_formatted`.
- `GET /client/subscriptions/active` powers a compact "current packages" strip (also used on `/dashboard`).

### 12.6 `/locations` (CLI-LOC-01..06)

- List (not paginated, default first). Card per location: `name`, `city`, `address`, default star.
- Create/edit modal: `name*` (max 150), `city`, `address*` (max 255), `latitude`/`longitude` (optional numeric), `is_default` switch. The **first** location becomes default automatically; setting a default unsets the others.
- Delete: ConfirmDialog; if it was the default, the newest remaining becomes default (backend does it — just invalidate).
- "Set as default": `PATCH /client/locations/{id}/default`.

### 12.7 `/payment-methods` (CLI-PM-01..04)

- List (default first). Card: `display` ("Visa •••• 4242"), `holder_name`, `exp_month`/`exp_year` (`dir="ltr"`), `is_expired` badge, default star.
- Add: `<CardForm>` (§11.5) → `POST /client/payment-methods { token, is_default? }`. A duplicate token returns the existing one.
- Delete: ConfirmDialog → `DELETE /client/payment-methods/{id}`. Default: `PATCH …/default`.
- The raw gateway token is never returned — never ask for it.

### 12.8 `/profile` (CLI-PRF-01..05)

- Form: `name*`, `email*`, `phone*` (`05XXXXXXXX`), `company_name*`.
- Avatar: `POST /client/profile/avatar` (FormData, §14.1) + `DELETE` to remove. Bust the browser cache with `updated_at` if the image looks stale.
- Password card: `current_password`, `password`, `password_confirmation` → `PUT /client/profile/password`. Wrong current password → 422 under `current_password`.

---

## 13. Admin dashboard pages

All under `/admin`, wrapped in `<RequireAdmin>` + AdminLayout, each page gated by its §7.1 permission. Admin auth pages (`/admin/login`, `/admin/forgot-password`, `/admin/reset-password`) are guest-only and use the PublicLayout look.

### 13.1 `/admin` — dashboard home (DSH-01)

`GET /admin/dashboard/stats` →

```json
{ "bookings": { "total", "pending", "completed", "cancelled", "today" },
  "reports": { "total", "pending" },
  "clients": { "total", "new_this_month" },
  "consultants": { "total", "active" },                 // admins only
  "revenue": { "this_month", "this_month_formatted", "total", "total_formatted" },  // admins only
  "upcoming_bookings": [ "…Booking ×5" ] }
```

- Stat cards for bookings/reports/clients; `consultants` and `revenue` cards **only when the keys exist** (a consultant's response omits them).
- The `reports.pending` card links to `/admin/reports` (those are completed bookings awaiting a report).
- "Upcoming bookings" mini-table (reference, client, consultant, date/time, status badge) → row click opens `/admin/bookings/{id}`.

### 13.2 `/admin/roles` — roles & permissions (ACL-01..07)

- List: `GET /admin/roles?search=` — columns: `name`, `permissions_count`, `users_count`, `is_protected` badge, created date.
- Create/edit modal (`POST /admin/roles`, `PUT /admin/roles/{id}`):
  - `name`: lowercase kebab (`/^[a-z0-9-]+$/`) — disabled for the protected roles.
  - Permissions picker: `GET /admin/permissions` (ACL-01) returns groups `[{ group, label, permissions: [{ id, name, group, label }] }]` — render one section per group with translated `label`s and a "select all in group" checkbox. At least one permission required.
  - The `admin` role: fully protected (`ROLE_PROTECTED` — hide edit/delete). The `consultant` role: name locked, permissions editable.
- Users drawer: `GET /admin/roles/{id}/users?search=` (paginated) — opens in a `<Drawer>`.
- Delete: ConfirmDialog → `DELETE /admin/roles/{id}`; `409 ROLE_HAS_USERS` → toast "الدور مرتبط بمستخدمين".

### 13.3 `/admin/users` (USR-01..08)

- List: `GET /admin/users?search=&type=admin|consultant&role=&is_active=&sort=`. Columns: avatar+name, email, phone, `type` badge, roles chips, `is_active` switch, `last_login_at`.
- Create modal (`POST /admin/users`, **FormData** when an avatar file is chosen, JSON otherwise): `name*`, `email*`, `phone`, `password` + `password_confirmation` (**optional** — without it the user gets a set-password email; show that hint), `type` (`admin` default | `consultant`), `roles*` multi-select of role **names**, `is_active`, `avatar` (jpg/png/webp ≤ 2 MB), consultant fields (`title`, `specialization`, `bio`) shown when `type=consultant`.
- Edit modal (`PUT /admin/users/{id}`): same fields, all optional; **no roles here** — roles have their own modal: `PUT /admin/users/{id}/roles { roles: [...] }` (`assign-roles` permission).
- Status switch: `PATCH /admin/users/{id}/status { is_active }` — ConfirmDialog when deactivating ("revokes the user's sessions").
- Delete: `DELETE /admin/users/{id}`.
- Errors to handle: `CANNOT_DELETE_SELF` (also on self-deactivate), `LAST_ADMIN`, `CONSULTANT_HAS_FUTURE_BOOKINGS` (409) → toast with `message`.

### 13.4 `/admin/consultants` (CON-01..07)

- List: `GET /admin/consultants?search=&is_active=&specialization=`. Card/row: photo, name, `title`, `specialization`, `working_days` chips, `stats` (`pending_bookings`, `completed_bookings`, `pending_reports`, `reports`, `clients`), active switch.
- Create (`POST /admin/consultants`, **always FormData** because of `photo`): `name*`, `email*`, `phone`, `title`, `specialization`, `bio`, `password` (optional → set-password email), `is_active`, `photo` (≤ 2 MB), and **optional** `availability` — build it with the `<AvailabilityEditor>` and serialize into the FormData as bracket-notation fields (§14.3). The `consultant` role is assigned automatically — there is no role picker here.
- Edit (`PUT /admin/consultants/{id}`, JSON): `name`, `email`, `phone`, `title`, `specialization`, `bio`, `is_active`. Photo separately: `POST /admin/consultants/{id}/photo` (FormData). Status: `PATCH …/status`. Delete: `DELETE` → `409 CONSULTANT_HAS_FUTURE_BOOKINGS` means "cancel his future bookings first".

### 13.5 `/admin/consultants/[id]` — details with tabs

Header: photo, name, title/specialization, active badge, stats row (`GET /admin/consultants/{id}/stats`, CON-18: `pending_bookings`, `completed_bookings`, `cancelled_bookings`, `pending_reports`, `reports`, `clients`, `upcoming_bookings` ×5). Tabs (each gated per §7.1):

1. **Profile** — `GET /admin/consultants/{id}` (CON-03) + the edit form (CON-04) + photo (CON-07).
2. **Availability** — `GET …/availability` (CON-08) → `<AvailabilityEditor>`; save = `PUT …/availability` (CON-09) which **replaces the whole week**. After save, if `warnings.conflicting_bookings_count > 0`, show `<Alert>`: "N future bookings are now outside the availability (they are NOT cancelled)".
3. **Time off** — `GET …/time-offs?from=&to=` (CON-10, paginated) + add form (CON-11: `date*` today-or-later; full-day switch, or `start_time`+`end_time` pair; `reason`) + delete (CON-12). Same conflicting-bookings warning on create.
4. **Clients** — `GET …/clients?search=` (CON-14): clients who booked him, with `bookings_count` and `last_booking_at`.
5. **Bookings** — `GET …/bookings?status=&report_status=&date_from=&date_to=&search=` (CON-15); the "pending bookings" tab is `?status=pending`.
6. **Pending reports** — `GET …/pending-reports` (CON-16): completed bookings awaiting a report, each with an "Upload report" button (§13.9 modal).
7. **Reports** — `GET …/reports?search=&date_from=&date_to=` (CON-17) with download buttons.
8. **Slot preview** — `GET …/slots?date=` (CON-13): a date input + the resulting slot buttons, read-only (helps the admin see what clients see).

#### The `<AvailabilityEditor>` component (used here, in CON-02 create, and in My availability)

- Seven rows, **Sunday first** (`day_of_week` 0→6; JS `Date.getDay()` matches). Day names from PUB-08 `days_of_week`.
- Each row: working switch + a list of ranges (`start_time`, `end_time` selects with `:00`/`:30` options) + add/remove range buttons.
- Client-side checks before submit (mirror the backend): `end > start`, minutes are `00`/`30`, range ≥ 30 min, no overlap within a day (touching is OK: 10:00–12:00 + 12:00–14:00).
- Submit body: `{ "days": [ { "day_of_week": 0, "ranges": [ { "start_time": "09:00", "end_time": "17:00" } ] } ] }` — send only working days; **omitted days are cleared** by the backend.
- `422 AVAILABILITY_OVERLAP` → the response `errors` are keyed like `days.0.ranges.1` — highlight that range row.

### 13.6 `/admin/my-availability` — consultant self-service (MY-01..06)

Same editors as §13.5 tabs 2, 3 and 8, but against `GET|PUT /admin/my/availability`, `GET|POST /admin/my/time-offs`, `DELETE /admin/my/time-offs/{id}`, `GET /admin/my/slots?date=`. Only for `type === 'consultant'` (the backend returns 403 for admins — hide the menu item).

### 13.7 `/admin/bookings` (BKG-01) + `/admin/bookings/calendar` (BKG-06)

- Filter bar: status select (`pending_payment | pending | completed | cancelled`), `report_status`, `payment_status`, consultant select (admins only — options from CON-01), client id, package id, `date_from`/`date_to`, search (reference, client, company, consultant), sort (`starts_at`, `created_at`, `amount`; default `-starts_at`).
- Table columns: `reference`, client (`company_name`), consultant, `date`+`time`, `amount_formatted`, status badge, payment badge, report badge, chevron to details.
- Calendar view (`/admin/bookings/calendar`): month grid; fetch `GET /admin/bookings/calendar?from=&to=` for the visible month (max 62-day range — request exactly the month's days), optional `consultant_id` filter. Items show `reference` + client `company_name`, colored by status; click → details.

### 13.8 `/admin/bookings/[id]` — details + actions (BKG-02..07)

`GET /admin/bookings/{id}` returns the full booking: `client`, `consultant`, `package`, `location` (snapshot), `meeting`, `payments[]`, `report`, `can` (`complete`, `cancel`, `upload_report` — calculated for the current user; **use it to show/hide the action buttons**, combined with the §7.2 permissions).

Layout: header (`reference`, status badge, `date`/`time`, `duration_minutes`) → two-column: left = client card, location card, `client_notes`, payment card (`payment_status`, `amount_formatted`, `payments[]` history table with `status`/`card_brand •••• last_four`/`paid_at`); right = consultant card, meeting card, report card, timeline (`created_at`, `paid_at`, `completed_at`, `cancelled_at` + `cancellation_reason`).

Actions bar:

| Action | Endpoint | When / notes |
|---|---|---|
| Complete | `POST /admin/bookings/{id}/complete { notes? }` (BKG-03) | `can.complete` + `complete-bookings`. Only after `starts_at` (`BOOKING_NOT_STARTED`) and while `pending`. Optional notes modal. Sets `report_status = pending` → the booking now expects a report |
| Cancel | `POST /admin/bookings/{id}/cancel { reason* }` (BKG-04) | `can.cancel` + `cancel-bookings`. Reason **required** (max 500). A paid booking becomes `refund_status = requested` → show the refund alert afterwards |
| Regenerate Meet link | `POST /admin/bookings/{id}/meeting { notify_client? }` (BKG-05) | `manage-meetings`; only `pending`. Show a spinner (it runs synchronously); `502 MEETING_CREATION_FAILED` → "Google is down, retry" button |
| Mark refunded | `POST /admin/bookings/{id}/mark-refunded { note? }` (BKG-07) | `refund-payments`; only while `refund_status = requested`, after the refund was done in the Moyasar dashboard. ConfirmDialog must warn: **"this also cancels the subscription the booking paid for"** |
| Upload report | see §13.9 | `can.upload_report` + `upload-reports`; only `completed` |

Meeting card states: `created` → url + CopyButton; `pending` → "جاري إنشاء الرابط" (poll the booking a few times); `failed` → red alert + the regenerate button; `none` → nothing.

### 13.9 Reports (RPT-01..06)

- `/admin/reports` list: `GET /admin/reports?consultant_id=&client_id=&search=&date_from=&date_to=&sort=`. Columns: `title`, booking `reference`+date, consultant, client company, `file.size_human`, `first_downloaded_at` (client read it?), actions: download (§14.2), resend email, delete.
- **Upload modal** (also opened from the booking details and the consultant's pending-reports tab): `POST /admin/bookings/{booking}/report` — **FormData**: `title*` (max 191), `summary` (max 5000), `file*` (pdf/doc/docx ≤ 20 MB), `notify_client` switch (default on → the client gets the email with a 7-day signed link). 201 = created, 200 = replaced (uploading again **replaces** the file — say so in the modal when `report` already exists). `422 REPORT_NOT_ALLOWED` → the booking is not completed.
- Resend email: `POST /admin/reports/{id}/notify-client` → success toast.
- Delete: ConfirmDialog → `DELETE /admin/reports/{id}`; note that the booking returns to "awaiting report".

### 13.10 `/admin/clients` (ADM-CL-01..08)

- List: `GET /admin/clients?search=&is_active=&consultant_id=&has_active_subscription=`. Columns: avatar+name, `company_name`, email, phone, `bookings_count`, `reports_count`, `active_subscription` chip (`package.name`), active switch.
- Details `/admin/clients/[id]`: header (avatar, name, company, contact info, active badge) + tabs:
  - **Overview** — `GET /admin/clients/{id}` (with `locations`, counts, `active_subscription`) + edit modal (`PUT`: `name*`, `email*`, `phone*` `05XXXXXXXX`, `company_name*`).
  - **Bookings** — `GET /admin/clients/{id}/bookings` (ADM-CL-06).
  - **Reports** — `GET /admin/clients/{id}/reports` (ADM-CL-07).
  - **Subscriptions** — `GET /admin/clients/{id}/subscriptions` (ADM-CL-08): package, status, period, usage.
- Status switch: `PATCH /admin/clients/{id}/status` (deactivating revokes the client's tokens).
- Delete: `DELETE /admin/clients/{id}` → `409 CLIENT_HAS_FUTURE_BOOKINGS` → toast.

### 13.11 `/admin/packages` (PKG-01..06)

- List (includes inactive): `GET /admin/packages?search=&is_active=`. Card per package: `name` (+`name_en`), `price_formatted`, `consultations_limit`/`documents_limit` ("unlimited" when null), `is_featured` badge, `is_active` switch, `sort_order`.
- Create/edit modal (`POST` / `PUT /admin/packages/{id}`):
  - `slug*` — lowercase `alpha_dash` (`/^[a-z0-9-_]+$/`), unique; disabled on edit of seeded slugs is fine but not required.
  - `name_ar*` / `name_en*`, `description_ar` / `description_en`.
  - **Features editor**: a list of `{ ar, en }` pairs with add/remove (both required per row).
  - **Price: the form inputs SAR** (e.g. `1900.00`) and converts to halalas before sending (`Math.round(sar * 100)`); when editing, initialize from `price / 100`. Display elsewhere always uses `price_formatted`.
  - `billing_period_days` (default 30), `consultations_limit` / `documents_limit` with an "unlimited" checkbox that sends **null**.
  - `is_featured`, `is_active`, `sort_order`.
- Status: `PATCH …/status`. Delete: `DELETE` → `409 PACKAGE_HAS_SUBSCRIPTIONS` → toast. (Price changes do not affect existing subscriptions — no UI needed for that.)

### 13.12 `/admin/payments` (PAY-01/02)

- List: `GET /admin/payments?status=&client_id=&date_from=&date_to=&search=` (search: booking reference, gateway payment id, client company). Columns: id, booking `reference`, client company, `amount_formatted`, `card_brand`+`last_four`, status badge (`initiated|paid|failed|refunded`), `paid_at`, `created_at`.
- Details drawer/page: `GET /admin/payments/{id}` — payment fields + booking summary card (link to the booking). `gateway_response` is never returned — do not ask for it.

### 13.13 `/admin/profile` (ADM-PRF-01..05)

Same as the client profile (§12.8) but against `/admin/profile*`. When `user.type === 'consultant'`, also edit `title`, `specialization`, `bio`. Password: `PUT /admin/profile/password` (keeps the current session, revokes the others).

---

## 14. File uploads and downloads

### 14.1 Uploads (`src/lib/files.ts`)

Avatars/photos: jpg/jpeg/png/webp ≤ **2 MB**. Report files: pdf/doc/docx ≤ **20 MB**. Check size and mime **client-side** in `<FileDrop>` before uploading, then:

```ts
export async function uploadFile(url: string, field: string, file: File, extra: Record<string, string> = {}) {
  const fd = new FormData();
  fd.append(field, file);
  Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
  const { data } = await api.post(url, fd);   // do NOT set Content-Type — the browser adds the boundary
  return data.data;
}
```

Upload endpoints are always `POST` (avatars have their own endpoints for updates). The response returns the fresh URL (`avatar_url`, `photo_url`) — invalidate the relevant query afterwards.

### 14.2 Downloads (report files)

Downloads need the Bearer token, so fetch a blob and save it under the file name from `Content-Disposition`:

```ts
export async function downloadFile(url: string, fallback = 'report.pdf') {
  const res = await api.get(url, { responseType: 'blob' });
  const cd = (res.headers['content-disposition'] as string) ?? '';
  const filename = cd.match(/filename="?([^"]+)"?/)?.[1] ?? fallback;
  const a = Object.assign(document.createElement('a'), {
    href: URL.createObjectURL(res.data), download: filename,
  });
  a.click();
  URL.revokeObjectURL(a.href);
}
```

Used for `GET /admin/reports/{id}/download` (RPT-04) and `GET /client/reports/{id}/download` (CLI-RPT-03). The 7-day **signed URL** in the report email is for the email client — the app never uses it.

### 14.3 Nested objects in FormData (`src/lib/form-data.ts`)

PHP parses bracket notation into nested arrays. Needed for the consultant create form's `availability`:

```ts
export function appendFormData(fd: FormData, value: unknown, key: string) {
  if (value === null || value === undefined) return;
  if (value instanceof File) { fd.append(key, value); return; }
  if (Array.isArray(value)) {
    value.forEach((v, i) => appendFormData(fd, v, `${key}[${i}]`));
    return;
  }
  if (typeof value === 'object') {
    Object.entries(value as Record<string, unknown>).forEach(([k, v]) => appendFormData(fd, v, `${key}[${k}]`));
    return;
  }
  fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
}
```

Usage: `appendFormData(fd, { days: [...] }, 'availability')` → `availability[days][0][day_of_week]=0`, `availability[days][0][ranges][0][start_time]=09:00`, …

---

## 15. Status badges

Labels come translated from the API (`status_label` or PUB-08 enums) — use them. Colors:

### 15.1 `booking.status`

| Value | Label (ar) | Color |
|---|---|---|
| `pending_payment` | في انتظار الدفع | amber |
| `pending` | مؤكد / قادم | blue |
| `completed` | مكتمل | green |
| `cancelled` | ملغي | gray/red |

### 15.2 `booking.report_status`

| Value | Label (ar) | Color |
|---|---|---|
| `none` | — | gray |
| `pending` | بانتظار التقرير | amber |
| `uploaded` | تم رفع التقرير | green |

### 15.3 Payments

`payment.status`: `initiated` بدأ الدفع (amber) · `paid` مدفوع (green) · `failed` فشل الدفع (red) · `refunded` مسترد (purple).

`booking.payment_status` (roll-up): `unpaid` غير مدفوع (amber) · `paid` مدفوع (green) · `not_required` مشمول بالاشتراك (gray) · `failed` فشل الدفع (red) · `refunded` مسترد (purple).

### 15.4 `booking.refund_status`

`none` — (gray) · `requested` استرداد مطلوب (amber) · `refunded` تم الاسترداد (green).

### 15.5 `booking.meeting.status`

`none` — (gray) · `pending` جاري إنشاء الرابط (amber) · `created` الرابط جاهز (green) · `failed` فشل إنشاء الرابط (red — show the BKG-05 retry to staff).

### 15.6 `subscription.status`

`active` نشط (green) · `expired` منتهي (gray) · `cancelled` ملغي (red).

---

## 16. Error handling master table

Global rules: branch on `error_code`; `VALIDATION_ERROR` → inline field errors; everything else → toast `message` plus the specific handling below.

| HTTP | `error_code` | UI handling |
|---|---|---|
| 401 | `UNAUTHENTICATED` | interceptor: clear token, redirect to the area's login |
| 403 | `FORBIDDEN` | "لا تملك صلاحية" toast; hide the action next time |
| 403 | `ACCOUNT_DISABLED` | block login: "الحساب معطّل، تواصل مع الإدارة" |
| 404 | `NOT_FOUND` | 404 page (or "not found" empty state in modals) |
| 405 | `METHOD_NOT_ALLOWED` | dev error |
| 422 | `VALIDATION_ERROR` | inline field errors from `errors` |
| 422 | `INVALID_CREDENTIALS` | under the email field |
| 422 | `ROLE_PROTECTED` / `ROLE_HAS_USERS` / `CANNOT_DELETE_SELF` / `LAST_ADMIN` | toast |
| 422 | `CONSULTANT_INACTIVE` | wizard: pick another consultant |
| 409 | `CONSULTANT_HAS_FUTURE_BOOKINGS` / `CLIENT_HAS_FUTURE_BOOKINGS` / `PACKAGE_HAS_SUBSCRIPTIONS` | toast explaining the blocker |
| 422 | `AVAILABILITY_OVERLAP` | highlight the range (`errors` keys like `days.0.ranges.1`) |
| 409 | `SLOT_NOT_AVAILABLE` | wizard: reload PUB-06, mark the time gone, ask to re-pick (§11.6) |
| 422 | `PACKAGE_INACTIVE` | toast |
| 422 | `SUBSCRIPTION_EXHAUSTED` | wizard: re-quote → payment step (§11.4) |
| 422 | `LOCATION_NOT_OWNED` / `PAYMENT_METHOD_NOT_OWNED` | dev error (refetch the lists) |
| 422 | `PAYMENT_METHOD_REQUIRED` | wizard: highlight the payment step |
| 402 | `PAYMENT_FAILED` | show `message`, offer another card |
| 422 | `PAYMENT_ALREADY_PROCESSED` | safe to ignore; refresh the booking |
| 503 | `PAYMENT_PENDING_CONFIRMATION` | "your payment is being confirmed" + poll the booking after ~30 s (§11.6/§11.7) |
| 422 | `BOOKING_INVALID_STATUS` | refresh the booking |
| 422 | `BOOKING_NOT_STARTED` | toast |
| 422 | `BOOKING_CANCEL_WINDOW_PASSED` | "انتهت مهلة الإلغاء" |
| 422 | `REPORT_NOT_ALLOWED` | toast (booking not completed) |
| 502 | `MEETING_CREATION_FAILED` | retry button (BKG-05) |
| 429 | `TOO_MANY_REQUESTS` | "حاول بعد قليل" |
| 500 | `SERVER_ERROR` | generic error toast/page |
| 0 | `NETWORK_ERROR` | "تحقق من اتصالك بالإنترنت" + retry |

---

## 17. Appendix A — TypeScript types for every API resource

Put these in `src/types/api.ts`. They match the API Resources exactly.

```ts
// ---------- shared ----------
export type ID = number;

export interface Paginated<T> {
  data: T[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
  links: { first: string; last: string; prev: string | null; next: string | null };
}

export interface Envelope<T> { success: true; message: string; data: T }

// ---------- users / consultants ----------
export type UserType = 'admin' | 'consultant';

export interface User {
  id: ID; type: UserType; name: string; email: string; phone: string | null;
  title: string | null; specialization: string | null; bio: string | null;
  avatar_url: string | null; avatar_thumb_url: string | null;
  is_active: boolean; last_login_at: string | null;
  roles: string[];
  permissions?: string[];          // only in /me and /profile
  created_at: string; updated_at: string;
}

export interface Consultant extends User {
  stats?: { pending_bookings: number; completed_bookings: number; pending_reports: number; reports: number; clients: number };
  working_days?: number[];         // 0 = Sunday … 6 = Saturday
}

export interface PublicConsultant {
  id: ID; name: string; title: string | null; specialization: string | null; bio: string | null;
  avatar_url: string | null; avatar_thumb_url: string | null; working_days: number[];
}

export interface AvailabilityDay {
  day_of_week: number;             // 0 = Sunday … 6 = Saturday
  day_name: string; day_name_ar: string; is_working: boolean;
  ranges: { id?: ID; start_time: string; end_time: string }[];   // "HH:MM"
}

export interface TimeOff {
  id: ID; date: string;            // YYYY-MM-DD
  start_time: string | null; end_time: string | null;
  is_full_day: boolean; reason: string | null; created_at: string;
}

export interface Slot { time: string; starts_at: string; ends_at: string }

// ---------- clients ----------
export interface Client {
  id: ID; name: string; email: string; phone: string; company_name: string;
  avatar_url: string | null; is_active: boolean; last_login_at: string | null;
  bookings_count?: number; reports_count?: number;
  active_subscription?: Subscription | null;
  created_at: string;
}

export interface ClientMe extends Client {
  active_subscriptions: Subscription[];
  default_location: Location | null;
  default_payment_method: PaymentMethod | null;
}

export interface Location {
  id: ID; name: string; city: string | null; address: string;
  latitude: string | null; longitude: string | null;
  is_default: boolean; created_at: string;
}

// ---------- packages / subscriptions ----------
export interface PackageFeature { ar: string; en: string }

export interface Package {
  id: ID; slug: string;
  name: string; name_ar: string; name_en: string;
  description: string | null; description_ar: string | null; description_en: string | null;
  features: PackageFeature[]; features_localized: string[];
  price: number; price_formatted: string; currency: string;   // halalas
  billing_period_days: number;
  consultations_limit: number | null; documents_limit: number | null;   // null = unlimited
  is_unlimited: boolean; is_featured: boolean; is_active: boolean; sort_order: number;
}

export type SubscriptionStatus = 'active' | 'expired' | 'cancelled';

export interface Subscription {
  id: ID;
  package: Pick<Package, 'id' | 'slug' | 'name' | 'name_ar' | 'name_en'>;
  status: SubscriptionStatus; status_label?: string;
  starts_at: string; ends_at: string;
  consultations_limit: number | null; consultations_used: number;
  consultations_remaining: number | null; is_unlimited: boolean;
  price_paid: number; price_paid_formatted: string;
}

// ---------- payments ----------
export interface PaymentMethod {
  id: ID; brand: 'visa' | 'mastercard' | 'mada' | 'amex' | string;
  last_four: string; exp_month: number; exp_year: number;
  holder_name: string | null; is_default: boolean; is_expired: boolean;
  display: string;                 // "Visa •••• 4242"
  created_at: string;
}

export type PaymentStatus = 'initiated' | 'paid' | 'failed' | 'refunded';

export interface Payment {
  id: ID; booking_id: ID; booking_reference?: string;
  amount: number; amount_formatted: string; currency: string;
  status: PaymentStatus; status_label?: string;
  gateway: string; card_brand: string | null; card_last_four: string | null;
  failure_reason: string | null;
  transaction_url?: string | null;  // only while initiated
  requires_action?: boolean;
  paid_at: string | null; created_at: string;
  client?: { id: ID; name: string; company_name: string };   // admin side
}

// ---------- bookings ----------
export type BookingStatus = 'pending_payment' | 'pending' | 'completed' | 'cancelled';
export type ReportStatus = 'none' | 'pending' | 'uploaded';
export type BookingPaymentStatus = 'unpaid' | 'paid' | 'not_required' | 'failed' | 'refunded';
export type RefundStatus = 'none' | 'requested' | 'refunded';
export type MeetingStatus = 'none' | 'pending' | 'created' | 'failed';

export interface Booking {
  id: ID; reference: string;                       // BK-2026-000015
  status: BookingStatus; status_label: string;
  report_status: ReportStatus; payment_status: BookingPaymentStatus; refund_status: RefundStatus;
  date: string; time: string;                      // display fields
  starts_at: string; ends_at: string; duration_minutes: number;
  amount: number; amount_formatted: string; currency: string;
  package: Pick<Package, 'id' | 'slug' | 'name' | 'name_ar' | 'name_en'>;
  consultant: { id: ID; name: string; title: string | null; specialization: string | null; avatar_thumb_url: string | null };
  client?: { id: ID; name: string; company_name: string; email: string; phone: string };
  location: { id: ID | null; name: string; city: string | null; address: string } | null;
  meeting: { provider: string | null; status: MeetingStatus; url: string | null };
  payment?: Payment | null;                        // latest
  payments?: Payment[];                            // admin details
  report?: { id: ID; title: string; file_name: string; uploaded_at: string } | null;
  client_notes: string | null;
  can: { complete: boolean; cancel: boolean; upload_report: boolean };
  expires_at: string | null; completed_at: string | null;
  cancelled_at: string | null; cancellation_reason: string | null;
  created_at: string;
}

export interface BookingCalendarItem {
  id: ID; reference: string; status: BookingStatus;
  starts_at: string; ends_at: string;
  consultant: { id: ID; name: string };
  client: { id: ID; company_name: string };
}

export interface Quote {
  package: Package;
  requires_payment: boolean;
  amount: number; amount_formatted: string; currency: string;
  subscription: Subscription | null;
  slot_available: boolean | null;   // only when consultant+date+time sent
}

// ---------- reports ----------
export interface Report {
  id: ID; title: string; summary: string | null;
  booking: { id: ID; reference: string; date: string; time: string };
  consultant: { id: ID; name: string };
  client: { id: ID; name: string; company_name: string };
  file: { name: string; size: number; size_human: string; mime_type: string };
  download_url: string;             // guard-specific — prefer the blob helper (§14.2)
  client_notified_at: string | null; first_downloaded_at: string | null;
  created_at: string; updated_at: string;
}

// ---------- roles / permissions ----------
export interface Role {
  id: ID; name: string; guard_name: string; is_protected: boolean;
  permissions_count: number; users_count: number;
  permissions?: Permission[];       // only on show
  created_at: string;
}

export interface Permission { id: ID; name: string; group: string; label: string }
export interface PermissionGroup { group: string; label: string; permissions: Permission[] }

// ---------- dashboards ----------
export interface AdminStats {
  bookings: { total: number; pending: number; completed: number; cancelled: number; today: number };
  reports: { total: number; pending: number };
  clients: { total: number; new_this_month: number };
  consultants?: { total: number; active: number };        // admins only
  revenue?: { this_month: number; this_month_formatted: string; total: number; total_formatted: string };
  upcoming_bookings: Booking[];
}

export interface ClientDashboard {
  next_booking: Booking | null;
  bookings: { upcoming: number; completed: number; cancelled: number };
  reports: { total: number; unread: number };
  active_subscriptions: Subscription[];
}

// ---------- PUB-08 meta ----------
export interface PublicMeta {
  booking_statuses: { value: string; label: string }[];
  report_statuses: { value: string; label: string }[];
  payment_statuses: { value: string; label: string }[];
  days_of_week: { value: number; name: string; name_ar: string }[];
  booking: { slot_minutes: number; duration_minutes: number; max_advance_days: number; min_notice_minutes: number; client_cancel_hours: number };
  payment_gateway: { driver: 'fake' | 'moyasar'; publishable_key: string | null };
}
```

---

## 18. Appendix B — Zod schemas for every form

One file per form in `src/schemas/`. These mirror the backend validation — the backend is still the authority; client validation is for UX. Messages come from the active locale's message file.

```ts
// shared rules
const phone = z.string().regex(/^05\d{8}$/, 'invalidPhone');           // 05XXXXXXXX
const password = z.string().min(8).regex(/[a-z]/, 'pwLower').regex(/[A-Z]/, 'pwUpper').regex(/[0-9]/, 'pwNumber');
const time = z.string().regex(/^([01]\d|2[0-3]):(00|30)$/, 'invalidTime'); // HH:MM, :00/:30 only
const dateYmd = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'invalidDate');

// auth
export const loginSchema = z.object({ email: z.string().email(), password: z.string().min(1) });
export const registerSchema = z.object({
  name: z.string().min(1).max(150),
  email: z.string().email(),
  phone,
  company_name: z.string().min(1).max(191),
  password,
  password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'pwMismatch' });
export const forgotSchema = z.object({ email: z.string().email() });
export const resetSchema = z.object({
  token: z.string().min(1), email: z.string().email(),
  password, password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'pwMismatch' });

// profiles
export const clientProfileSchema = z.object({
  name: z.string().min(1).max(150), email: z.string().email(),
  phone, company_name: z.string().min(1).max(191),
});
export const changePasswordSchema = z.object({
  current_password: z.string().min(1), password, password_confirmation: z.string(),
}).refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'pwMismatch' });

// locations
export const locationSchema = z.object({
  name: z.string().min(1).max(150),
  city: z.string().max(100).optional().or(z.literal('')),
  address: z.string().min(1).max(255),
  latitude: z.coerce.number().min(-90).max(90).optional().or(z.literal('')),
  longitude: z.coerce.number().min(-180).max(180).optional().or(z.literal('')),
  is_default: z.boolean().default(false),
});

// roles / users / consultants
export const roleSchema = z.object({
  name: z.string().regex(/^[a-z0-9-]+$/, 'invalidRoleName').max(100),
  permissions: z.array(z.string()).min(1),
});
export const userSchema = z.object({
  name: z.string().min(1).max(150),
  email: z.string().email(),
  phone: z.string().max(20).optional().or(z.literal('')),
  password: password.optional().or(z.literal('')),        // empty → set-password email
  password_confirmation: z.string().optional(),
  type: z.enum(['admin', 'consultant']).default('admin'),
  roles: z.array(z.string()).min(1),
  is_active: z.boolean().default(true),
  title: z.string().max(150).optional().or(z.literal('')),
  specialization: z.string().max(150).optional().or(z.literal('')),
  bio: z.string().max(2000).optional().or(z.literal('')),
});
export const consultantSchema = z.object({
  name: z.string().min(1).max(150),
  email: z.string().email(),
  phone: z.string().max(20).optional().or(z.literal('')),
  title: z.string().max(150).optional().or(z.literal('')),
  specialization: z.string().max(150).optional().or(z.literal('')),
  bio: z.string().max(2000).optional().or(z.literal('')),
  password: password.optional().or(z.literal('')),
  is_active: z.boolean().default(true),
});

// availability + time off
export const availabilitySchema = z.object({
  days: z.array(z.object({
    day_of_week: z.number().int().min(0).max(6),
    ranges: z.array(z.object({ start_time: time, end_time: time })),
  })).max(7),
}).superRefine((val, ctx) => {
  // per day: end > start, and no overlap (touching is allowed)
  val.days.forEach((day, di) => {
    const sorted = [...day.ranges].sort((a, b) => a.start_time.localeCompare(b.start_time));
    sorted.forEach((r, ri) => {
      if (r.end_time <= r.start_time) {
        ctx.addIssue({ code: 'custom', path: ['days', di, 'ranges', ri, 'end_time'], message: 'endAfterStart' });
      }
      const next = sorted[ri + 1];
      if (next && next.start_time < r.end_time) {
        ctx.addIssue({ code: 'custom', path: ['days', di, 'ranges', ri + 1, 'start_time'], message: 'overlap' });
      }
    });
  });
});
export const timeOffSchema = z.object({
  date: dateYmd,                                          // >= today (check with dayjs)
  full_day: z.boolean().default(true),
  start_time: time.optional(), end_time: time.optional(), // required together when full_day=false
  reason: z.string().max(255).optional().or(z.literal('')),
});

// packages (admin) — price is entered in SAR, converted to halalas on submit
export const packageSchema = z.object({
  slug: z.string().regex(/^[a-z0-9-_]+$/, 'invalidSlug'),
  name_ar: z.string().min(1).max(100), name_en: z.string().min(1).max(100),
  description_ar: z.string().max(500).optional().or(z.literal('')),
  description_en: z.string().max(500).optional().or(z.literal('')),
  features: z.array(z.object({ ar: z.string().min(1), en: z.string().min(1) })).default([]),
  price_sar: z.coerce.number().min(0),                    // → price = Math.round(price_sar * 100)
  billing_period_days: z.coerce.number().int().min(1).default(30),
  consultations_unlimited: z.boolean().default(false),
  consultations_limit: z.coerce.number().int().min(1).nullable().default(null),
  documents_unlimited: z.boolean().default(false),
  documents_limit: z.coerce.number().int().min(0).nullable().default(null),
  is_featured: z.boolean().default(false),
  is_active: z.boolean().default(true),
  sort_order: z.coerce.number().int().default(0),
});

// booking actions
export const cancelBookingSchema = z.object({ reason: z.string().max(500).optional().or(z.literal('')) });       // client
export const adminCancelSchema = z.object({ reason: z.string().min(1).max(500) });                              // admin: required
export const completeBookingSchema = z.object({ notes: z.string().max(1000).optional().or(z.literal('')) });
export const reportUploadSchema = z.object({
  title: z.string().min(1).max(191),
  summary: z.string().max(5000).optional().or(z.literal('')),
  notify_client: z.boolean().default(true),
  // file validated by <FileDrop>: pdf/doc/docx ≤ 20 MB
});
```

---

## 19. Appendix C — Complete API reference (from the Postman collection)

Every endpoint of the backend, generated from `postman/GCMC-API.postman_collection.json` (run `php scripts/postman-api-reference.php` to regenerate). Conventions:

- All URLs are relative to `NEXT_PUBLIC_API_URL` (e.g. `http://localhost:8000/api/v1`).
- `{{variable}}` in a path = a route parameter (an id, a slug, …).
- **Auth**: which Bearer token the request needs (§5.1 attaches it automatically by URL prefix).
- Query params marked *(optional)* are sent only when used.
- The example responses are **real responses** captured from a run against the seeded database. Long lists are truncated to the first item (`… (N more)`).

<!-- API-REFERENCE:START -->

### 00 Public

#### `GET /public/meta` — Meta (PUB-08)

**Auth:** — (public)

Enum values and labels for the frontend (booking/report/payment statuses, days of week), the booking config and the payment gateway driver + publishable key.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "booking_statuses": [
            {
                "value": "pending_payment",
                "label": "في انتظار الدفع"
            },
            "… (3 more)"
        ],
        "report_statuses": [
            {
                "value": "none",
                "label": "لا يوجد"
            },
            "… (2 more)"
        ],
        "payment_statuses": [
            {
                "value": "unpaid",
                "label": "غير مدفوع"
            },
            "… (4 more)"
        ],
        "days_of_week": [
            {
                "value": 0,
                "name": "الأحد"
            },
            "… (6 more)"
        ],
        "booking": {
            "slot_minutes": 30,
            "duration_minutes": 30,
            "max_advance_days": 60,
            "min_notice_minutes": 60,
            "client_cancel_hours": 24
        },
        "payment_gateway": {
            "driver": "fake",
            "publishable_key": ""
        }
    }
}
```

#### `GET /public/packages` — List packages (PUB-01)

**Auth:** — (public)

The active subscription packages, ordered by sort_order. Prices are halalas; `price_formatted` is the display value.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package",
            "description": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_ar": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_en": "For start-up establishments beginning their governance and compliance journey.",
            "features": [
                {
                    "ar": "استشارتان شهرياً",
                    "en": "Two consultations monthly"
                },
                "… (3 more)"
            ],
            "features_localized": [
                "استشارتان شهرياً",
                "… (3 more)"
            ],
            "price": 190000,
            "price_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "billing_period_days": 30,
            "consultations_limit": 2,
            "documents_limit": 2,
            "is_unlimited": false,
            "is_featured": false,
            "is_active": true,
            "sort_order": 1,
            "created_at": "2026-09-24T03:50:39+03:00",
            "updated_at": "2026-09-24T03:50:39+03:00"
        },
        "… (2 more)"
    ]
}
```

#### `GET /public/packages/{{package_slug}}` — Show package (PUB-02)

**Auth:** — (public)

One active package by slug.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 1,
        "slug": "iron",
        "name": "الباقة الحديدية",
        "name_ar": "الباقة الحديدية",
        "name_en": "Iron Package",
        "description": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
        "description_ar": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
        "description_en": "For start-up establishments beginning their governance and compliance journey.",
        "features": [
            {
                "ar": "استشارتان شهرياً",
                "en": "Two consultations monthly"
            },
            "… (3 more)"
        ],
        "features_localized": [
            "استشارتان شهرياً",
            "… (3 more)"
        ],
        "price": 190000,
        "price_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "billing_period_days": 30,
        "consultations_limit": 2,
        "documents_limit": 2,
        "is_unlimited": false,
        "is_featured": false,
        "is_active": true,
        "sort_order": 1,
        "created_at": "2026-09-24T03:50:39+03:00",
        "updated_at": "2026-09-24T03:50:39+03:00"
    }
}
```

#### `GET /public/consultants` — List consultants (PUB-03)

**Auth:** — (public)

Active consultants who have availability. 12 per page by default.

| Query param | Notes |
|---|---|
| `search` *(optional)* | Search name/title/specialization |
| `specialization` *(optional)* | Exact specialization filter |
| `page` *(optional)* | Page number e.g. `1` |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "working_days": [
                0,
                "… (4 more)"
            ]
        },
        "… (5 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 12,
        "total": 6,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/public/consultants?page=1",
        "last": "http://localhost:8000/api/v1/public/consultants?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /public/consultants/{{consultant_id}}` — Show consultant (PUB-04)

**Auth:** — (public)

A consultant profile with his availability.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 7,
        "name": "أ. نورة السبيعي",
        "title": "مستشار جودة",
        "specialization": "جودة العمليات",
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "working_days": [
            0,
            "… (4 more)"
        ]
    }
}
```

#### `GET /public/consultants/{{consultant_id}}/available-dates` — Available dates (PUB-05)

**Auth:** — (public)

The dates of a month on which the consultant still has free slots.

**Errors:** 422 `VALIDATION_ERROR`, 404 `NOT_FOUND`

| Query param | Notes |
|---|---|
| `month` | YYYY-MM (defaults to the current month) |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "month": "2026-10",
        "dates": [
            "2026-10-01",
            "… (20 more)"
        ]
    }
}
```

#### `GET /public/consultants/{{consultant_id}}/slots` — Slots (PUB-06)

**Auth:** — (public)

The free slots of one day. Booked slots are hidden.

**Errors:** 422 `VALIDATION_ERROR`, 404 `NOT_FOUND`

| Query param | Notes |
|---|---|
| `date` | YYYY-MM-DD, required |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "date": "2026-10-01",
        "timezone": "Asia/Riyadh",
        "slot_minutes": 30,
        "duration_minutes": 30,
        "slots": [
            {
                "time": "09:00",
                "starts_at": "2026-10-01T09:00:00+03:00",
                "ends_at": "2026-10-01T09:30:00+03:00"
            },
            "… (15 more)"
        ]
    }
}
```

#### `GET /public/reports/{{demo_report_id}}/signed-download` — Signed report download (PUB-07)

**Auth:** — (public)

Downloads a report through the signed URL from the report-ready email (valid 7 days, no login). Skipped in the CI run: the signature cannot be computed in Postman — copy `signed_url` from the email.

**Errors:** 403 `FORBIDDEN` (missing/tampered/expired signature), 404 `NOT_FOUND`

| Query param | Notes |
|---|---|
| `expires` | unix timestamp e.g. `PASTE-FROM-EMAIL` |
| `signature` | HMAC signature e.g. `PASTE-FROM-EMAIL` |

### 01 Admin › Auth

#### `POST /admin/auth/login` — Login as admin (ADM-AUTH-01)

**Auth:** — (public)

Dashboard A login. Returns a Sanctum token.

| Field | Rules |
|---|---|
| email | required email |
| password | required |
| device_name | optional |

**Errors:** 422 `INVALID_CREDENTIALS`, 403 `ACCOUNT_DISABLED`

Body:

```json
{
    "email": "{{admin_email}}",
    "password": "{{admin_password}}",
    "device_name": "postman"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الدخول بنجاح.",
    "data": {
        "token": "1|sLo14eb0tWGnyj554x065EOviUMhDME9GOx7zOEk627310db",
        "token_type": "Bearer",
        "user": {
            "id": 1,
            "type": "admin",
            "name": "Super Admin",
            "email": "admin@gcmc.sa",
            "phone": null,
            "title": null,
            "specialization": null,
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": "2026-09-24T03:50:44+03:00",
            "roles": [
                "admin"
            ],
            "permissions": [
                "view-dashboard",
                "… (33 more)"
            ],
            "created_at": "2026-09-24T03:50:39+03:00",
            "updated_at": "2026-09-24T03:50:44+03:00"
        }
    }
}
```

#### `POST /admin/auth/login` — Login as consultant

**Auth:** — (public)

A consultant logs in through the same endpoint; his `type` is `consultant` and he only sees his own data.

Body:

```json
{
    "email": "{{consultant_email}}",
    "password": "{{consultant_password}}",
    "device_name": "postman"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الدخول بنجاح.",
    "data": {
        "token": "2|FmY4ceEMU34PZDb6yuMwHFX2lnHAqUerNE9BmI0md7f1ca8d",
        "token_type": "Bearer",
        "user": {
            "id": 2,
            "type": "consultant",
            "name": "أ. أحمد العتيبي",
            "email": "ahmad.alotaibi@gcmc.sa",
            "phone": null,
            "title": "مستشار حوكمة",
            "specialization": "الحوكمة المؤسسية",
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": "2026-09-24T03:50:44+03:00",
            "roles": [
                "consultant"
            ],
            "permissions": [
                "view-dashboard",
                "… (9 more)"
            ],
            "created_at": "2026-09-24T03:50:40+03:00",
            "updated_at": "2026-09-24T03:50:44+03:00"
        }
    }
}
```

#### `GET /admin/auth/me` — Me (ADM-AUTH-03)

**Auth:** Admin token

The authenticated user with roles and the full permissions list — the frontend hides menu items by these.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 1,
        "type": "admin",
        "name": "Super Admin",
        "email": "admin@gcmc.sa",
        "phone": null,
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:44+03:00",
        "roles": [
            "admin"
        ],
        "permissions": [
            "view-dashboard",
            "… (33 more)"
        ],
        "created_at": "2026-09-24T03:50:39+03:00",
        "updated_at": "2026-09-24T03:50:44+03:00"
    }
}
```

#### `POST /admin/auth/forgot-password` — Forgot password (ADM-AUTH-04)

**Auth:** — (public)

Always 200 (does not leak whether the email exists). Sends the reset email.

| Field | Rules |
|---|---|
| email | required email |

Body:

```json
{
    "email": "{{admin_email}}"
}
```

Example response:

```json
{
    "success": true,
    "message": "إذا كان هذا البريد مسجلاً، فقد تم إرسال رابط إعادة تعيين كلمة المرور.",
    "data": null
}
```

#### `POST /admin/auth/reset-password` — Reset password (ADM-AUTH-05)

**Auth:** — (public)

Resets the password with the token from the email. All old tokens are revoked.

| Field | Rules |
|---|---|
| token | required |
| email | required email |
| password | required, confirmed, min 8 |

**Errors:** 422 `VALIDATION_ERROR` (bad/expired token, reason under `errors.email`)

Body:

```json
{
    "token": "PASTE-TOKEN-FROM-EMAIL",
    "email": "{{admin_email}}",
    "password": "Password@123",
    "password_confirmation": "Password@123"
}
```

### 02 Admin › Profile

#### `GET /admin/profile` — Show profile (ADM-PRF-01)

**Auth:** Admin token

The authenticated user profile.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 1,
        "type": "admin",
        "name": "Super Admin",
        "email": "admin@gcmc.sa",
        "phone": null,
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:44+03:00",
        "roles": [
            "admin"
        ],
        "permissions": [
            "view-dashboard",
            "… (33 more)"
        ],
        "created_at": "2026-09-24T03:50:39+03:00",
        "updated_at": "2026-09-24T03:50:44+03:00"
    }
}
```

#### `PUT /admin/profile` — Update profile (ADM-PRF-02)

**Auth:** Admin token

Updates the profile.

| Field | Rules |
|---|---|
| name | required, max 150 |
| email | required, email, unique |
| phone | optional |
| title | optional |
| specialization | optional |
| bio | optional |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "Super Admin",
    "email": "{{admin_email}}",
    "phone": "0500000000",
    "title": "مدير النظام",
    "specialization": null,
    "bio": null
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الملف الشخصي بنجاح.",
    "data": {
        "id": 1,
        "type": "admin",
        "name": "Super Admin",
        "email": "admin@gcmc.sa",
        "phone": "0500000000",
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:44+03:00",
        "roles": [
            "admin"
        ],
        "permissions": [
            "view-dashboard",
            "… (33 more)"
        ],
        "created_at": "2026-09-24T03:50:39+03:00",
        "updated_at": "2026-09-24T03:50:44+03:00"
    }
}
```

#### `POST /admin/profile/avatar` — Upload avatar (ADM-PRF-03)

**Auth:** Admin token

Uploads the avatar (jpg/png/webp ≤ 2 MB).

**Errors:** 422 `VALIDATION_ERROR`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `avatar` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الصورة الشخصية بنجاح.",
    "data": {
        "id": 1,
        "type": "admin",
        "name": "Super Admin",
        "email": "admin@gcmc.sa",
        "phone": "0500000000",
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": "http://localhost:8000/storage/2/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/2/conversions/avatar-thumb.jpg",
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:44+03:00",
        "roles": [
            "admin"
        ],
        "permissions": [
            "view-dashboard",
            "… (33 more)"
        ],
        "created_at": "2026-09-24T03:50:39+03:00",
        "updated_at": "2026-09-24T03:50:44+03:00"
    }
}
```

#### `DELETE /admin/profile/avatar` — Delete avatar (ADM-PRF-04)

**Auth:** Admin token

Removes the avatar.

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

#### `PUT /admin/profile/password` — Update password (ADM-PRF-05)

**Auth:** Admin token

Changes the password.

| Field | Rules |
|---|---|
| current_password | required |
| password | required, confirmed, min 8 |

**Errors:** 422 `VALIDATION_ERROR`, 422 `INVALID_CREDENTIALS`

Body:

```json
{
    "current_password": "{{admin_password}}",
    "password": "NewPassword@123",
    "password_confirmation": "NewPassword@123"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تغيير كلمة المرور بنجاح.",
    "data": null
}
```

### 03 Admin › Roles & Permissions

#### `GET /admin/permissions` — List permissions (ACL-01)

**Auth:** Admin token

All permissions grouped by group name.

**Permission:** `view-roles|view-permissions`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "group": "dashboard",
            "label": "لوحة التحكم",
            "permissions": [
                {
                    "id": 1,
                    "name": "view-dashboard",
                    "group": "dashboard",
                    "label": "عرض لوحة التحكم"
                }
            ]
        },
        "… (10 more)"
    ]
}
```

#### `GET /admin/roles` — List roles (ACL-02)

**Auth:** Admin token

All roles with permissions_count and users_count.

**Permission:** `view-roles`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "name": "consultant",
            "guard_name": "admin",
            "is_protected": true,
            "permissions_count": 10,
            "users_count": 6,
            "created_at": "2026-09-24T03:50:39+03:00"
        },
        "… (1 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 2,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/roles?page=1",
        "last": "http://localhost:8000/api/v1/admin/roles?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/roles` — Create role (ACL-03)

**Auth:** Admin token

Creates a role. `admin` is protected and cannot be created/edited/deleted.

**Permission:** `create-roles`

| Field | Rules |
|---|---|
| name | required, lowercase slug, unique |
| permissions | required array of permission names |

**Errors:** 422 `VALIDATION_ERROR`, 403 `FORBIDDEN`

Body:

```json
{
    "name": "supervisor-{{$timestamp}}",
    "permissions": [
        "view-bookings",
        "view-reports"
    ]
}
```

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 3,
        "name": "supervisor-1790211046",
        "guard_name": "admin",
        "is_protected": false,
        "permissions": [
            {
                "id": 25,
                "name": "view-bookings",
                "group": "bookings",
                "label": "عرض الحجوزات"
            },
            "… (1 more)"
        ],
        "created_at": "2026-09-24T03:50:45+03:00"
    }
}
```

#### `GET /admin/roles/{{role_id}}` — Show role (ACL-04)

**Auth:** Admin token

One role with its permissions.

**Permission:** `view-roles`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 3,
        "name": "supervisor-1790211046",
        "guard_name": "admin",
        "is_protected": false,
        "permissions_count": 2,
        "users_count": 0,
        "permissions": [
            {
                "id": 25,
                "name": "view-bookings",
                "group": "bookings",
                "label": "عرض الحجوزات"
            },
            "… (1 more)"
        ],
        "created_at": "2026-09-24T03:50:45+03:00"
    }
}
```

#### `PUT /admin/roles/{{role_id}}` — Update role (ACL-05)

**Auth:** Admin token

Renames a role and/or syncs its permissions.

**Permission:** `update-roles`

| Field | Rules |
|---|---|
| name | optional slug |
| permissions | optional array |

**Errors:** 422 `VALIDATION_ERROR`, 422 `ROLE_PROTECTED`

Body:

```json
{
    "permissions": [
        "view-bookings",
        "view-reports",
        "view-clients"
    ]
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 3,
        "name": "supervisor-1790211046",
        "guard_name": "admin",
        "is_protected": false,
        "permissions_count": 3,
        "users_count": 0,
        "permissions": [
            {
                "id": 18,
                "name": "view-clients",
                "group": "clients",
                "label": "عرض العملاء"
            },
            "… (2 more)"
        ],
        "created_at": "2026-09-24T03:50:45+03:00"
    }
}
```

#### `GET /admin/roles/{{role_id}}/users` — Role users (ACL-07)

**Auth:** Admin token

The users who have this role.

**Permission:** `view-roles`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/roles/3/users?page=1",
        "last": "http://localhost:8000/api/v1/admin/roles/3/users?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `DELETE /admin/roles/{{role_id}}` — Delete role (ACL-06)

**Auth:** Admin token

Deletes a role. The protected `admin` role and roles still assigned to users cannot be deleted.

**Permission:** `delete-roles`

**Errors:** 422 `ROLE_PROTECTED`, 422 `ROLE_HAS_USERS`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 04 Admin › Users

#### `GET /admin/users` — List users (USR-01)

**Auth:** Admin token

Staff users (admins and consultants).

**Permission:** `view-users`

| Query param | Notes |
|---|---|
| `search` *(optional)* | name, email or phone |
| `type` *(optional)* | admin | consultant |
| `role` *(optional)* | role name |
| `is_active` *(optional)* | 1 | 0 |
| `sort` *(optional)* | name | created_at | last_login_at (- for desc) |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 7,
            "type": "consultant",
            "name": "أ. نورة السبيعي",
            "email": "noura.alsubaie@gcmc.sa",
            "phone": null,
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": null,
            "roles": [
                "consultant"
            ],
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:41+03:00"
        },
        "… (6 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 7,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/users?page=1",
        "last": "http://localhost:8000/api/v1/admin/users?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/users` — Create user (USR-02)

**Auth:** Admin token

Creates a staff user. `type` defaults to `admin`; sending `consultant` also adds the consultant role. Without a password, a set-password email is sent.

**Permission:** `create-users`

| Field | Rules |
|---|---|
| name | required, max 150 |
| email | required, email, unique |
| password | optional, confirmed, min 8 |
| type | admin | consultant |
| roles | required array of role names |
| is_active | optional boolean |
| avatar | optional image ≤ 2 MB |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "موظف تجريبي",
    "email": "staff+{{$timestamp}}@gcmc.sa",
    "password": "Password@123",
    "password_confirmation": "Password@123",
    "type": "admin",
    "roles": [
        "admin"
    ],
    "is_active": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي",
        "email": "staff+1790211046@gcmc.sa",
        "phone": null,
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `GET /admin/users/{{user_id}}` — Show user (USR-03)

**Auth:** Admin token

One user with roles and permissions.

**Permission:** `view-users`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي",
        "email": "staff+1790211046@gcmc.sa",
        "phone": null,
        "title": null,
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `PUT /admin/users/{{user_id}}` — Update user (USR-04)

**Auth:** Admin token

Updates a user.

**Permission:** `update-users`

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "موظف تجريبي (محدّث)",
    "phone": "0551234567",
    "title": "موظف استقبال"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي (محدّث)",
        "email": "staff+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "موظف استقبال",
        "specialization": null,
        "bio": null,
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `POST /admin/users/{{user_id}}/avatar` — Upload user avatar (USR-08)

**Auth:** Admin token

Uploads the avatar of a user.

**Permission:** `update-users`

**Errors:** 422 `VALIDATION_ERROR`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `avatar` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الصورة الشخصية بنجاح.",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي (محدّث)",
        "email": "staff+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "موظف استقبال",
        "specialization": null,
        "bio": null,
        "avatar_url": "http://localhost:8000/storage/3/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/3/conversions/avatar-thumb.jpg",
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `PUT /admin/users/{{user_id}}/roles` — Sync user roles (USR-06)

**Auth:** Admin token

Replaces the roles of a user.

**Permission:** `assign-roles`

| Field | Rules |
|---|---|
| roles | required array of role names |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "roles": [
        "admin"
    ]
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تعيين الأدوار بنجاح.",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي (محدّث)",
        "email": "staff+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "موظف استقبال",
        "specialization": null,
        "bio": null,
        "avatar_url": "http://localhost:8000/storage/3/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/3/conversions/avatar-thumb.jpg",
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "permissions": [
            "view-dashboard",
            "… (33 more)"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `PATCH /admin/users/{{user_id}}/status` — Update user status (USR-07)

**Auth:** Admin token

Activates/deactivates a user. Deactivation revokes his tokens. The last active admin cannot be deactivated.

**Permission:** `update-users`

| Field | Rules |
|---|---|
| is_active | required boolean |

**Errors:** 422 `LAST_ADMIN`

Body:

```json
{
    "is_active": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 8,
        "type": "admin",
        "name": "موظف تجريبي (محدّث)",
        "email": "staff+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "موظف استقبال",
        "specialization": null,
        "bio": null,
        "avatar_url": "http://localhost:8000/storage/3/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/3/conversions/avatar-thumb.jpg",
        "is_active": true,
        "last_login_at": null,
        "roles": [
            "admin"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `DELETE /admin/users/{{user_id}}` — Delete user (USR-05)

**Auth:** Admin token

Deletes a user. You cannot delete yourself or the last active admin.

**Permission:** `delete-users`

**Errors:** 422 `LAST_ADMIN`, 422 `CANNOT_DELETE_SELF`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 05 Admin › Consultants

#### `GET /admin/consultants` — List consultants (CON-01)

**Auth:** Admin token

All consultants. A consultant calling this only sees himself.

**Permission:** `view-consultants`

| Query param | Notes |
|---|---|
| `search` *(optional)* | name, email, phone |
| `is_active` *(optional)* | 1 | 0 |
| `specialization` *(optional)* | exact match |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "email": "noura.alsubaie@gcmc.sa",
            "phone": null,
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "roles": [
                "consultant"
            ],
            "working_days": [
                0,
                "… (4 more)"
            ],
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:41+03:00"
        },
        "… (5 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 6,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/consultants` — Create consultant (CON-02)

**Auth:** Admin token

Creates a consultant; the role `consultant` is assigned automatically. Without a password, a set-password email is sent.

**Permission:** `create-consultants`

| Field | Rules |
|---|---|
| name | required, max 150 |
| email | required, email, unique |
| phone | optional |
| photo | optional image ≤ 2 MB |
| title | optional |
| specialization | optional |
| bio | optional |
| password | optional, confirmed |
| availability | optional {days: [{day_of_week, ranges: [{start_time, end_time}]}]} |

**Errors:** 422 `VALIDATION_ERROR`, 403 `FORBIDDEN`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `name` | text | أ. خالد المطيري |
| `email` | text | khaled+{{$timestamp}}@gcmc.sa |
| `phone` | text | 0551234567 |
| `title` | text | مستشار حوكمة |
| `specialization` | text | الحوكمة المؤسسية |
| `bio` | text | خبرة 10 سنوات في الحوكمة |
| `password` | text | Password@123 |
| `password_confirmation` | text | Password@123 |
| `is_active` | text | 1 |
| `photo` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 9,
        "name": "أ. خالد المطيري",
        "email": "khaled+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "مستشار حوكمة",
        "specialization": "الحوكمة المؤسسية",
        "bio": "خبرة 10 سنوات في الحوكمة",
        "avatar_url": "http://localhost:8000/storage/4/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/4/conversions/avatar-thumb.jpg",
        "is_active": true,
        "roles": [
            "consultant"
        ],
        "working_days": [],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}` — Show consultant (CON-03)

**Auth:** Admin token

A consultant with his availability. A consultant can only view himself.

**Permission:** `view-consultants`

**Errors:** 403 `FORBIDDEN`, 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 9,
        "name": "أ. خالد المطيري",
        "email": "khaled+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "مستشار حوكمة",
        "specialization": "الحوكمة المؤسسية",
        "bio": "خبرة 10 سنوات في الحوكمة",
        "avatar_url": "http://localhost:8000/storage/4/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/4/conversions/avatar-thumb.jpg",
        "is_active": true,
        "roles": [
            "consultant"
        ],
        "working_days": [],
        "availability": {
            "days": [
                {
                    "day_of_week": 0,
                    "day_name": "Sunday",
                    "day_name_ar": "الأحد",
                    "is_working": false,
                    "ranges": []
                },
                "… (6 more)"
            ]
        },
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `PUT /admin/consultants/{{consultant_id}}` — Update consultant (CON-04)

**Auth:** Admin token

Updates a consultant.

**Permission:** `update-consultants`

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "title": "مستشار حوكمة أول",
    "specialization": "الحوكمة المؤسسية",
    "bio": "خبرة 12 سنة"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 9,
        "name": "أ. خالد المطيري",
        "email": "khaled+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "مستشار حوكمة أول",
        "specialization": "الحوكمة المؤسسية",
        "bio": "خبرة 12 سنة",
        "avatar_url": "http://localhost:8000/storage/4/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/4/conversions/avatar-thumb.jpg",
        "is_active": true,
        "roles": [
            "consultant"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `POST /admin/consultants/{{consultant_id}}/photo` — Upload consultant photo (CON-07)

**Auth:** Admin token

Uploads the consultant photo shown on the public pages.

**Permission:** `update-consultants`

**Errors:** 422 `VALIDATION_ERROR`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `photo` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الصورة الشخصية بنجاح.",
    "data": {
        "id": 9,
        "name": "أ. خالد المطيري",
        "email": "khaled+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "مستشار حوكمة أول",
        "specialization": "الحوكمة المؤسسية",
        "bio": "خبرة 12 سنة",
        "avatar_url": "http://localhost:8000/storage/5/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/5/conversions/avatar-thumb.jpg",
        "is_active": true,
        "roles": [
            "consultant"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `PATCH /admin/consultants/{{consultant_id}}/status` — Update consultant status (CON-06)

**Auth:** Admin token

Activates/deactivates a consultant. Inactive consultants disappear from the public list and cannot be booked.

**Permission:** `update-consultants`

| Field | Rules |
|---|---|
| is_active | required boolean |

Body:

```json
{
    "is_active": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 9,
        "name": "أ. خالد المطيري",
        "email": "khaled+1790211046@gcmc.sa",
        "phone": "0551234567",
        "title": "مستشار حوكمة أول",
        "specialization": "الحوكمة المؤسسية",
        "bio": "خبرة 12 سنة",
        "avatar_url": "http://localhost:8000/storage/5/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/5/conversions/avatar-thumb.jpg",
        "is_active": true,
        "roles": [
            "consultant"
        ],
        "created_at": "2026-09-24T03:50:46+03:00",
        "updated_at": "2026-09-24T03:50:46+03:00"
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/availability` — Show availability (CON-08)

**Auth:** Admin token

The weekly availability of the consultant.

**Permission:** `view-availability`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "days": [
            {
                "day_of_week": 0,
                "day_name": "Sunday",
                "day_name_ar": "الأحد",
                "is_working": false,
                "ranges": []
            },
            "… (6 more)"
        ]
    }
}
```

#### `PUT /admin/consultants/{{consultant_id}}/availability` — Replace availability (CON-09)

**Auth:** Admin token

Replaces the whole weekly schedule. Days not sent are removed.

**Permission:** `manage-availability`

| Field | Rules |
|---|---|
| days | required array, max 7 |
| days.*.day_of_week | 0 (Sunday) – 6 (Saturday), distinct |
| days.*.ranges | present array |
| days.*.ranges.*.start_time / end_time | H:i |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "days": [
        {
            "day_of_week": 0,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 1,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 2,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 3,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 4,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        }
    ]
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تحديث التوفر بنجاح.",
    "data": {
        "days": [
            {
                "day_of_week": 0,
                "day_name": "Sunday",
                "day_name_ar": "الأحد",
                "is_working": true,
                "ranges": [
                    {
                        "id": 31,
                        "start_time": "09:00",
                        "end_time": "17:00"
                    }
                ]
            },
            "… (6 more)"
        ],
        "warnings": {
            "conflicting_bookings_count": 0
        }
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/time-offs` — List time-offs (CON-10)

**Auth:** Admin token

The days (or parts of days) the consultant is off.

**Permission:** `view-availability`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants/9/time-offs?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants/9/time-offs?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/consultants/{{consultant_id}}/time-offs` — Create time-off (CON-11)

**Auth:** Admin token

A day off; send start_time+end_time for a partial day.

**Permission:** `manage-availability`

| Field | Rules |
|---|---|
| date | required Y-m-d, today or later |
| start_time / end_time | optional pair H:i |
| reason | optional |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "date": "{{time_off_date}}",
    "reason": "إجازة سنوية"
}
```

Example response:

```json
{
    "success": true,
    "message": "تمت إضافة الإجازة بنجاح.",
    "data": {
        "id": 1,
        "date": "2027-01-22",
        "start_time": null,
        "end_time": null,
        "is_full_day": true,
        "reason": "إجازة سنوية",
        "created_at": "2026-09-24T03:50:46+03:00",
        "warnings": {
            "conflicting_bookings_count": 0
        }
    }
}
```

#### `DELETE /admin/consultants/{{consultant_id}}/time-offs/{{time_off_id}}` — Delete time-off (CON-12)

**Auth:** Admin token

Removes a time-off.

**Permission:** `manage-availability`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

#### `GET /admin/consultants/{{consultant_id}}/slots` — Consultant slots (CON-13)

**Auth:** Admin token

The same slot engine as PUB-06, for the dashboard.

**Permission:** `view-availability`

**Errors:** 422 `VALIDATION_ERROR`

| Query param | Notes |
|---|---|
| `date` | YYYY-MM-DD, required |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "date": "2026-10-01",
        "timezone": "Asia/Riyadh",
        "slot_minutes": 30,
        "duration_minutes": 30,
        "slots": [
            {
                "time": "09:00",
                "starts_at": "2026-10-01T09:00:00+03:00",
                "ends_at": "2026-10-01T09:30:00+03:00"
            },
            "… (15 more)"
        ]
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/clients` — Consultant clients (CON-14)

**Auth:** Admin token

The distinct clients who booked this consultant, with bookings_count and last_booking_at.

**Permission:** `view-clients`

| Query param | Notes |
|---|---|
| `search` *(optional)* | name/email/phone/company |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants/9/clients?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants/9/clients?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/bookings` — Consultant bookings (CON-15)

**Auth:** Admin token

The bookings of this consultant. The "Pending bookings" tab is `?status=pending`.

**Permission:** `view-bookings`

| Query param | Notes |
|---|---|
| `status` *(optional)* | pending | completed | cancelled |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants/9/bookings?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants/9/bookings?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/pending-reports` — Consultant pending reports (CON-16)

**Auth:** Admin token

Completed bookings still awaiting a report.

**Permission:** `view-reports`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants/9/pending-reports?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants/9/pending-reports?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/reports` — Consultant reports (CON-17)

**Auth:** Admin token

The reports uploaded for this consultant.

**Permission:** `view-reports`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/consultants/9/reports?page=1",
        "last": "http://localhost:8000/api/v1/admin/consultants/9/reports?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/consultants/{{consultant_id}}/stats` — Consultant stats (CON-18)

**Auth:** Admin token

The counters of the consultant profile page.

**Permission:** `view-consultants`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "pending_bookings": 0,
        "completed_bookings": 0,
        "cancelled_bookings": 0,
        "pending_reports": 0,
        "reports": 0,
        "clients": 0,
        "upcoming_bookings": []
    }
}
```

#### `DELETE /admin/consultants/{{consultant_id}}` — Delete consultant (CON-05)

**Auth:** Admin token

Deletes a consultant (kept last in the folder: the booking wizard re-reads the public list).

**Permission:** `delete-consultants`

**Errors:** 422 `CONSULTANT_HAS_FUTURE_BOOKINGS`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 06 Admin › My (consultant)

#### `GET /admin/my/availability` — My availability (MY-01)

**Auth:** Consultant token (admin guard)

The availability of the logged-in consultant.

**Permission:** `view-availability`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "days": [
            {
                "day_of_week": 0,
                "day_name": "Sunday",
                "day_name_ar": "الأحد",
                "is_working": true,
                "ranges": [
                    {
                        "id": 1,
                        "start_time": "09:00",
                        "end_time": "17:00"
                    }
                ]
            },
            "… (6 more)"
        ]
    }
}
```

#### `PUT /admin/my/availability` — Replace my availability (MY-02)

**Auth:** Consultant token (admin guard)

A consultant manages his own schedule.

**Permission:** `manage-availability`

Body:

```json
{
    "days": [
        {
            "day_of_week": 0,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 1,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 2,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 3,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        },
        {
            "day_of_week": 4,
            "ranges": [
                {
                    "start_time": "09:00",
                    "end_time": "17:00"
                }
            ]
        }
    ]
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تحديث التوفر بنجاح.",
    "data": {
        "days": [
            {
                "day_of_week": 0,
                "day_name": "Sunday",
                "day_name_ar": "الأحد",
                "is_working": true,
                "ranges": [
                    {
                        "id": 36,
                        "start_time": "09:00",
                        "end_time": "17:00"
                    }
                ]
            },
            "… (6 more)"
        ],
        "warnings": {
            "conflicting_bookings_count": 0
        }
    }
}
```

#### `GET /admin/my/time-offs` — My time-offs (MY-03)

**Auth:** Consultant token (admin guard)

The time-offs of the logged-in consultant.

**Permission:** `view-availability`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 0,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/my/time-offs?page=1",
        "last": "http://localhost:8000/api/v1/admin/my/time-offs?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/my/time-offs` — Create my time-off (MY-04)

**Auth:** Consultant token (admin guard)

Adds a day off.

**Permission:** `manage-availability`

| Field | Rules |
|---|---|
| date | required Y-m-d, today or later |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "date": "{{time_off_date}}",
    "reason": "موعد شخصي"
}
```

Example response:

```json
{
    "success": true,
    "message": "تمت إضافة الإجازة بنجاح.",
    "data": {
        "id": 2,
        "date": "2027-01-22",
        "start_time": null,
        "end_time": null,
        "is_full_day": true,
        "reason": "موعد شخصي",
        "created_at": "2026-09-24T03:50:47+03:00",
        "warnings": {
            "conflicting_bookings_count": 0
        }
    }
}
```

#### `DELETE /admin/my/time-offs/{{time_off_id}}` — Delete my time-off (MY-05)

**Auth:** Consultant token (admin guard)

Removes one of my time-offs.

**Permission:** `manage-availability`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

#### `GET /admin/my/slots` — My slots (MY-06)

**Auth:** Consultant token (admin guard)

My free slots for a date.

**Permission:** `view-availability`

| Query param | Notes |
|---|---|
| `date` | YYYY-MM-DD, required |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "date": "2026-10-01",
        "timezone": "Asia/Riyadh",
        "slot_minutes": 30,
        "duration_minutes": 30,
        "slots": [
            {
                "time": "09:00",
                "starts_at": "2026-10-01T09:00:00+03:00",
                "ends_at": "2026-10-01T09:30:00+03:00"
            },
            "… (15 more)"
        ]
    }
}
```

### 07 Admin › Packages

#### `GET /admin/packages` — List packages (PKG-01)

**Auth:** Admin token

All packages including inactive ones.

**Permission:** `view-packages`

| Query param | Notes |
|---|---|
| `search` *(optional)* | name |
| `is_active` *(optional)* | 1 | 0 |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package",
            "description": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_ar": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_en": "For start-up establishments beginning their governance and compliance journey.",
            "features": [
                {
                    "ar": "استشارتان شهرياً",
                    "en": "Two consultations monthly"
                },
                "… (3 more)"
            ],
            "features_localized": [
                "استشارتان شهرياً",
                "… (3 more)"
            ],
            "price": 190000,
            "price_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "billing_period_days": 30,
            "consultations_limit": 2,
            "documents_limit": 2,
            "is_unlimited": false,
            "is_featured": false,
            "is_active": true,
            "sort_order": 1,
            "subscriptions_count": 0,
            "created_at": "2026-09-24T03:50:39+03:00",
            "updated_at": "2026-09-24T03:50:39+03:00"
        },
        "… (2 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 3,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/packages?page=1",
        "last": "http://localhost:8000/api/v1/admin/packages?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/packages` — Create package (PKG-02)

**Auth:** Admin token

Creates a subscription package. Price in halalas.

**Permission:** `create-packages`

| Field | Rules |
|---|---|
| slug | required alpha_dash unique |
| name_ar / name_en | required, max 100 |
| price | required integer halalas |
| billing_period_days | optional, default 30 |
| consultations_limit | nullable = unlimited |
| documents_limit | nullable = unlimited |
| is_featured / is_active / sort_order | optional |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "slug": "test-{{$timestamp}}",
    "name_ar": "باقة تجريبية",
    "name_en": "Test Package",
    "description_ar": "باقة للاختبار",
    "description_en": "A test package",
    "features": [
        {
            "ar": "استشارة",
            "en": "One consultation"
        }
    ],
    "price": 100000,
    "billing_period_days": 30,
    "consultations_limit": 3,
    "documents_limit": 3,
    "is_featured": false,
    "is_active": true,
    "sort_order": 9
}
```

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 4,
        "slug": "test-1790211047",
        "name": "باقة تجريبية",
        "name_ar": "باقة تجريبية",
        "name_en": "Test Package",
        "description": "باقة للاختبار",
        "description_ar": "باقة للاختبار",
        "description_en": "A test package",
        "features": [
            {
                "ar": "استشارة",
                "en": "One consultation"
            }
        ],
        "features_localized": [
            "استشارة"
        ],
        "price": 100000,
        "price_formatted": "1,000.00 SAR",
        "currency": "SAR",
        "billing_period_days": 30,
        "consultations_limit": 3,
        "documents_limit": 3,
        "is_unlimited": false,
        "is_featured": false,
        "is_active": true,
        "sort_order": 9,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:47+03:00"
    }
}
```

#### `GET /admin/packages/{{package_id_new}}` — Show package (PKG-03)

**Auth:** Admin token

One package.

**Permission:** `view-packages`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 4,
        "slug": "test-1790211047",
        "name": "باقة تجريبية",
        "name_ar": "باقة تجريبية",
        "name_en": "Test Package",
        "description": "باقة للاختبار",
        "description_ar": "باقة للاختبار",
        "description_en": "A test package",
        "features": [
            {
                "ar": "استشارة",
                "en": "One consultation"
            }
        ],
        "features_localized": [
            "استشارة"
        ],
        "price": 100000,
        "price_formatted": "1,000.00 SAR",
        "currency": "SAR",
        "billing_period_days": 30,
        "consultations_limit": 3,
        "documents_limit": 3,
        "is_unlimited": false,
        "is_featured": false,
        "is_active": true,
        "sort_order": 9,
        "subscriptions_count": 0,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:47+03:00"
    }
}
```

#### `PUT /admin/packages/{{package_id_new}}` — Update package (PKG-04)

**Auth:** Admin token

Updates a package.

**Permission:** `update-packages`

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "price": 120000,
    "name_ar": "باقة تجريبية محدثة"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 4,
        "slug": "test-1790211047",
        "name": "باقة تجريبية محدثة",
        "name_ar": "باقة تجريبية محدثة",
        "name_en": "Test Package",
        "description": "باقة للاختبار",
        "description_ar": "باقة للاختبار",
        "description_en": "A test package",
        "features": [
            {
                "ar": "استشارة",
                "en": "One consultation"
            }
        ],
        "features_localized": [
            "استشارة"
        ],
        "price": 120000,
        "price_formatted": "1,200.00 SAR",
        "currency": "SAR",
        "billing_period_days": 30,
        "consultations_limit": 3,
        "documents_limit": 3,
        "is_unlimited": false,
        "is_featured": false,
        "is_active": true,
        "sort_order": 9,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:47+03:00"
    }
}
```

#### `PATCH /admin/packages/{{package_id_new}}/status` — Update package status (PKG-06)

**Auth:** Admin token

Activates/deactivates a package. Inactive packages disappear from the public list.

**Permission:** `update-packages`

| Field | Rules |
|---|---|
| is_active | required boolean |

Body:

```json
{
    "is_active": false
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 4,
        "slug": "test-1790211047",
        "name": "باقة تجريبية محدثة",
        "name_ar": "باقة تجريبية محدثة",
        "name_en": "Test Package",
        "description": "باقة للاختبار",
        "description_ar": "باقة للاختبار",
        "description_en": "A test package",
        "features": [
            {
                "ar": "استشارة",
                "en": "One consultation"
            }
        ],
        "features_localized": [
            "استشارة"
        ],
        "price": 120000,
        "price_formatted": "1,200.00 SAR",
        "currency": "SAR",
        "billing_period_days": 30,
        "consultations_limit": 3,
        "documents_limit": 3,
        "is_unlimited": false,
        "is_featured": false,
        "is_active": false,
        "sort_order": 9,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:47+03:00"
    }
}
```

#### `DELETE /admin/packages/{{package_id_new}}` — Delete package (PKG-05)

**Auth:** Admin token

Soft-deletes a package.

**Permission:** `delete-packages`

**Errors:** 422 `PACKAGE_IN_USE`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 08 Client › Auth

#### `POST /client/auth/register` — Register (CLI-AUTH-01)

**Auth:** — (public)

Dashboard B registration. Returns a token; sends the welcome email.

| Field | Rules |
|---|---|
| name | required, max 150 |
| email | required, email, unique among clients |
| phone | required, 05XXXXXXXX |
| company_name | required |
| password | required, confirmed, min 8 |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "عميل تجريبي",
    "email": "{{new_client_email}}",
    "phone": "0500000099",
    "company_name": "شركة الاختبار",
    "password": "Password@123",
    "password_confirmation": "Password@123",
    "device_name": "postman"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التسجيل بنجاح.",
    "data": {
        "token": "3|7fduzo4IZBocwWUVekhaQXcDd2ckimLuTSnXIRvCb1bb5343",
        "token_type": "Bearer",
        "user": {
            "id": 2,
            "name": "عميل تجريبي",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099",
            "company_name": "شركة الاختبار",
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": null,
            "created_at": "2026-09-24T03:50:47+03:00",
            "updated_at": "2026-09-24T03:50:47+03:00"
        }
    }
}
```

#### `POST /client/auth/login` — Login (CLI-AUTH-02)

**Auth:** — (public)

Client login. An admin email cannot log in here.

| Field | Rules |
|---|---|
| email | required email |
| password | required |

**Errors:** 422 `INVALID_CREDENTIALS`, 403 `ACCOUNT_DISABLED`

Body:

```json
{
    "email": "{{new_client_email}}",
    "password": "Password@123",
    "device_name": "postman"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الدخول بنجاح.",
    "data": {
        "token": "4|n02HzwCOcPnndcLgX6axTURiHjePL8n4HmAmvnDq27298486",
        "token_type": "Bearer",
        "user": {
            "id": 2,
            "name": "عميل تجريبي",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099",
            "company_name": "شركة الاختبار",
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": "2026-09-24T03:50:48+03:00",
            "created_at": "2026-09-24T03:50:47+03:00",
            "updated_at": "2026-09-24T03:50:48+03:00"
        }
    }
}
```

#### `GET /client/auth/me` — Me (CLI-AUTH-04)

**Auth:** Client token

The client with active_subscriptions, default_location and default_payment_method.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 2,
        "name": "عميل تجريبي",
        "email": "client+1790211047589@example.com",
        "phone": "0500000099",
        "company_name": "شركة الاختبار",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:48+03:00",
        "active_subscriptions": [],
        "default_location": null,
        "default_payment_method": null,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:48+03:00"
    }
}
```

#### `POST /client/auth/forgot-password` — Forgot password (CLI-AUTH-05)

**Auth:** — (public)

Always 200; sends the reset email.

| Field | Rules |
|---|---|
| email | required email |

Body:

```json
{
    "email": "{{client_email}}"
}
```

Example response:

```json
{
    "success": true,
    "message": "إذا كان هذا البريد مسجلاً، فقد تم إرسال رابط إعادة تعيين كلمة المرور.",
    "data": null
}
```

#### `POST /client/auth/reset-password` — Reset password (CLI-AUTH-06)

**Auth:** — (public)

Resets the password with the token from the email.

| Field | Rules |
|---|---|
| token | required |
| email | required email |
| password | required, confirmed, min 8 |

**Errors:** 422 `VALIDATION_ERROR` (bad/expired token, reason under `errors.email`)

Body:

```json
{
    "token": "PASTE-TOKEN-FROM-EMAIL",
    "email": "{{client_email}}",
    "password": "Password@123",
    "password_confirmation": "Password@123"
}
```

### 09 Client › Profile

#### `GET /client/profile` — Show profile (CLI-PRF-01)

**Auth:** Client token

The client profile.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 2,
        "name": "عميل تجريبي",
        "email": "client+1790211047589@example.com",
        "phone": "0500000099",
        "company_name": "شركة الاختبار",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:48+03:00",
        "active_subscriptions": [],
        "default_location": null,
        "default_payment_method": null,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:48+03:00"
    }
}
```

#### `PUT /client/profile` — Update profile (CLI-PRF-02)

**Auth:** Client token

Updates the profile.

| Field | Rules |
|---|---|
| name | required |
| email | required, unique |
| phone | required, 05XXXXXXXX |
| company_name | required |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "عميل تجريبي",
    "email": "{{new_client_email}}",
    "phone": "0500000099",
    "company_name": "شركة الاختبار المحدودة"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الملف الشخصي بنجاح.",
    "data": {
        "id": 2,
        "name": "عميل تجريبي",
        "email": "client+1790211047589@example.com",
        "phone": "0500000099",
        "company_name": "شركة الاختبار المحدودة",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:48+03:00",
        "active_subscriptions": [],
        "default_location": null,
        "default_payment_method": null,
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:48+03:00"
    }
}
```

#### `POST /client/profile/avatar` — Upload avatar (CLI-PRF-03)

**Auth:** Client token

Uploads the avatar (jpg/png/webp ≤ 2 MB).

**Errors:** 422 `VALIDATION_ERROR`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `avatar` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم تحديث الصورة الشخصية بنجاح.",
    "data": {
        "id": 2,
        "name": "عميل تجريبي",
        "email": "client+1790211047589@example.com",
        "phone": "0500000099",
        "company_name": "شركة الاختبار المحدودة",
        "avatar_url": "http://localhost:8000/storage/6/avatar.jpg",
        "avatar_thumb_url": "http://localhost:8000/storage/6/conversions/avatar-thumb.jpg",
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:48+03:00",
        "created_at": "2026-09-24T03:50:47+03:00",
        "updated_at": "2026-09-24T03:50:48+03:00"
    }
}
```

#### `DELETE /client/profile/avatar` — Delete avatar (CLI-PRF-04)

**Auth:** Client token

Removes the avatar.

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

#### `PUT /client/profile/password` — Update password (CLI-PRF-05)

**Auth:** Client token

Changes the password.

| Field | Rules |
|---|---|
| current_password | required |
| password | required, confirmed, min 8 |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "current_password": "Password@123",
    "password": "NewPassword@123",
    "password_confirmation": "NewPassword@123"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تغيير كلمة المرور بنجاح.",
    "data": null
}
```

### 10 Client › Locations

#### `GET /client/locations` — List locations (CLI-LOC-01)

**Auth:** Client token

The locations of the client; one of them is the default.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": []
}
```

#### `POST /client/locations` — Create location (CLI-LOC-02)

**Auth:** Client token

Adds a location (a branch).

| Field | Rules |
|---|---|
| name | required, max 150 |
| city | optional |
| address | required |
| latitude / longitude | optional |
| is_default | optional |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "المقر الرئيسي",
    "city": "الرياض",
    "address": "طريق الملك فهد، برج المكاتب",
    "latitude": 24.7136,
    "longitude": 46.6753,
    "is_default": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 5,
        "name": "المقر الرئيسي",
        "city": "الرياض",
        "address": "طريق الملك فهد، برج المكاتب",
        "latitude": "24.7136000",
        "longitude": "46.6753000",
        "is_default": true,
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `GET /client/locations/{{location_id}}` — Show location (CLI-LOC-03)

**Auth:** Client token

One location. 404 when it belongs to another client.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 5,
        "name": "المقر الرئيسي",
        "city": "الرياض",
        "address": "طريق الملك فهد، برج المكاتب",
        "latitude": "24.7136000",
        "longitude": "46.6753000",
        "is_default": true,
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `PUT /client/locations/{{location_id}}` — Update location (CLI-LOC-04)

**Auth:** Client token

Updates a location.

**Errors:** 422 `VALIDATION_ERROR`, 404 `NOT_FOUND`

Body:

```json
{
    "name": "المقر الرئيسي (محدّث)",
    "city": "الرياض",
    "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 5,
        "name": "المقر الرئيسي (محدّث)",
        "city": "الرياض",
        "address": "طريق الملك فهد، برج المكاتب، الدور ٥",
        "latitude": "24.7136000",
        "longitude": "46.6753000",
        "is_default": true,
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `PATCH /client/locations/{{location_id}}/default` — Set default location (CLI-LOC-06)

**Auth:** Client token

Marks the location as the default one.

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 5,
        "name": "المقر الرئيسي (محدّث)",
        "city": "الرياض",
        "address": "طريق الملك فهد، برج المكاتب، الدور ٥",
        "latitude": "24.7136000",
        "longitude": "46.6753000",
        "is_default": true,
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `POST /client/locations` — Create a second location (CLI-LOC-02)

**Auth:** Client token

A second location, deleted by the next request so the default one survives for the booking wizard.

Body:

```json
{
    "name": "فرع مؤقت",
    "city": "جدة",
    "address": "شارع التحلية"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم الإنشاء بنجاح.",
    "data": {
        "id": 6,
        "name": "فرع مؤقت",
        "city": "جدة",
        "address": "شارع التحلية",
        "latitude": null,
        "longitude": null,
        "is_default": false,
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `DELETE /client/locations/{{delete_location_id}}` — Delete location (CLI-LOC-05)

**Auth:** Client token

Deletes a location.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 11 Client › Payment methods

#### `GET /client/payment-methods` — List payment methods (CLI-PM-01)

**Auth:** Client token

The saved cards. `gateway_token` is never returned.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": []
}
```

#### `POST /client/payment-methods` — Save a card (CLI-PM-02)

**Auth:** Client token

Saves a card from a gateway token. Fake driver tokens: `tok_fake_success` (visa 4242), `tok_fake_3ds`, `tok_fake_declined`.

| Field | Rules |
|---|---|
| token | required |
| is_default | optional |

**Errors:** 422 `VALIDATION_ERROR`, 402 `PAYMENT_FAILED`

Body:

```json
{
    "token": "tok_fake_success",
    "is_default": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تمت إضافة طريقة الدفع بنجاح.",
    "data": {
        "id": 2,
        "brand": "visa",
        "last_four": "4242",
        "exp_month": 12,
        "exp_year": 2028,
        "holder_name": "Test Card",
        "is_default": true,
        "is_expired": false,
        "display": "Visa •••• 4242",
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `PATCH /client/payment-methods/{{payment_method_id}}/default` — Set default card (CLI-PM-04)

**Auth:** Client token

Marks a card as the default.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "تم تحديث طريقة الدفع الافتراضية بنجاح.",
    "data": {
        "id": 2,
        "brand": "visa",
        "last_four": "4242",
        "exp_month": 12,
        "exp_year": 2028,
        "holder_name": "Test Card",
        "is_default": true,
        "is_expired": false,
        "display": "Visa •••• 4242",
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `POST /client/payment-methods` — Save a second card (CLI-PM-02)

**Auth:** Client token

A second card, deleted by the next request so the default card survives for the booking wizard.

Body:

```json
{
    "token": "tok_fake_ok2"
}
```

Example response:

```json
{
    "success": true,
    "message": "تمت إضافة طريقة الدفع بنجاح.",
    "data": {
        "id": 3,
        "brand": "visa",
        "last_four": "4242",
        "exp_month": 12,
        "exp_year": 2028,
        "holder_name": "Test Card",
        "is_default": false,
        "is_expired": false,
        "display": "Visa •••• 4242",
        "created_at": "2026-09-24T03:50:49+03:00"
    }
}
```

#### `DELETE /client/payment-methods/{{delete_pm_id}}` — Delete card (CLI-PM-03)

**Auth:** Client token

Soft-deletes a saved card.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "تم حذف طريقة الدفع بنجاح.",
    "data": null
}
```

### 12 Client › Booking wizard

#### `GET /public/packages` — Step 1 – Packages (PUB-01)

**Auth:** — (public)

The client picks a package.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package",
            "description": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_ar": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_en": "For start-up establishments beginning their governance and compliance journey.",
            "features": [
                {
                    "ar": "استشارتان شهرياً",
                    "en": "Two consultations monthly"
                },
                "… (3 more)"
            ],
            "features_localized": [
                "استشارتان شهرياً",
                "… (3 more)"
            ],
            "price": 190000,
            "price_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "billing_period_days": 30,
            "consultations_limit": 2,
            "documents_limit": 2,
            "is_unlimited": false,
            "is_featured": false,
            "is_active": true,
            "sort_order": 1,
            "created_at": "2026-09-24T03:50:39+03:00",
            "updated_at": "2026-09-24T03:50:39+03:00"
        },
        "… (2 more)"
    ]
}
```

#### `GET /public/consultants` — Step 3 – Consultants (PUB-03)

**Auth:** — (public)

The client picks a consultant.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "bio": null,
            "avatar_url": null,
            "avatar_thumb_url": null,
            "working_days": [
                0,
                "… (4 more)"
            ]
        },
        "… (5 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 12,
        "total": 6,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/public/consultants?page=1",
        "last": "http://localhost:8000/api/v1/public/consultants?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /public/consultants/{{consultant_id}}/available-dates` — Step 4 – Available dates (PUB-05)

**Auth:** — (public)

The client picks a date.

| Query param | Notes |
|---|---|
| `month` | YYYY-MM |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "month": "2026-10",
        "dates": [
            "2026-10-01",
            "… (20 more)"
        ]
    }
}
```

#### `GET /public/consultants/{{consultant_id}}/slots` — Step 4 – Slots (PUB-06)

**Auth:** — (public)

The client picks a time.

| Query param | Notes |
|---|---|
| `date` | YYYY-MM-DD |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "date": "2026-10-01",
        "timezone": "Asia/Riyadh",
        "slot_minutes": 30,
        "duration_minutes": 30,
        "slots": [
            {
                "time": "09:00",
                "starts_at": "2026-10-01T09:00:00+03:00",
                "ends_at": "2026-10-01T09:30:00+03:00"
            },
            "… (15 more)"
        ]
    }
}
```

#### `GET /client/locations` — Step 5 – Locations (CLI-LOC-01)

**Auth:** Client token

The client picks where the consultation happens.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥",
            "latitude": "24.7136000",
            "longitude": "46.6753000",
            "is_default": true,
            "created_at": "2026-09-24T03:50:49+03:00"
        }
    ]
}
```

#### `GET /client/payment-methods` — Step 6 – Payment methods (CLI-PM-01)

**Auth:** Client token

The client picks a saved card or adds a new one.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "brand": "visa",
            "last_four": "4242",
            "exp_month": 12,
            "exp_year": 2028,
            "holder_name": "Test Card",
            "is_default": true,
            "is_expired": false,
            "display": "Visa •••• 4242",
            "created_at": "2026-09-24T03:50:49+03:00"
        }
    ]
}
```

#### `POST /client/bookings/quote` — Quote (CLI-BKG-01)

**Auth:** Client token

The price check. With an active subscription that still has consultations, `requires_payment` is false and the amount is 0. `slot_available` is only calculated when consultant+date+time are sent.

| Field | Rules |
|---|---|
| package_id | required |
| consultant_id | optional |
| date | optional Y-m-d |
| time | optional H:i |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "package_id": "{{package_id}}",
    "consultant_id": "{{consultant_id}}",
    "date": "{{slot_date}}",
    "time": "{{slot_time}}"
}
```

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "package": {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package",
            "description": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_ar": "للمنشآت الناشئة التي تبدأ رحلتها في الحوكمة والامتثال.",
            "description_en": "For start-up establishments beginning their governance and compliance journey.",
            "features": [
                {
                    "ar": "استشارتان شهرياً",
                    "en": "Two consultations monthly"
                },
                "… (3 more)"
            ],
            "features_localized": [
                "استشارتان شهرياً",
                "… (3 more)"
            ],
            "price": 190000,
            "price_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "billing_period_days": 30,
            "consultations_limit": 2,
            "documents_limit": 2,
            "is_unlimited": false,
            "is_featured": false,
            "is_active": true,
            "sort_order": 1,
            "created_at": "2026-09-24T03:50:39+03:00",
            "updated_at": "2026-09-24T03:50:39+03:00"
        },
        "requires_payment": true,
        "amount": 190000,
        "amount_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "subscription": null,
        "slot_available": true
    }
}
```

#### `POST /client/bookings` — Create booking – saved card (CLI-BKG-02)

**Auth:** Client token

Books with a saved card: the card is charged, the booking becomes `pending` (confirmed), the meeting is created and the confirmation email is sent.

| Field | Rules |
|---|---|
| package_id | required |
| consultant_id | required |
| date | required Y-m-d |
| time | required H:i |
| client_location_id | required |
| payment_method_id | required unless card_token |
| card_token | a new card token |
| save_card | optional |
| client_notes | optional |

**Errors:** 409 `SLOT_NOT_AVAILABLE`, 422 `PAYMENT_METHOD_REQUIRED`, 402 `PAYMENT_FAILED`, 422 `PACKAGE_INACTIVE`

Body:

```json
{
    "package_id": "{{package_id}}",
    "consultant_id": "{{consultant_id}}",
    "date": "{{slot_date}}",
    "time": "{{slot_time}}",
    "client_location_id": "{{location_id}}",
    "payment_method_id": "{{payment_method_id}}",
    "client_notes": "أرجو التركيز على الحوكمة"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم إنشاء الحجز بنجاح.",
    "data": {
        "booking": {
            "id": 4,
            "reference": "BK-2026-000004",
            "status": "pending",
            "status_label": "قيد الانتظار",
            "report_status": "none",
            "payment_status": "paid",
            "refund_status": "none",
            "date": "2026-10-01",
            "time": "09:00",
            "starts_at": "2026-10-01T09:00:00+03:00",
            "ends_at": "2026-10-01T09:30:00+03:00",
            "duration_minutes": 30,
            "amount": 190000,
            "amount_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 1,
                "slug": "iron",
                "name": "الباقة الحديدية",
                "name_ar": "الباقة الحديدية",
                "name_en": "Iron Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": "https://meet.google.com/fak-opaw-bnw"
            },
            "payment": {
                "id": 2,
                "booking_id": 4,
  …
```

#### `POST /client/bookings` — Create booking – new card 3DS (CLI-BKG-02)

**Auth:** Client token

Books another package with a new card that needs 3-D Secure: the payment is `initiated` and the client must be sent to `transaction_url`, then the app calls verify.

**Errors:** 409 `SLOT_NOT_AVAILABLE`, 402 `PAYMENT_FAILED`

Body:

```json
{
    "package_id": "{{package_id_silver}}",
    "consultant_id": "{{consultant_id}}",
    "date": "{{slot_date}}",
    "time": "{{slot_time_2}}",
    "client_location_id": "{{location_id}}",
    "card_token": "tok_fake_3ds",
    "save_card": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم إنشاء الحجز بنجاح.",
    "data": {
        "booking": {
            "id": 5,
            "reference": "BK-2026-000005",
            "status": "pending_payment",
            "status_label": "في انتظار الدفع",
            "report_status": "none",
            "payment_status": "unpaid",
            "refund_status": "none",
            "date": "2026-10-01",
            "time": "09:30",
            "starts_at": "2026-10-01T09:30:00+03:00",
            "ends_at": "2026-10-01T10:00:00+03:00",
            "duration_minutes": 30,
            "amount": 450000,
            "amount_formatted": "4,500.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 2,
                "slug": "silver",
                "name": "الباقة الفضية",
                "name_ar": "الباقة الفضية",
                "name_en": "Silver Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": null,
                "status": "none",
                "url": null
            },
            "payment": {
                "id": 3,
                "booking_id": 5,
  …
```

#### `POST /client/payments/{{payment_id_3ds}}/verify` — Verify payment (CLI-PAY-02)

**Auth:** Client token

After the 3DS redirect: fetches the payment from the gateway and applies the result (idempotent).

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "booking": {
            "id": 5,
            "reference": "BK-2026-000005",
            "status": "pending",
            "status_label": "قيد الانتظار",
            "report_status": "none",
            "payment_status": "paid",
            "refund_status": "none",
            "date": "2026-10-01",
            "time": "09:30",
            "starts_at": "2026-10-01T09:30:00+03:00",
            "ends_at": "2026-10-01T10:00:00+03:00",
            "duration_minutes": 30,
            "amount": 450000,
            "amount_formatted": "4,500.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 2,
                "slug": "silver",
                "name": "الباقة الفضية",
                "name_ar": "الباقة الفضية",
                "name_en": "Silver Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": "https://meet.google.com/fak-jhlb-9vj"
            },
            "payment": {
                "id": 3,
                "booking_id": 5,
  …
```

### 13 Client › Bookings, Payments, Subscriptions, Dashboard

#### `GET /client/bookings` — List bookings (CLI-BKG-03)

**Auth:** Client token

The bookings of the client.

| Query param | Notes |
|---|---|
| `status` *(optional)* | pending | completed | cancelled |
| `sort` *(optional)* | -starts_at (default) | starts_at |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 5,
            "reference": "BK-2026-000005",
            "status": "pending",
            "status_label": "قيد الانتظار",
            "report_status": "none",
            "payment_status": "paid",
            "refund_status": "none",
            "date": "2026-10-01",
            "time": "09:30",
            "starts_at": "2026-10-01T09:30:00+03:00",
            "ends_at": "2026-10-01T10:00:00+03:00",
            "duration_minutes": 30,
            "amount": 450000,
            "amount_formatted": "4,500.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 2,
                "slug": "silver",
                "name": "الباقة الفضية",
                "name_ar": "الباقة الفضية",
                "name_en": "Silver Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": "https://meet.google.com/fak-jhlb-9vj"
            },
            "payment": {
                "id": 3,
                "booking_id": 5,
  …
```

#### `GET /client/bookings/{{booking_id}}` — Show booking (CLI-BKG-04)

**Auth:** Client token

One booking with the meeting link and the report. 404 when it belongs to another client.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 4,
        "reference": "BK-2026-000004",
        "status": "pending",
        "status_label": "قيد الانتظار",
        "report_status": "none",
        "payment_status": "paid",
        "refund_status": "none",
        "date": "2026-10-01",
        "time": "09:00",
        "starts_at": "2026-10-01T09:00:00+03:00",
        "ends_at": "2026-10-01T09:30:00+03:00",
        "duration_minutes": 30,
        "amount": 190000,
        "amount_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": "https://meet.google.com/fak-opaw-bnw"
        },
        "payment": {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
  …
```

#### `POST /client/bookings/{{booking_id_3ds}}/cancel` — Cancel booking (CLI-BKG-05)

**Auth:** Client token

The client cancels up to `client_cancel_hours` (24) before the start. A paid booking gets `refund_status = requested`.

| Field | Rules |
|---|---|
| reason | optional, max 500 |

**Errors:** 422 `BOOKING_CANCEL_WINDOW_PASSED`, 422 `BOOKING_INVALID_STATUS`, 404 `NOT_FOUND`

Body:

```json
{
    "reason": "ظرف طارئ"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم إلغاء الحجز.",
    "data": {
        "id": 5,
        "reference": "BK-2026-000005",
        "status": "cancelled",
        "status_label": "ملغي",
        "report_status": "none",
        "payment_status": "paid",
        "refund_status": "requested",
        "date": "2026-10-01",
        "time": "09:30",
        "starts_at": "2026-10-01T09:30:00+03:00",
        "ends_at": "2026-10-01T10:00:00+03:00",
        "duration_minutes": 30,
        "amount": 450000,
        "amount_formatted": "4,500.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 2,
            "slug": "silver",
            "name": "الباقة الفضية",
            "name_ar": "الباقة الفضية",
            "name_en": "Silver Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": null
        },
        "payment": {
            "id": 3,
            "booking_id": 5,
            "booking_reference": "BK-2026-000005",
  …
```

#### `GET /client/payments` — List payments (CLI-PAY-01)

**Auth:** Client token

The payments of the client.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
            "amount": 190000,
            "amount_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "status": "paid",
            "gateway": "fake",
            "card_brand": "visa",
            "card_last_four": "4242",
            "failure_reason": null,
            "transaction_url": null,
            "paid_at": "2026-09-24T03:50:49+03:00",
            "created_at": "2026-09-24T03:50:49+03:00"
        },
        "… (1 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 2,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/client/payments?page=1",
        "last": "http://localhost:8000/api/v1/client/payments?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /client/subscriptions` — List subscriptions (CLI-SUB-01)

**Auth:** Client token

All subscriptions of the client with the remaining consultations.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "package": {
                "id": 1,
                "slug": "iron",
                "name": "الباقة الحديدية",
                "name_ar": "الباقة الحديدية",
                "name_en": "Iron Package"
            },
            "status": "active",
            "starts_at": "2026-09-24T03:50:49+03:00",
            "ends_at": "2026-10-24T03:50:49+03:00",
            "consultations_limit": 2,
            "consultations_used": 1,
            "consultations_remaining": 1,
            "is_unlimited": false,
            "price_paid": 190000,
            "price_paid_formatted": "1,900.00 SAR"
        },
        "… (1 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 2,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/client/subscriptions?page=1",
        "last": "http://localhost:8000/api/v1/client/subscriptions?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /client/subscriptions/active` — Active subscriptions (CLI-SUB-02)

**Auth:** Client token

Only the active ones.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "package": {
                "id": 1,
                "slug": "iron",
                "name": "الباقة الحديدية",
                "name_ar": "الباقة الحديدية",
                "name_en": "Iron Package"
            },
            "status": "active",
            "starts_at": "2026-09-24T03:50:49+03:00",
            "ends_at": "2026-10-24T03:50:49+03:00",
            "consultations_limit": 2,
            "consultations_used": 1,
            "consultations_remaining": 1,
            "is_unlimited": false,
            "price_paid": 190000,
            "price_paid_formatted": "1,900.00 SAR"
        },
        "… (1 more)"
    ]
}
```

#### `GET /client/dashboard` — Dashboard (CLI-DSH-01)

**Auth:** Client token

The client home: next booking, counters, unread reports, active subscriptions.

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "next_booking": {
            "id": 4,
            "reference": "BK-2026-000004",
            "status": "pending",
            "status_label": "قيد الانتظار",
            "report_status": "none",
            "payment_status": "paid",
            "refund_status": "none",
            "date": "2026-10-01",
            "time": "09:00",
            "starts_at": "2026-10-01T09:00:00+03:00",
            "ends_at": "2026-10-01T09:30:00+03:00",
            "duration_minutes": 30,
            "amount": 190000,
            "amount_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 1,
                "slug": "iron",
                "name": "الباقة الحديدية",
                "name_ar": "الباقة الحديدية",
                "name_en": "Iron Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": "https://meet.google.com/fak-opaw-bnw"
            },
            "payment": {
                "id": 2,
                "booking_id": 4,
  …
```

### 14 Admin › Bookings

#### `GET /admin/bookings` — List bookings (BKG-01)

**Auth:** Admin token

All bookings (a consultant only sees his own).

**Permission:** `view-bookings`

| Query param | Notes |
|---|---|
| `status` *(optional)* | pending_payment | pending | completed | cancelled |
| `consultant_id` *(optional)* | admin only |
| `client_id` *(optional)* | — |
| `date_from` *(optional)* | YYYY-MM-DD |
| `date_to` *(optional)* | YYYY-MM-DD |
| `search` *(optional)* | reference or client name |
| `sort` *(optional)* | -starts_at (default) |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 5,
            "reference": "BK-2026-000005",
            "status": "cancelled",
            "status_label": "ملغي",
            "report_status": "none",
            "payment_status": "paid",
            "refund_status": "requested",
            "date": "2026-10-01",
            "time": "09:30",
            "starts_at": "2026-10-01T09:30:00+03:00",
            "ends_at": "2026-10-01T10:00:00+03:00",
            "duration_minutes": 30,
            "amount": 450000,
            "amount_formatted": "4,500.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 2,
                "slug": "silver",
                "name": "الباقة الفضية",
                "name_ar": "الباقة الفضية",
                "name_en": "Silver Package"
            },
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي",
                "title": "مستشار جودة",
                "specialization": "جودة العمليات",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة",
                "email": "client+1790211047589@example.com",
                "phone": "0500000099"
            },
            "location": {
                "id": 5,
                "name": "المقر الرئيسي (محدّث)",
                "city": "الرياض",
                "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": null
            },
            "payment": {
                "id": 3,
                "booking_id": 5,
  …
```

#### `GET /admin/bookings` — List completed bookings (BKG-01)

**Auth:** Admin token

Finds the demo completed booking that still awaits a report; the report upload uses it.

**Permission:** `view-bookings`

| Query param | Notes |
|---|---|
| `status` | e.g. `completed` |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "reference": "BK-2026-000002",
            "status": "completed",
            "status_label": "مكتمل",
            "report_status": "pending",
            "payment_status": "not_required",
            "refund_status": "none",
            "date": "2026-09-22",
            "time": "12:00",
            "starts_at": "2026-09-22T12:00:00+03:00",
            "ends_at": "2026-09-22T12:30:00+03:00",
            "duration_minutes": 30,
            "amount": 0,
            "amount_formatted": "0.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 3,
                "slug": "gold",
                "name": "الباقة الذهبية",
                "name_ar": "الباقة الذهبية",
                "name_en": "Gold Package"
            },
            "consultant": {
                "id": 3,
                "name": "د. سارة الدوسري",
                "title": "مستشار امتثال",
                "specialization": "الامتثال التنظيمي",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 1,
                "name": "عميل تجريبي",
                "company_name": "شركة تجريبية",
                "email": "client@gcmc.sa",
                "phone": "0500000001"
            },
            "location": {
                "id": 1,
                "name": "الفرع الرئيسي — الرياض",
                "city": "الرياض",
                "address": "طريق العروبة، حي العليا، برج المملكة، الدور ١٨"
            },
            "meeting": {
                "provider": "fake",
                "status": "created",
                "url": "https://meet.google.com/fak-dem-o456"
            },
            "payment": null,
            "report": null,
            "client_notes": null,
  …
```

#### `GET /admin/bookings/{{booking_id}}` — Show booking (BKG-02)

**Auth:** Admin token

One booking with its payments.

**Permission:** `view-bookings`

**Errors:** 404 `NOT_FOUND`, 403 `FORBIDDEN`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 4,
        "reference": "BK-2026-000004",
        "status": "pending",
        "status_label": "قيد الانتظار",
        "report_status": "none",
        "payment_status": "paid",
        "refund_status": "none",
        "date": "2026-10-01",
        "time": "09:00",
        "starts_at": "2026-10-01T09:00:00+03:00",
        "ends_at": "2026-10-01T09:30:00+03:00",
        "duration_minutes": 30,
        "amount": 190000,
        "amount_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": "https://meet.google.com/fak-opaw-bnw"
        },
        "payment": {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
  …
```

#### `POST /admin/bookings/{{booking_id}}/meeting` — Regenerate meeting (BKG-05)

**Auth:** Admin token

Re-creates the meeting link (e.g. after a Google failure).

**Permission:** `manage-meetings`

**Errors:** 422 `BOOKING_INVALID_STATUS`, 502 `MEETING_CREATION_FAILED`

Example response:

```json
{
    "success": true,
    "message": "تم إنشاء رابط اجتماع جديد.",
    "data": {
        "id": 4,
        "reference": "BK-2026-000004",
        "status": "pending",
        "status_label": "قيد الانتظار",
        "report_status": "none",
        "payment_status": "paid",
        "refund_status": "none",
        "date": "2026-10-01",
        "time": "09:00",
        "starts_at": "2026-10-01T09:00:00+03:00",
        "ends_at": "2026-10-01T09:30:00+03:00",
        "duration_minutes": 30,
        "amount": 190000,
        "amount_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": "https://meet.google.com/fak-8mfz-s55"
        },
        "payment": {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
  …
```

#### `POST /admin/bookings/{{booking_id}}/complete` — Complete booking (BKG-03)

**Auth:** Admin token

Marks the booking completed after the session. The start time must have passed; the booking then waits for the report. Skipped in the CI run: the seeded pending bookings start in the future — run it by hand on a booking whose start has passed.

**Permission:** `complete-bookings`

| Field | Rules |
|---|---|
| notes | optional, max 1000 |

**Errors:** 422 `BOOKING_NOT_STARTED`, 422 `BOOKING_INVALID_STATUS`

Body:

```json
{
    "notes": "تمت الجلسة بنجاح"
}
```

#### `POST /admin/bookings/{{booking_id}}/cancel` — Cancel booking (BKG-04)

**Auth:** Admin token

The admin cancels at any time; a paid booking gets `refund_status = requested`. The client and the consultant are notified.

**Permission:** `cancel-bookings`

| Field | Rules |
|---|---|
| reason | required, max 500 |

**Errors:** 422 `BOOKING_INVALID_STATUS`

Body:

```json
{
    "reason": "إلغاء بناءً على طلب العميل"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم إلغاء الحجز.",
    "data": {
        "id": 4,
        "reference": "BK-2026-000004",
        "status": "cancelled",
        "status_label": "ملغي",
        "report_status": "none",
        "payment_status": "paid",
        "refund_status": "requested",
        "date": "2026-10-01",
        "time": "09:00",
        "starts_at": "2026-10-01T09:00:00+03:00",
        "ends_at": "2026-10-01T09:30:00+03:00",
        "duration_minutes": 30,
        "amount": 190000,
        "amount_formatted": "1,900.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 1,
            "slug": "iron",
            "name": "الباقة الحديدية",
            "name_ar": "الباقة الحديدية",
            "name_en": "Iron Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": null
        },
        "payment": {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
  …
```

#### `GET /admin/bookings/calendar` — Calendar (BKG-06)

**Auth:** Admin token

The bookings of a period (max 62 days) for the calendar view.

**Permission:** `view-bookings`

| Query param | Notes |
|---|---|
| `from` | YYYY-MM-DD, required |
| `to` | YYYY-MM-DD, required, from + 62 days max |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 3,
            "reference": "BK-2026-000003",
            "status": "pending",
            "starts_at": "2026-09-27T10:00:00+03:00",
            "ends_at": "2026-09-27T10:30:00+03:00",
            "consultant": {
                "id": 2,
                "name": "أ. أحمد العتيبي"
            },
            "client": {
                "id": 1,
                "company_name": "شركة تجريبية"
            }
        },
        "… (2 more)"
    ]
}
```

#### `POST /admin/bookings/{{booking_id_3ds}}/mark-refunded` — Mark refunded (BKG-07)

**Auth:** Admin token

After refunding in the gateway dashboard: marks the booking refunded. Only possible while `refund_status = requested` (the client cancelled this paid booking in folder 13).

**Permission:** `refund-payments`

**Errors:** 422 `BOOKING_INVALID_STATUS`

Example response:

```json
{
    "success": true,
    "message": "تم تعليم الحجز كمسترد.",
    "data": {
        "id": 5,
        "reference": "BK-2026-000005",
        "status": "cancelled",
        "status_label": "ملغي",
        "report_status": "none",
        "payment_status": "refunded",
        "refund_status": "refunded",
        "date": "2026-10-01",
        "time": "09:30",
        "starts_at": "2026-10-01T09:30:00+03:00",
        "ends_at": "2026-10-01T10:00:00+03:00",
        "duration_minutes": 30,
        "amount": 450000,
        "amount_formatted": "4,500.00 SAR",
        "currency": "SAR",
        "package": {
            "id": 2,
            "slug": "silver",
            "name": "الباقة الفضية",
            "name_ar": "الباقة الفضية",
            "name_en": "Silver Package"
        },
        "consultant": {
            "id": 7,
            "name": "أ. نورة السبيعي",
            "title": "مستشار جودة",
            "specialization": "جودة العمليات",
            "avatar_thumb_url": null
        },
        "client": {
            "id": 2,
            "name": "عميل تجريبي",
            "company_name": "شركة الاختبار المحدودة",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099"
        },
        "location": {
            "id": 5,
            "name": "المقر الرئيسي (محدّث)",
            "city": "الرياض",
            "address": "طريق الملك فهد، برج المكاتب، الدور ٥"
        },
        "meeting": {
            "provider": "fake",
            "status": "created",
            "url": null
        },
        "payment": {
            "id": 3,
            "booking_id": 5,
            "booking_reference": "BK-2026-000005",
  …
```

### 15 Admin › Reports

#### `GET /admin/reports` — List reports (RPT-01)

**Auth:** Admin token

All reports (a consultant only sees his own).

**Permission:** `view-reports`

| Query param | Notes |
|---|---|
| `consultant_id` *(optional)* | admin only |
| `client_id` *(optional)* | — |
| `date_from` *(optional)* | YYYY-MM-DD |
| `date_to` *(optional)* | YYYY-MM-DD |
| `search` *(optional)* | title or booking reference |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "title": "تقرير الحوكمة المؤسسية",
            "summary": "تقرير تجريبي يغطي تقييم ممارسات الحوكمة المؤسسية والتوصيات.",
            "booking": {
                "id": 1,
                "reference": "BK-2026-000001",
                "date": "2026-09-21",
                "time": "10:00"
            },
            "consultant": {
                "id": 2,
                "name": "أ. أحمد العتيبي"
            },
            "client": {
                "id": 1,
                "name": "عميل تجريبي",
                "company_name": "شركة تجريبية"
            },
            "file": {
                "name": "governance-report.pdf",
                "size": 192,
                "size_human": "192 B",
                "mime_type": "application/pdf"
            },
            "download_url": "http://localhost:8000/api/v1/admin/reports/1/download",
            "client_notified_at": "2026-09-24T03:50:41+03:00",
            "first_downloaded_at": null,
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:41+03:00"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 1,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/reports?page=1",
        "last": "http://localhost:8000/api/v1/admin/reports?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `POST /admin/bookings/{{completed_booking_id}}/report` — Upload report (RPT-03)

**Auth:** Admin token

Uploads the report of a completed booking (pdf/doc/docx ≤ 20 MB, private disk). Uploading again replaces the file. The client gets an email with a dashboard link and a 7-day signed download link unless `notify_client` is false.

**Permission:** `upload-reports`

| Field | Rules |
|---|---|
| title | required, max 191 |
| summary | optional |
| file | required pdf/doc/docx ≤ 20 MB |
| notify_client | optional, default true |

**Errors:** 422 `REPORT_NOT_ALLOWED` (booking not completed), 422 `VALIDATION_ERROR`, 403 `FORBIDDEN`

Body (multipart/form-data):

| Field | Type | Example |
|---|---|---|
| `title` | text | تقرير الحوكمة المؤسسية |
| `summary` | text | تقييم شامل لممارسات الحوكمة مع التوصيات. |
| `file` | file | (file) |

Example response:

```json
{
    "success": true,
    "message": "تم رفع التقرير بنجاح.",
    "data": {
        "id": 2,
        "title": "تقرير الحوكمة المؤسسية",
        "summary": "تقييم شامل لممارسات الحوكمة مع التوصيات.",
        "booking": {
            "id": 2,
            "reference": "BK-2026-000002",
            "date": "2026-09-22",
            "time": "12:00"
        },
        "consultant": {
            "id": 3,
            "name": "د. سارة الدوسري"
        },
        "client": {
            "id": 1,
            "name": "عميل تجريبي",
            "company_name": "شركة تجريبية"
        },
        "file": {
            "name": "report.pdf",
            "size": 449,
            "size_human": "449 B",
            "mime_type": "application/pdf"
        },
        "download_url": "http://localhost:8000/api/v1/admin/reports/2/download",
        "client_notified_at": "2026-09-24T03:50:50+03:00",
        "first_downloaded_at": null,
        "created_at": "2026-09-24T03:50:50+03:00",
        "updated_at": "2026-09-24T03:50:50+03:00"
    }
}
```

#### `GET /admin/reports/{{report_id}}` — Show report (RPT-02)

**Auth:** Admin token

One report with the guard-specific download_url.

**Permission:** `view-reports`

**Errors:** 404 `NOT_FOUND`, 403 `FORBIDDEN`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 2,
        "title": "تقرير الحوكمة المؤسسية",
        "summary": "تقييم شامل لممارسات الحوكمة مع التوصيات.",
        "booking": {
            "id": 2,
            "reference": "BK-2026-000002",
            "date": "2026-09-22",
            "time": "12:00"
        },
        "consultant": {
            "id": 3,
            "name": "د. سارة الدوسري"
        },
        "client": {
            "id": 1,
            "name": "عميل تجريبي",
            "company_name": "شركة تجريبية"
        },
        "file": {
            "name": "report.pdf",
            "size": 449,
            "size_human": "449 B",
            "mime_type": "application/pdf"
        },
        "download_url": "http://localhost:8000/api/v1/admin/reports/2/download",
        "client_notified_at": "2026-09-24T03:50:50+03:00",
        "first_downloaded_at": null,
        "created_at": "2026-09-24T03:50:50+03:00",
        "updated_at": "2026-09-24T03:50:50+03:00"
    }
}
```

#### `GET /admin/reports/{{report_id}}/download` — Download report (RPT-04)

**Auth:** Admin token

Streams the file as an attachment.

**Permission:** `download-reports`

**Errors:** 404 `NOT_FOUND`, 403 `FORBIDDEN`

Example response:

```json
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj
4 0 obj<</Length 90>>stream
BT /F1 24 Tf 72 720 Td (GCMC demo report fixture) Tj ET
endstream
endobj
5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj
xref
0 6
0000000000 65535 f 
trailer<</Size 6/Root 1 0 R>>
startxref
0
%%EOF
```

#### `POST /admin/reports/{{report_id}}/notify-client` — Notify client (RPT-06)

**Auth:** Admin token

Resends the report-ready email.

**Permission:** `upload-reports`

Example response:

```json
{
    "success": true,
    "message": "تم إشعار العميل بالتقرير.",
    "data": {
        "id": 2,
        "title": "تقرير الحوكمة المؤسسية",
        "summary": "تقييم شامل لممارسات الحوكمة مع التوصيات.",
        "booking": {
            "id": 2,
            "reference": "BK-2026-000002",
            "date": "2026-09-22",
            "time": "12:00"
        },
        "consultant": {
            "id": 3,
            "name": "د. سارة الدوسري"
        },
        "client": {
            "id": 1,
            "name": "عميل تجريبي",
            "company_name": "شركة تجريبية"
        },
        "file": {
            "name": "report.pdf",
            "size": 449,
            "size_human": "449 B",
            "mime_type": "application/pdf"
        },
        "download_url": "http://localhost:8000/api/v1/admin/reports/2/download",
        "client_notified_at": "2026-09-24T03:50:50+03:00",
        "first_downloaded_at": null,
        "created_at": "2026-09-24T03:50:50+03:00",
        "updated_at": "2026-09-24T03:50:50+03:00"
    }
}
```

#### `DELETE /admin/reports/{{report_id}}` — Delete report (RPT-05)

**Auth:** Admin token

Deletes the report and its file; the booking goes back to awaiting a report. (Deletes the report uploaded above — the seeded demo report survives for the client folder.)

**Permission:** `delete-reports`

**Errors:** 403 `FORBIDDEN`

Example response:

```json
{
    "success": true,
    "message": "تم حذف التقرير.",
    "data": null
}
```

### 16 Client › Reports

#### `POST /client/auth/login` — Login as demo client (CLI-AUTH-02)

**Auth:** — (public)

The demo client owns the seeded report, so this folder runs as him.

Body:

```json
{
    "email": "{{client_email}}",
    "password": "{{client_password}}",
    "device_name": "postman"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الدخول بنجاح.",
    "data": {
        "token": "5|Q4bdltj74S96CrACS8Ymr4cZPVIY9G8io68KAPyM9764e36b",
        "token_type": "Bearer",
        "user": {
            "id": 1,
            "name": "عميل تجريبي",
            "email": "client@gcmc.sa",
            "phone": "0500000001",
            "company_name": "شركة تجريبية",
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": "2026-09-24T03:50:51+03:00",
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:51+03:00"
        }
    }
}
```

#### `GET /client/reports` — List reports (CLI-RPT-01)

**Auth:** Client token

The reports of the client. `download_url` points to CLI-RPT-03.

| Query param | Notes |
|---|---|
| `search` *(optional)* | title or consultant name |
| `date_from` *(optional)* | YYYY-MM-DD |
| `date_to` *(optional)* | YYYY-MM-DD |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "title": "تقرير الحوكمة المؤسسية",
            "summary": "تقرير تجريبي يغطي تقييم ممارسات الحوكمة المؤسسية والتوصيات.",
            "booking": {
                "id": 1,
                "reference": "BK-2026-000001",
                "date": "2026-09-21",
                "time": "10:00"
            },
            "consultant": {
                "id": 2,
                "name": "أ. أحمد العتيبي"
            },
            "client": {
                "id": 1,
                "name": "عميل تجريبي",
                "company_name": "شركة تجريبية"
            },
            "file": {
                "name": "governance-report.pdf",
                "size": 192,
                "size_human": "192 B",
                "mime_type": "application/pdf"
            },
            "download_url": "http://localhost:8000/api/v1/client/reports/1/download",
            "client_notified_at": "2026-09-24T03:50:41+03:00",
            "first_downloaded_at": null,
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:41+03:00"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 1,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/client/reports?page=1",
        "last": "http://localhost:8000/api/v1/client/reports?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /client/reports/{{demo_report_id}}` — Show report (CLI-RPT-02)

**Auth:** Client token

One report. 404 when it belongs to another client.

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 1,
        "title": "تقرير الحوكمة المؤسسية",
        "summary": "تقرير تجريبي يغطي تقييم ممارسات الحوكمة المؤسسية والتوصيات.",
        "booking": {
            "id": 1,
            "reference": "BK-2026-000001",
            "date": "2026-09-21",
            "time": "10:00"
        },
        "consultant": {
            "id": 2,
            "name": "أ. أحمد العتيبي"
        },
        "client": {
            "id": 1,
            "name": "عميل تجريبي",
            "company_name": "شركة تجريبية"
        },
        "file": {
            "name": "governance-report.pdf",
            "size": 192,
            "size_human": "192 B",
            "mime_type": "application/pdf"
        },
        "download_url": "http://localhost:8000/api/v1/client/reports/1/download",
        "client_notified_at": "2026-09-24T03:50:41+03:00",
        "first_downloaded_at": null,
        "created_at": "2026-09-24T03:50:41+03:00",
        "updated_at": "2026-09-24T03:50:41+03:00"
    }
}
```

#### `GET /client/reports/{{demo_report_id}}/download` — Download report (CLI-RPT-03)

**Auth:** Client token

Streams the file; the first download sets `first_downloaded_at` (the "unread" counter of the dashboard).

**Errors:** 404 `NOT_FOUND`

Example response:

```json
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj
trailer<</Root 1 0 R>>
%%EOF
```

### 17 Admin › Clients

#### `GET /admin/clients` — List clients (ADM-CL-01)

**Auth:** Admin token

All clients with bookings_count, reports_count and the active subscription. A consultant only sees clients who booked him.

**Permission:** `view-clients`

| Query param | Notes |
|---|---|
| `search` *(optional)* | name, email, phone, company |
| `is_active` *(optional)* | 1 | 0 |
| `consultant_id` *(optional)* | admin only |
| `has_active_subscription` *(optional)* | 1 | 0 |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "name": "عميل تجريبي",
            "email": "client+1790211047589@example.com",
            "phone": "0500000099",
            "company_name": "شركة الاختبار المحدودة",
            "avatar_url": null,
            "avatar_thumb_url": null,
            "is_active": true,
            "last_login_at": "2026-09-24T03:50:48+03:00",
            "bookings_count": 2,
            "reports_count": 0,
            "active_subscription": {
                "id": 2,
                "status": "active",
                "starts_at": "2026-09-24T03:50:49+03:00",
                "ends_at": "2026-10-24T03:50:49+03:00",
                "consultations_limit": 2,
                "consultations_used": 0,
                "consultations_remaining": 2,
                "is_unlimited": false,
                "price_paid": 190000,
                "price_paid_formatted": "1,900.00 SAR"
            },
            "created_at": "2026-09-24T03:50:47+03:00",
            "updated_at": "2026-09-24T03:50:49+03:00"
        },
        "… (1 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 2,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/clients?page=1",
        "last": "http://localhost:8000/api/v1/admin/clients?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/clients/{{client_id}}` — Show client (ADM-CL-02)

**Auth:** Admin token

One client with locations, subscriptions and counts.

**Permission:** `view-clients`

**Errors:** 404 `NOT_FOUND`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "id": 1,
        "name": "عميل تجريبي",
        "email": "client@gcmc.sa",
        "phone": "0500000001",
        "company_name": "شركة تجريبية",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:51+03:00",
        "bookings_count": 3,
        "reports_count": 1,
        "locations": [
            {
                "id": 1,
                "name": "الفرع الرئيسي — الرياض",
                "city": "الرياض",
                "address": "طريق العروبة، حي العليا، برج المملكة، الدور ١٨",
                "latitude": "24.7136000",
                "longitude": "46.6753000",
                "is_default": true,
                "created_at": "2026-09-24T03:50:41+03:00"
            },
            "… (3 more)"
        ],
        "active_subscription": {
            "id": 1,
            "status": "active",
            "starts_at": "2026-09-01T00:00:00+03:00",
            "ends_at": "2026-10-01T00:00:00+03:00",
            "consultations_limit": null,
            "consultations_used": 1,
            "consultations_remaining": null,
            "is_unlimited": true,
            "price_paid": 980000,
            "price_paid_formatted": "9,800.00 SAR"
        },
        "created_at": "2026-09-24T03:50:41+03:00",
        "updated_at": "2026-09-24T03:50:51+03:00"
    }
}
```

#### `PUT /admin/clients/{{client_id}}` — Update client (ADM-CL-03)

**Auth:** Admin token

Updates a client.

**Permission:** `update-clients`

| Field | Rules |
|---|---|
| name | required |
| email | required, unique |
| phone | required, 05XXXXXXXX |
| company_name | required |

**Errors:** 422 `VALIDATION_ERROR`

Body:

```json
{
    "name": "عميل تجريبي",
    "email": "client@gcmc.sa",
    "phone": "0500000001",
    "company_name": "شركة تجريبية"
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 1,
        "name": "عميل تجريبي",
        "email": "client@gcmc.sa",
        "phone": "0500000001",
        "company_name": "شركة تجريبية",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:51+03:00",
        "created_at": "2026-09-24T03:50:41+03:00",
        "updated_at": "2026-09-24T03:50:51+03:00"
    }
}
```

#### `PATCH /admin/clients/{{client_id}}/status` — Update client status (ADM-CL-04)

**Auth:** Admin token

Activates/deactivates a client. Deactivation revokes his tokens.

**Permission:** `update-clients`

| Field | Rules |
|---|---|
| is_active | required boolean |

Body:

```json
{
    "is_active": true
}
```

Example response:

```json
{
    "success": true,
    "message": "تم التحديث بنجاح.",
    "data": {
        "id": 1,
        "name": "عميل تجريبي",
        "email": "client@gcmc.sa",
        "phone": "0500000001",
        "company_name": "شركة تجريبية",
        "avatar_url": null,
        "avatar_thumb_url": null,
        "is_active": true,
        "last_login_at": "2026-09-24T03:50:51+03:00",
        "created_at": "2026-09-24T03:50:41+03:00",
        "updated_at": "2026-09-24T03:50:51+03:00"
    }
}
```

#### `GET /admin/clients/{{client_id}}/bookings` — Client bookings (ADM-CL-06)

**Auth:** Admin token

The bookings of this client.

**Permission:** `view-bookings`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 3,
            "reference": "BK-2026-000003",
            "status": "pending",
            "status_label": "قيد الانتظار",
            "report_status": "none",
            "payment_status": "not_required",
            "refund_status": "none",
            "date": "2026-09-27",
            "time": "10:00",
            "starts_at": "2026-09-27T10:00:00+03:00",
            "ends_at": "2026-09-27T10:30:00+03:00",
            "duration_minutes": 30,
            "amount": 0,
            "amount_formatted": "0.00 SAR",
            "currency": "SAR",
            "package": {
                "id": 3,
                "slug": "gold",
                "name": "الباقة الذهبية",
                "name_ar": "الباقة الذهبية",
                "name_en": "Gold Package"
            },
            "consultant": {
                "id": 2,
                "name": "أ. أحمد العتيبي",
                "title": "مستشار حوكمة",
                "specialization": "الحوكمة المؤسسية",
                "avatar_thumb_url": null
            },
            "client": {
                "id": 1,
                "name": "عميل تجريبي",
                "company_name": "شركة تجريبية",
                "email": "client@gcmc.sa",
                "phone": "0500000001"
            },
            "location": {
                "id": 1,
                "name": "الفرع الرئيسي — الرياض",
                "city": "الرياض",
                "address": "طريق العروبة، حي العليا، برج المملكة، الدور ١٨"
            },
            "meeting": {
                "provider": "fake",
                "status": "pending",
                "url": null
            },
            "payment": null,
            "client_notes": "حجز تجريبي قادم",
            "can": {
  …
```

#### `GET /admin/clients/{{client_id}}/reports` — Client reports (ADM-CL-07)

**Auth:** Admin token

The reports of this client.

**Permission:** `view-reports`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "title": "تقرير الحوكمة المؤسسية",
            "summary": "تقرير تجريبي يغطي تقييم ممارسات الحوكمة المؤسسية والتوصيات.",
            "booking": {
                "id": 1,
                "reference": "BK-2026-000001",
                "date": "2026-09-21",
                "time": "10:00"
            },
            "consultant": {
                "id": 2,
                "name": "أ. أحمد العتيبي"
            },
            "client": {
                "id": 1,
                "name": "عميل تجريبي",
                "company_name": "شركة تجريبية"
            },
            "file": {
                "name": "governance-report.pdf",
                "size": 192,
                "size_human": "192 B",
                "mime_type": "application/pdf"
            },
            "download_url": "http://localhost:8000/api/v1/admin/reports/1/download",
            "client_notified_at": "2026-09-24T03:50:41+03:00",
            "first_downloaded_at": "2026-09-24T03:50:51+03:00",
            "created_at": "2026-09-24T03:50:41+03:00",
            "updated_at": "2026-09-24T03:50:51+03:00"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 1,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/clients/1/reports?page=1",
        "last": "http://localhost:8000/api/v1/admin/clients/1/reports?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/clients/{{client_id}}/subscriptions` — Client subscriptions (ADM-CL-08)

**Auth:** Admin token

The subscriptions of this client.

**Permission:** `view-clients`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 1,
            "package": {
                "id": 3,
                "slug": "gold",
                "name": "الباقة الذهبية",
                "name_ar": "الباقة الذهبية",
                "name_en": "Gold Package"
            },
            "status": "active",
            "starts_at": "2026-09-01T00:00:00+03:00",
            "ends_at": "2026-10-01T00:00:00+03:00",
            "consultations_limit": null,
            "consultations_used": 1,
            "consultations_remaining": null,
            "is_unlimited": true,
            "price_paid": 980000,
            "price_paid_formatted": "9,800.00 SAR"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 1,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/clients/1/subscriptions?page=1",
        "last": "http://localhost:8000/api/v1/admin/clients/1/subscriptions?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `DELETE /admin/clients/{{new_client_id}}` — Delete client (ADM-CL-05)

**Auth:** Admin token

Deletes the client registered in folder 08 (his bookings were cancelled in folders 13–14). A client with future bookings cannot be deleted.

**Permission:** `delete-clients`

**Errors:** 422 `CLIENT_HAS_FUTURE_BOOKINGS`

Example response:

```json
{
    "success": true,
    "message": "تم الحذف بنجاح.",
    "data": null
}
```

### 18 Admin › Payments

#### `GET /admin/payments` — List payments (PAY-01)

**Auth:** Admin token

All payments (a consultant only sees payments of his own bookings). `gateway_response` is never returned.

**Permission:** `view-payments`

| Query param | Notes |
|---|---|
| `status` *(optional)* | initiated | paid | failed | refunded |
| `client_id` *(optional)* | — |
| `date_from` *(optional)* | YYYY-MM-DD |
| `date_to` *(optional)* | YYYY-MM-DD |

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": [
        {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
            "amount": 190000,
            "amount_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "status": "paid",
            "gateway": "fake",
            "card_brand": "visa",
            "card_last_four": "4242",
            "failure_reason": null,
            "transaction_url": null,
            "paid_at": "2026-09-24T03:50:49+03:00",
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة"
            },
            "created_at": "2026-09-24T03:50:49+03:00"
        },
        "… (2 more)"
    ],
    "meta": {
        "current_page": 1,
        "per_page": 15,
        "total": 3,
        "last_page": 1
    },
    "links": {
        "first": "http://localhost:8000/api/v1/admin/payments?page=1",
        "last": "http://localhost:8000/api/v1/admin/payments?page=1",
        "prev": null,
        "next": null
    }
}
```

#### `GET /admin/payments/{{payment_id}}` — Show payment (PAY-02)

**Auth:** Admin token

One payment.

**Permission:** `view-payments`

**Errors:** 404 `NOT_FOUND`, 403 `FORBIDDEN`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "payment": {
            "id": 2,
            "booking_id": 4,
            "booking_reference": "BK-2026-000004",
            "amount": 190000,
            "amount_formatted": "1,900.00 SAR",
            "currency": "SAR",
            "status": "paid",
            "gateway": "fake",
            "card_brand": "visa",
            "card_last_four": "4242",
            "failure_reason": null,
            "transaction_url": null,
            "paid_at": "2026-09-24T03:50:49+03:00",
            "client": {
                "id": 2,
                "name": "عميل تجريبي",
                "company_name": "شركة الاختبار المحدودة"
            },
            "created_at": "2026-09-24T03:50:49+03:00"
        },
        "booking": {
            "id": 4,
            "reference": "BK-2026-000004",
            "status": "cancelled",
            "date": "2026-10-01",
            "time": "09:00",
            "consultant": {
                "id": 7,
                "name": "أ. نورة السبيعي"
            }
        }
    }
}
```

### 19 Admin › Dashboard

#### `GET /admin/dashboard/stats` — Dashboard stats (DSH-01)

**Auth:** Admin token

The home counters. A consultant gets the same keys scoped to him, without `consultants` and `revenue`.

**Permission:** `view-dashboard`

Example response:

```json
{
    "success": true,
    "message": "OK",
    "data": {
        "bookings": {
            "total": 5,
            "pending": 1,
            "completed": 2,
            "cancelled": 2,
            "today": 0
        },
        "reports": {
            "total": 1,
            "pending": 1
        },
        "clients": {
            "total": 1,
            "new_this_month": 1
        },
        "consultants": {
            "total": 6,
            "active": 6
        },
        "revenue": {
            "this_month": 1170000,
            "this_month_formatted": "11,700.00 SAR",
            "total": 1170000,
            "total_formatted": "11,700.00 SAR"
        },
        "upcoming_bookings": [
            {
                "id": 3,
                "reference": "BK-2026-000003",
                "status": "pending",
                "status_label": "قيد الانتظار",
                "report_status": "none",
                "payment_status": "not_required",
                "refund_status": "none",
                "date": "2026-09-27",
                "time": "10:00",
                "starts_at": "2026-09-27T10:00:00+03:00",
                "ends_at": "2026-09-27T10:30:00+03:00",
                "duration_minutes": 30,
                "amount": 0,
                "amount_formatted": "0.00 SAR",
                "currency": "SAR",
                "package": {
                    "id": 3,
                    "slug": "gold",
                    "name": "الباقة الذهبية",
                    "name_ar": "الباقة الذهبية",
                    "name_en": "Gold Package"
                },
                "consultant": {
                    "id": 2,
  …
```

### 20 Webhooks

#### `POST /webhooks/payments/moyasar` — Moyasar webhook (WHK-01)

**Auth:** — (public)

The gateway calls this on payment updates. Idempotent; always 200 for known events, 401 for a wrong secret. Skipped in the CI run (needs a real Moyasar signature).

**Errors:** 401 `UNAUTHORIZED`

Body:

```json
{
    "id": "PASTE-PAYMENT-ID",
    "status": "paid",
    "secret_token": "PASTE-WEBHOOK-SECRET"
}
```

### 99 Logout

#### `POST /admin/auth/logout` — Admin logout (ADM-AUTH-02)

**Auth:** Admin token

Revokes the current token.

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الخروج بنجاح.",
    "data": null
}
```

#### `POST /client/auth/logout` — Client logout (CLI-AUTH-03)

**Auth:** Client token

Revokes the current token.

Example response:

```json
{
    "success": true,
    "message": "تم تسجيل الخروج بنجاح.",
    "data": null
}
```

<!-- API-REFERENCE:END -->

---

## 20. Build phases and acceptance checklists

Work in this order. After every phase: `npm run build` with zero TypeScript errors, then tick the list.

### Phase 0 — Skeleton, theme, i18n, primitives
1. §2 setup commands; folder structure from §2.4 (empty pages are fine).
2. `globals.css` with the §3 variables mapped from the provided theme; `<Logo />`; font wired.
3. next-intl per §4 (cookie, `dir`, switcher); `messages/ar.json` + `en.json` with the `common`/`nav`/`auth` namespaces.
4. All §8 primitives with a hidden `/dev/ui` page that renders every variant (delete the page before delivery).
- [ ] `npm run build` passes · RTL/LTR flip works · every primitive renders with theme tokens only

### Phase 1 — API layer + client auth + public pages
1. §5 api.ts + errors.ts + query client; §6 stores + RequireClient/RequireAdmin.
2. Client auth pages: `/login`, `/register`, `/forgot-password`, `/reset-password`.
3. PUB-08 meta boot store; `/packages` (§10.1); `/consultants` + `/consultants/[id]` (§10.2).
- [ ] Register → login → `/dashboard` renders (placeholder) · wrong password shows the inline error · language switch re-fetches with translated messages · pricing page matches the seeded Iron/Silver/Gold data

### Phase 2 — The booking wizard
1. §11.1 store + layout + summary sidebar.
2. Steps 2–5 (account, consultant, date/time with PUB-05/06, location).
3. Quote wiring (§11.4); step 6 with CardForm incl. fake-driver dev tokens (§11.5).
4. Submit + every outcome row of §11.6; the callback page §11.7.
- [ ] Full journey locally: choose package → register → consultant → slot → location → `tok_fake_success` → success screen with reference · `tok_fake_3ds` → callback → verify → confirmed · `tok_fake_declined` → 402 shown · an active subscription skips payment · 409 re-pick works

### Phase 3 — Client dashboard
1. ClientLayout + `/dashboard` (§12.1).
2. `/bookings` + `/bookings/[id]` + cancel (§12.2/12.3).
3. `/reports` + `/reports/[id]` + blob download (§12.4, §14.2).
4. `/my-packages`, `/locations`, `/payment-methods`, `/profile` (§12.5–12.8).
- [ ] Every list has loading/empty/error states · cancel respects the 24 h rule error · download saves the real file name · unread badge clears after the first download

### Phase 4 — Admin shell + dashboard + profile
1. Admin auth pages; AdminLayout with the §9.2 permission-gated sidebar; `/admin/profile`.
2. `/admin` stats (§13.1) incl. the consultant variant (no `consultants`/`revenue`).
- [ ] Admin sees all cards; the consultant login sees only his numbers · sidebar hides items without permission

### Phase 5 — Roles + users
1. `/admin/roles` with the grouped permissions picker and users drawer (§13.2).
2. `/admin/users` with create/edit/roles/status/delete (§13.3).
- [ ] Create a role with 3 permissions → a new user with it logs in and sees only those pages · `admin` role is locked · `LAST_ADMIN`/`CANNOT_DELETE_SELF` toasts work

### Phase 6 — Consultants + availability
1. `/admin/consultants` list + create (FormData + photo + optional availability, §14.3) (§13.4).
2. Details tabs incl. `<AvailabilityEditor>`, time off, slot preview (§13.5).
3. `/admin/my-availability` for the consultant (§13.6).
- [ ] Sunday 10:00–12:00 saved → public slots show 10:00/10:30/11:00/11:30 · overlap → inline highlight · conflicting-bookings warning appears · a consultant edits his own week via My availability

### Phase 7 — Admin bookings
1. List + filters (§13.7); calendar view (BKG-06).
2. Details page with all actions: complete, cancel, regenerate meeting, mark refunded, upload report (§13.8/§13.9).
- [ ] Complete before start → `BOOKING_NOT_STARTED` toast · cancel of a paid booking → refund alert · mark-refunded warns about the subscription · meeting regenerate shows spinner + 502 retry · `can` + permissions gate every button

### Phase 8 — Reports, clients, packages, payments
1. `/admin/reports` + resend + delete (§13.9).
2. `/admin/clients` + details tabs (§13.10).
3. `/admin/packages` with the SAR→halalas price field and the features editor (§13.11).
4. `/admin/payments` + details (§13.12).
- [ ] Upload → replace → resend → delete cycle works · package price round-trips (1900 SAR ↔ 190000) · payments list never shows `gateway_response`

### Phase 9 — Polish and hardening
1. Every list: search + pagination + empty/error states verified.
2. Every destructive action uses ConfirmDialog; every mutation has a toast.
3. RTL audit: no `ml/mr/pl/pr/left/right` anywhere (`rg "m[lr]-|p[lr]-|text-(left|right)" src/` must be empty).
4. Accessibility pass: labels on inputs, focus states, `dir="ltr"` on phones/cards/times.
5. Remove the `/dev/ui` page; production `.env` documented in the README.
- [ ] `npm run build` clean · manual walkthrough of both dashboards in **both** languages

---

## 21. Definition of Done

- [ ] Every route in §9.1 exists, including the four URL-contract routes (`/reset-password`, `/admin/reset-password`, `/reports/[id]`, `/bookings/payment-callback`).
- [ ] Every API call goes through `lib/api.ts` (correct guard token, `Accept-Language`, envelope handling); no raw `fetch` outside `lib/`.
- [ ] Money is always displayed via `*_formatted`; the only conversion is the admin package form (SAR → halalas).
- [ ] Statuses/labels/permissions use the API's translated labels; message files hold UI chrome only; `ar.json` and `en.json` have identical keys.
- [ ] The wizard handles every §11.6 outcome, including 409 re-pick, 402 retry, 503 poll, and the subscription skip.
- [ ] Card numbers never touch our API (Moyasar.js tokenization; fake tokens in dev).
- [ ] Admin pages hide menu items/buttons by permission and by `can`; a consultant sees only his own data (the backend enforces it — the UI never relies on hiding alone).
- [ ] Uploads use FormData without a manual `Content-Type`; downloads use the blob helper with the `Content-Disposition` file name.
- [ ] `npm run build` passes with zero TypeScript errors; the app runs against the seeded local backend (`http://localhost:8000`) with the demo accounts in §1.3.




