# Moyasar Payment: Mobile App Guide (Flutter / React Native)

How the client mobile app pays for a booking through Moyasar. The flow is the
same as the web (`MOYASAR_FRONTEND_GUIDE.md`); only the 3-D Secure (3DS)
redirect is handled with an in-app WebView instead of a browser redirect.
Base URL, headers and response envelope: `FRONTEND_API_GUIDE.md` §1.

The app talks to **our backend** for everything except one call: turning the
card into a token, which goes straight to Moyasar. Do not use Moyasar's mobile
SDK "create payment" flow: it charges from the device, but our backend must
own the charge.

---

## 1. Flow

```
1. App  -> Moyasar   POST https://api.moyasar.com/v1/tokens   (save_only=true, publishable key)  -> token_xxx
2. App  -> Backend   POST /client/bookings { ..., card_token: token_xxx }
3. Backend -> App    { booking, payment: { id, status, requires_action, transaction_url } }
4. App: open payment.transaction_url in a WebView (3DS)
5. WebView navigates to the callback URL  -> app intercepts it and closes the WebView
6. App  -> Backend   POST /client/payments/{payment.id}/verify
7. Show the result from the verify response (never from the URL)
```

The callback URL is configured on the backend (`PAYMENT_CALLBACK_URL`, for
example `https://www.hawkama.sa/bookings/payment-callback`). The app does not
need to own that URL: the WebView just watches for navigation to it and
stops before loading it.

---

## 2. Security rules

- Only the **publishable key** (`pk_test_...` / `pk_live_...`) goes in the app.
  The secret key (`sk_...`) must never be in the app, in a config file in the
  repo, or in a build.
- Card number/CVC go **only** to `https://api.moyasar.com/v1/tokens`. Never to
  our API, never to logs, analytics or crash reports, never to local storage.
- Clear the card fields right after tokenizing. Disable screenshots/app
  switcher preview on the card screen if possible.
- Trust only the response of `POST /client/payments/{id}/verify`, not the
  `status` query parameter on the callback URL (it can be forged).
- Use HTTPS for the API (`https://api.gcmc.sa/api/v1`); no cleartext traffic.
- Store the user's Bearer token in secure storage (Keychain / Keystore:
  `flutter_secure_storage`, `react-native-keychain`), not plain preferences.
- A token is single use. After any failed booking call, create a new token.

Config:

```
API_URL=https://api.gcmc.sa/api/v1
MOYASAR_PUBLISHABLE_KEY=pk_test_U3a3UJCyMdmJmD6xhzhhXRVLXxF9N1XzHivwKMM7   # use pk_live_ in production builds
```

---

## 3. Test cards (test keys only)

Name: two words or more. Expiry: any future date. CVC: any 3 digits.

| Card | Result |
|---|---|
| `4111111111111111` | paid |
| `4111114005765430` | paid (frictionless 3DS) |
| `4201320111111010` (mada) | paid |
| `4123120001090000` | failed: insufficient funds |
| `4123120001090109` | failed: declined |
| `4111115784228433` | failed: 3DS rejected |
| `4111116611600661` | failed: not enrolled in 3DS |

More: https://docs.moyasar.com/guides/card-payments/test-cards

---

## 4. Backend endpoints

All with `Authorization: Bearer <client token>`.

| Step | Endpoint |
|---|---|
| Price | `POST /client/bookings/quote` |
| Create + pay | `POST /client/bookings` |
| Verify | `POST /client/payments/{payment}/verify` (idempotent) |
| History | `GET /client/payments` |
| Saved cards | `GET/POST /client/payment-methods`, `PATCH .../{id}/default`, `DELETE .../{id}` |

`POST /client/bookings` body:

```json
{
  "package_id": 1, "consultant_id": 2, "date": "2026-11-01", "time": "10:00",
  "client_location_id": 3,
  "card_token": "token_xxx",
  "payment_method_id": null,
  "client_notes": "optional"
}
```

Send **either** `card_token` (new card) **or** `payment_method_id` (saved
card); neither when `requires_payment` is false (subscription covers it).

