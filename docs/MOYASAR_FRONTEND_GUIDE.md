# Moyasar Payment: Next.js Frontend Guide

How the client app (Next.js, App Router) pays for a booking through Moyasar.
Backend base URL and headers are in `FRONTEND_API_GUIDE.md` §1. Moyasar docs:
https://docs.moyasar.com

---

## 1. How the flow works

The frontend never charges the card. It only turns card data into a
single-use **token** straight from the browser to Moyasar. The backend then
charges that token with the secret key.

```
1. Browser  -> Moyasar  POST /v1/tokens (save_only=true, publishable key)   -> token_xxx
2. Browser  -> Backend  POST /client/bookings { ..., card_token: token_xxx }
3. Backend  -> Moyasar  POST /v1/payments (source=token, secret key)
4. Backend  -> Browser  { booking, payment: { id, requires_action, transaction_url } }
5. Browser  -> redirect to payment.transaction_url   (3-D Secure at the bank)
6. Bank     -> Browser  {CLIENT_FRONTEND_URL}/bookings/payment-callback?id=..&status=..&message=..
7. Browser  -> Backend  POST /client/payments/{payment.id}/verify
8. Show success/failure from the verify response (NOT from the URL params)
```

Rules that keep it secure:

- **Never** put `sk_test_...` / `sk_live_...` in the frontend, in
  `NEXT_PUBLIC_*` variables, in git, or in chat. It lives only in the backend
  `.env` (`MOYASAR_SECRET_KEY`).
- Card number/CVC go **only** to `https://api.moyasar.com/v1/tokens`. Never
  send them to our API, never log them, never store them in state longer than
  needed, never put them in the URL.
- The callback URL query (`status=paid`) can be forged by the user. Trust only
  the response of `POST /client/payments/{id}/verify`.
- Serve the site over HTTPS in staging/production.

---

## 2. Keys and environment

Frontend `.env.local` (publishable key only; it is safe in browser code):

```
NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1
NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY=pk_test_U3a3UJCyMdmJmD6xhzhhXRVLXxF9N1XzHivwKMM7
```

Backend `.env` (backend team, not the frontend):

```
PAYMENT_GATEWAY=moyasar
MOYASAR_PUBLISHABLE_KEY=pk_test_...
MOYASAR_SECRET_KEY=sk_test_...
MOYASAR_WEBHOOK_SECRET=<random string, also set in the Moyasar dashboard webhook>
CLIENT_FRONTEND_URL=http://localhost:3001
# callback = CLIENT_FRONTEND_URL + /bookings/payment-callback  (or set PAYMENT_CALLBACK_URL)
```

The callback page route must exist in Next.js at `/bookings/payment-callback`.
Webhook (`POST /webhooks/payments/moyasar`) is backend-only; it reconciles the
payment if the user closes the browser before the callback.

Moving to live: swap to `pk_live_` / `sk_live_`, use an HTTPS callback URL.

---

## 3. Test cards (test keys only)

Any card not in this list fails in test mode. Name: at least two words.
Expiry: any future date. CVC: any 3 digits.

| Card | Result |
|---|---|
| `4111111111111111` | paid |
| `4111114005765430` | paid (frictionless 3DS) |
| `4201320111111010` (mada) | paid |
| `4123120001090000` | failed: insufficient funds |
| `4123120001090109` | failed: declined |
| `4111115784228433` | failed: 3DS rejected by issuer |
| `4111116611600661` | failed: card not enrolled in 3DS |

Full list: https://docs.moyasar.com/guides/card-payments/test-cards

---

## 4. Backend endpoints used

All client endpoints need `Authorization: Bearer <client token>`.

| Step | Endpoint | Notes |
|---|---|---|
| Price | `POST /client/bookings/quote` | `requires_payment`, `amount`, `amount_formatted` |
| Create + pay | `POST /client/bookings` | body below |
| Verify | `POST /client/payments/{payment}/verify` | idempotent; call after the 3DS redirect |
| History | `GET /client/payments` | paginated |
| Saved cards | `GET/POST/DELETE /client/payment-methods`, `PATCH .../{id}/default` | `POST` body: `{ token, is_default? }` |

`POST /client/bookings` body:

```json
{
  "package_id": 1,
  "consultant_id": 2,
  "date": "2026-11-01",
  "time": "10:00",
  "client_location_id": 3,
  "card_token": "token_xxx",
  "payment_method_id": null,
  "client_notes": "optional"
}
```

Send **either** `card_token` (new card) **or** `payment_method_id` (saved
card). Send neither when `requires_payment` is `false` (covered by a
subscription).

Success (`201`) `data`:

```json
{
  "booking": { "id": 10, "status": "pending_payment", "...": "..." },
  "payment": {
    "id": 55,
    "status": "initiated",
    "requires_action": true,
    "transaction_url": "https://api.moyasar.com/..."
  }
}
```

- `payment` is `null` when a subscription covers the booking.
- `requires_action: true` -> redirect to `transaction_url` (3DS).
- `status: "paid"` -> no redirect needed; show success.
- `status: "failed"` -> show `booking`/payment error; let the user retry with
  a new token.
- `503` with `error_code: PAYMENT_PENDING_CONFIRMATION` -> the charge outcome
  is unknown. `data.payment.id` is still returned. Do **not** charge again;
  poll `POST /client/payments/{id}/verify` every few seconds (max about 1 min),
  then tell the user to check "My bookings".

Verify response `data`:

```json
{ "booking": { "...": "..." }, "payment": { "id": 55, "status": "paid", "failure_reason": null, "...": "..." } }
```

`payment.status`: `initiated` (still pending), `paid`, `failed`, `refunded`.
A `pending_payment` booking expires automatically after about 15 minutes if
unpaid.

---

## 5. Next.js implementation

### 5.1 `lib/moyasar.ts` (tokenize in the browser)

```ts
export type CardInput = {
  name: string;   // at least two words
  number: string; // digits only
  month: string;  // "01".."12"
  year: string;   // "2030" or "30"
  cvc: string;
};

const TOKENS_URL = 'https://api.moyasar.com/v1/tokens';

export async function createCardToken(card: CardInput): Promise<string> {
  const res = await fetch(TOKENS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      publishable_api_key: process.env.NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY,
      save_only: true,
      name: card.name.trim(),
      number: card.number.replace(/\s+/g, ''),
      month: Number(card.month),
      year: Number(card.year.length === 4 ? card.year.slice(2) : card.year),
      cvc: card.cvc,
    }),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.id) {
    throw new Error(data.message ?? 'Invalid card details');
  }
  return data.id as string; // token_xxx (single use)
}
```

A token is single use. If the booking call fails, create a new token before
retrying.

### 5.2 `lib/api.ts`

```ts
const API = process.env.NEXT_PUBLIC_API_URL!;

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const token = localStorage.getItem('client_token');
  const res = await fetch(`${API}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'Accept-Language': 'ar',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...init.headers,
    },
  });
  const body = await res.json();
  if (!res.ok && res.status !== 503) throw Object.assign(new Error(body.message), { body, status: res.status });
  return { ...body, httpStatus: res.status } as T;
}
```

### 5.3 `components/CardForm.tsx` (client component)

```tsx
'use client';

import { useState } from 'react';
import { createCardToken } from '@/lib/moyasar';
import { api } from '@/lib/api';