Response `data.payment`:

- `null`: nothing to pay.
- `status: "paid"`: done, show success.
- `status: "failed"`: show error, let the user retry with a new token.
- `requires_action: true` + `transaction_url`: open the WebView (3DS).
- HTTP `503` with `error_code: PAYMENT_PENDING_CONFIRMATION`: outcome unknown.
  `data.payment.id` is still returned. **Do not charge again.** Poll
  `POST /client/payments/{id}/verify` every 3 seconds for about 30 seconds,
  then tell the user to check "My bookings".

Verify response: `data.payment.status` is `initiated` | `paid` | `failed` |
`refunded`, plus `data.booking`. An unpaid `pending_payment` booking expires
after about 15 minutes.

---

## 5. Flutter

Packages: `http`, `webview_flutter`, `flutter_secure_storage`.

### 5.1 Tokenize

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class CardInput {
  CardInput(this.name, this.number, this.month, this.year, this.cvc);
  final String name, number, month, year, cvc; // month "01".."12", year "2030" or "30"
}

Future<String> createCardToken(CardInput c, String publishableKey) async {
  final res = await http.post(
    Uri.parse('https://api.moyasar.com/v1/tokens'),
    headers: {'Content-Type': 'application/json'},
    body: jsonEncode({
      'publishable_api_key': publishableKey,
      'save_only': true,
      'name': c.name.trim(),
      'number': c.number.replaceAll(' ', ''),
      'month': int.parse(c.month),
      'year': int.parse(c.year.length == 4 ? c.year.substring(2) : c.year),
      'cvc': c.cvc,
    }),
  );
  final data = jsonDecode(res.body) as Map<String, dynamic>;
  if (res.statusCode >= 400 || data['id'] == null) {
    throw Exception(data['message'] ?? 'Invalid card details');
  }
  return data['id'] as String;
}
```

### 5.2 3DS WebView

```dart
import 'package:flutter/material.dart';
import 'package:webview_flutter/webview_flutter.dart';

/// Returns when the bank redirects to [callbackPrefix]. Always verify with the backend afterwards.
class ThreeDsPage extends StatefulWidget {
  const ThreeDsPage({super.key, required this.url, required this.callbackPrefix});
  final String url;
  final String callbackPrefix; // e.g. https://www.hawkama.sa/bookings/payment-callback

  @override
  State<ThreeDsPage> createState() => _ThreeDsPageState();
}

class _ThreeDsPageState extends State<ThreeDsPage> {
  late final WebViewController _c = WebViewController()
    ..setJavaScriptMode(JavaScriptMode.unrestricted)
    ..setNavigationDelegate(NavigationDelegate(
      onNavigationRequest: (req) {
        if (req.url.startsWith(widget.callbackPrefix)) {
          Navigator.of(context).pop(true); // finished, now verify
          return NavigationDecision.prevent;
        }
        return NavigationDecision.navigate;
      },
    ))
    ..loadRequest(Uri.parse(widget.url));

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Secure payment')),
        body: WebViewWidget(controller: _c),
      );
}
```

Closing the page with the back button also pops `null`; treat that as
"unknown" and run verify too (the user may have completed 3DS).

### 5.3 Pay

```dart
Future<void> payBooking(Map<String, dynamic> booking, CardInput card) async {
  final token = await createCardToken(card, const String.fromEnvironment('MOYASAR_PK'));

  final res = await api.post('/client/bookings', {...booking, 'card_token': token});
  final payment = res.data['data']?['payment'];

  if (res.statusCode == 503 && payment != null) {
    return pollVerify(payment['id']);
  }
  if (payment == null || payment['status'] == 'paid') return showSuccess();
  if (payment['status'] == 'failed') return showError('Payment failed, try another card');

  if (payment['requires_action'] == true) {
    await Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => ThreeDsPage(
        url: payment['transaction_url'],
        callbackPrefix: 'https://www.hawkama.sa/bookings/payment-callback',
      ),
    ));
    await pollVerify(payment['id']);
  }
}