export function PayForm({ booking }: { booking: Record<string, unknown> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (busy) return; // prevent double submit
    setBusy(true);
    setError(null);

    const f = new FormData(e.currentTarget);
    try {
      const cardToken = await createCardToken({
        name: String(f.get('name')),
        number: String(f.get('number')),
        month: String(f.get('month')),
        year: String(f.get('year')),
        cvc: String(f.get('cvc')),
      });
      e.currentTarget.reset(); // drop card data from the DOM

      const res: any = await api('/client/bookings', {
        method: 'POST',
        body: JSON.stringify({ ...booking, card_token: cardToken }),
      });

      const payment = res.data?.payment;
      if (res.httpStatus === 503 && payment) {
        sessionStorage.setItem('pending_payment_id', String(payment.id));
        window.location.href = '/bookings/payment-callback?pending=1';
        return;
      }
      if (!payment || payment.status === 'paid') {
        window.location.href = `/bookings/${res.data.booking.id}`;
        return;
      }
      if (payment.status === 'failed') {
        setError('Payment failed. Please try another card.');
        return;
      }
      if (payment.requires_action && payment.transaction_url) {
        sessionStorage.setItem('pending_payment_id', String(payment.id));
        window.location.href = payment.transaction_url; // 3-D Secure
        return;
      }
    } catch (err: any) {
      setError(err.message ?? 'Something went wrong');
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={onSubmit} autoComplete="on">
      <input name="name" autoComplete="cc-name" placeholder="Name on card" required />
      <input name="number" inputMode="numeric" autoComplete="cc-number" placeholder="Card number" required />
      <input name="month" inputMode="numeric" autoComplete="cc-exp-month" placeholder="MM" maxLength={2} required />
      <input name="year" inputMode="numeric" autoComplete="cc-exp-year" placeholder="YY" maxLength={4} required />
      <input name="cvc" inputMode="numeric" autoComplete="cc-csc" placeholder="CVC" maxLength={4} required />
      {error && <p role="alert">{error}</p>}
      <button type="submit" disabled={busy}>{busy ? 'Processing...' : 'Pay'}</button>
    </form>
  );
}
```

Do not add `console.log` of the form values or the request body.

### 5.4 `app/bookings/payment-callback/page.tsx` (3DS return page)

Moyasar redirects here with `?id=&status=&message=`. The `id` is Moyasar's
payment id; our verify endpoint needs **our** payment id, which we saved in
`sessionStorage` before the redirect.

```tsx
'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api';

type State = 'checking' | 'paid' | 'failed' | 'pending' | 'error';

export default function PaymentCallback() {
  const router = useRouter();
  const [state, setState] = useState<State>('checking');
  const [reason, setReason] = useState<string | null>(null);

  useEffect(() => {
    const paymentId = sessionStorage.getItem('pending_payment_id');
    if (!paymentId) {
      setState('error');
      return;
    }

    let tries = 0;
    let timer: ReturnType<typeof setTimeout>;

    const check = async () => {
      try {
        const res: any = await api(`/client/payments/${paymentId}/verify`, { method: 'POST' });
        const { payment, booking } = res.data;

        if (payment.status === 'paid') {
          sessionStorage.removeItem('pending_payment_id');
          setState('paid');
          setTimeout(() => router.replace(`/bookings/${booking.id}`), 1500);
        } else if (payment.status === 'failed') {
          sessionStorage.removeItem('pending_payment_id');
          setReason(payment.failure_reason);
          setState('failed');
        } else if (++tries < 10) {
          timer = setTimeout(check, 3000); // still initiated
        } else {
          setState('pending');
        }
      } catch {
        setState('error');
      }
    };

    check();
    return () => clearTimeout(timer);
  }, [router]);

  if (state === 'checking') return <p>Confirming your payment...</p>;
  if (state === 'paid') return <p>Payment successful. Redirecting...</p>;
  if (state === 'failed') return <p>Payment failed{reason ? `: ${reason}` : ''}. Please try again.</p>;
  if (state === 'pending') return <p>We are still confirming your payment. Check "My bookings" shortly.</p>;
  return <p>We could not confirm the payment. Check "My bookings".</p>;
}
```

The user must be logged in on this page (Bearer token in `localStorage`). If
the app uses another token store, adapt `lib/api.ts`.

---

## 6. UX checklist

- Disable the Pay button while processing; one click = one token = one charge.
- On failed payment, show `payment.failure_reason` (already translated by the
  gateway message) and let the user retry with a **new** token.
- Do not retry `POST /client/bookings` after a timeout or `503`. Verify instead
  (the backend also has idempotency protection).
- Show the amount from the quote (`amount_formatted`), never calculate it in
  the frontend.
- Saved cards: see section 8. Never send `save_card: true` together with a
  `save_only` token on `POST /client/bookings`.

---

## 8. Saved cards

A `save_only` token (section 5.1) is **single use**: Moyasar consumes it on the
first charge, so it can never be a saved card. Saving a card is a separate
flow that creates a **reusable** token. Moyasar charges 1 SAR for 3DS
verification and voids it automatically.

```
Add card page
1. Browser -> Moyasar  POST /v1/tokens  (NO save_only, callback_url = {site}/payment-methods/callback)
                       -> { id: token_xxx, status: "initiated", verification_url }
2. Browser: sessionStorage.setItem('pending_card_token', token_xxx); redirect to verification_url (3DS)
3. Bank -> Browser     {site}/payment-methods/callback
4. Browser -> Backend  POST /client/payment-methods { token: token_xxx, is_default?: true }
5. Backend fetches the token with the secret key. If it is not `active`, it answers 422 with
   errors.token = [message] and nothing is saved.
```

Pay with a saved card: `POST /client/bookings` with `payment_method_id` (no
`card_token`). The backend charges the saved token; 3DS still runs for each
payment, so handle `requires_action` / `transaction_url` exactly as in section 5.

`lib/moyasar.ts` addition:

```ts
export async function createReusableToken(card: CardInput): Promise<{ id: string; verificationUrl: string }> {
  const res = await fetch(TOKENS_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      publishable_api_key: process.env.NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY,
      name: card.name.trim(),
      number: card.number.replace(/\s+/g, ''),
      month: Number(card.month),
      year: Number(card.year.length === 4 ? card.year.slice(2) : card.year),
      cvc: card.cvc,
      callback_url: `${window.location.origin}/payment-methods/callback`,
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.id || !data.verification_url) {
    throw new Error(data.message ?? 'Invalid card details');
  }
  return { id: data.id, verificationUrl: data.verification_url };
}
```

Add-card submit handler: call `createReusableToken`, save `id` in
`sessionStorage` under `pending_card_token`, then
`window.location.href = verificationUrl`.

`app/payment-methods/callback/page.tsx`:

```tsx
'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api';

export default function CardCallback() {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const token = sessionStorage.getItem('pending_card_token');
    if (!token) {
      setError('No card to save.');
      return;
    }
    api('/client/payment-methods', { method: 'POST', body: JSON.stringify({ token }) })
      .then(() => {
        sessionStorage.removeItem('pending_card_token');
        router.replace('/payment-methods');
      })
      .catch((e: any) => setError(e.body?.errors?.token?.[0] ?? e.message ?? 'Card verification failed.'));
  }, [router]);

  return error ? <p role="alert">{error}</p> : <p>Saving your card...</p>;
}
```

Other saved-card endpoints: `GET /client/payment-methods` (list, default
first), `PATCH /client/payment-methods/{id}/default`,
`DELETE /client/payment-methods/{id}`. Show `brand`, `last_four`, expiry only;
the gateway token is never returned.

Test it with `4111111111111111` (verification succeeds) and
`4111115784228433` (3DS rejected: step 4 returns 422 and no card is saved).

---

## 9. Test checklist

1. Success: `4111111111111111` -> redirect to 3DS -> complete -> callback ->
   verify returns `paid` -> booking becomes `pending`/confirmed.
2. Declined: `4123120001090109` -> `failed`, retry works with a new token.
3. 3DS rejected: `4111115784228433` -> callback -> verify returns `failed`.
4. Close the tab during 3DS, then open "My bookings": the webhook should have
   updated the booking (needs a public URL for the webhook, e.g. ngrok, and
   `MOYASAR_WEBHOOK_SECRET` set in Moyasar's dashboard).
5. Forged callback: open `/bookings/payment-callback?status=paid` manually;
   the page must still show the result of `verify`, not the URL.