Future<void> pollVerify(int paymentId) async {
  for (var i = 0; i < 10; i++) {
    final res = await api.post('/client/payments/$paymentId/verify', {});
    final status = res.data['data']['payment']['status'];
    if (status == 'paid') return showSuccess();
    if (status == 'failed') return showError(res.data['data']['payment']['failure_reason']);
    await Future.delayed(const Duration(seconds: 3));
  }
  showInfo('We are still confirming your payment. Check My bookings.');
}
```

`api` is your own Dio/http wrapper that adds `Authorization`, `Accept:
application/json`, `Accept-Language`, and does not throw on 503.

---

## 6. React Native

Packages: `react-native-webview`, `react-native-keychain`.

```ts
export async function createCardToken(c: {
  name: string; number: string; month: string; year: string; cvc: string;
}): Promise<string> {
  const res = await fetch('https://api.moyasar.com/v1/tokens', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      publishable_api_key: MOYASAR_PUBLISHABLE_KEY,
      save_only: true,
      name: c.name.trim(),
      number: c.number.replace(/\s+/g, ''),
      month: Number(c.month),
      year: Number(c.year.length === 4 ? c.year.slice(2) : c.year),
      cvc: c.cvc,
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.id) throw new Error(data.message ?? 'Invalid card details');
  return data.id;
}
```

```tsx
import { WebView } from 'react-native-webview';

const CALLBACK = 'https://www.hawkama.sa/bookings/payment-callback';

export function ThreeDsScreen({ url, onDone }: { url: string; onDone: () => void }) {
  return (
    <WebView
      source={{ uri: url }}
      onShouldStartLoadWithRequest={(req) => {
        if (req.url.startsWith(CALLBACK)) {
          onDone(); // close the screen, then call verify
          return false;
        }
        return true;
      }}
    />
  );
}
```

Pay logic is identical to the Flutter `payBooking` / `pollVerify` above.

---

## 7. Saved cards

A `save_only` token is single use, so it cannot be a saved card. To save a
card, create a **reusable** token (no `save_only`). Moyasar verifies it with a
1 SAR authorization that is voided automatically.

```
1. App -> Moyasar   POST /v1/tokens  { publishable_api_key, name, number, month, year, cvc,
                                       callback_url: "https://www.hawkama.sa/payment-methods/callback" }
                    -> { id: token_xxx, status: "initiated", verification_url }
2. App: keep token_xxx in memory; open verification_url in the WebView (3DS)
3. WebView navigates to the callback URL -> intercept and close
4. App -> Backend   POST /client/payment-methods { token: token_xxx, is_default?: true }
5. Backend checks the token is `active`; otherwise 422 with errors.token and nothing is saved
```

Pay with a saved card: `POST /client/bookings` with `payment_method_id`. 3DS
still runs on each payment (handle `requires_action` as in section 5/6).

Never send `save_card: true` together with a `save_only` token.

Show only `brand`, `last_four`, `exp_month/exp_year`, `display`,
`is_default` from `GET /client/payment-methods`. The token is never returned.

---

## 8. UX checklist

- Disable the Pay button while processing: one tap = one token = one charge.
- Show the amount from the quote (`amount_formatted`); never compute it in the app.
- Handle the Android/iOS back gesture during 3DS: then run verify.
- Do not retry `POST /client/bookings` after a timeout or `503`; verify instead.
- Mask card input (`4111 1111 1111 1111`), numeric keyboard, set
  `autofillHints` (`creditCardNumber`, etc.) and `autocorrect: false`.
- Arabic/English: send `Accept-Language`; failure messages come translated.

## 9. Test checklist

1. `4111111111111111`: 3DS page opens, complete it, WebView closes, verify returns `paid`.
2. `4123120001090109`: payment `failed`, retry with a new token works.
3. `4111115784228433`: 3DS rejected, verify returns `failed`.
4. Kill the app during 3DS, reopen "My bookings": the booking is updated by the
   webhook or by the next verify.
5. Save card with `4111111111111111`: appears in the list. With
   `4111115784228433`: 422, nothing saved.
6. Pay with the saved card: 3DS still opens, then `paid`.
