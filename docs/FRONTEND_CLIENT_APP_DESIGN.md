# Client Mobile App — Design Specification

This document describes the **approved visual design** of the client mobile
app (guard `client`). It is the contract between the UI mockup and any
frontend implementation (React Native / Flutter / PWA). Build the screens to
look like this; wire the data using:

- `docs/FRONTEND_API_GUIDE.md` — endpoints, envelope, errors, booking wizard
- `docs/FRONTEND_NOTIFICATIONS.md` — notification list, real-time bell
- `postman/GCMC-API.postman_collection.json` — real response examples, to be updated with any new samples added here

Where the mockup and the API differ (e.g. the booking wizard), **the API
guide wins for data and flow; this document wins for look & feel.** All such
differences are listed in §7.

---

## 1. Brand & design tokens

The app is **Arabic-first, RTL everywhere** (`dir="rtl"`, `lang="ar"`).
All UI copy is Arabic. Latinate values (emails, phone numbers, reference
codes) are rendered LTR (`dir="ltr"`) inside the RTL flow.

### Colors

| Token | Hex | Usage |
|---|---|---|
| `--green-deep` | `#0e3b2e` | Primary surfaces, primary buttons, active states, avatars |
| `--green-mid` | `#16543f` | Secondary text, labels, links |
| `--green-darker` | `#0a2a21` | Splash gradient end, dark backdrops |
| `--gold` | `#c9a24b` | Accent: FAB, selected states, CTA buttons, active dots |
| `--gold-light` | `#e6c87a` | Icon/text on deep green |
| `--gold-soft` | `#f7ecd2` | Gold-tinted backgrounds (icon tiles, ref chips) |
| `--cream` | `#f6f2ea` | App background, inactive chips |
| `--white` | `#ffffff` | Cards, inputs |
| `--stone` | `#7a7468` | Muted/secondary text |
| `--line` | `#e6e0d4` | Borders, dividers, dashed separators |
| `--danger` | `#b3402f` | Cancel/destructive actions (`#f8e9e6` tint background) |

Semantic tints: success `#e7f2ec` bg / `#1c6b4a` fg; gold chip `#f7ecd2` bg /
`#8a6a1f` fg; danger `#f8e9e6` bg / `--danger` fg.

### Typography

| Role | Font | Notes |
|---|---|---|
| Headings (`h1–h3`) | **Amiri** (400/700) | Serif, traditional character |
| Body / UI | **IBM Plex Sans Arabic** (400–800) | Everything else |

Scale is mobile-compact: body 11px, section titles 12px, card titles
11–11.5px, muted/meta 9–11px, page titles 13–17px. Line height ~1.7 for
muted paragraphs.

### Frame & spacing

- Design for a **430px-wide phone frame**; the app never grows wider.
- Screen padding: 14px top, 16px sides, 80px bottom (clears the tab bar).
- Card radius: 12–14px; buttons 8px; pills/avatars circular.
- Border: 1px `--line` on all cards; 1.5px on focusable/selectable rows.
- Arab-Indic digits (`٠١٢٣٤٥٦٧٨٩`) for all Arabic UI numbers — dates, times,
  prices, refs shown to users. Keep API payloads in ASCII.

---

## 2. App shell

### 2.1 Screen structure

```
┌ statusbar (mock only — native OS bar in the real app)
├ .screen  (scrollable content, hidden scrollbar)
└ .tabbar  (bottom nav, fixed; hidden on flow screens)
```

### 2.2 Bottom tab bar

5 items, height 56px, white with top border:

| Tab | Icon | Screen |
|---|---|---|
| الرئيسية | home | Home |
| مواعيدي | calendar | Bookings |
| **FAB** (center, `+`) | gold circle Ø50 raised −22px, cream ring, shadow | opens booking wizard |
| التقارير | file | Reports |
| حسابي | user | Profile |

Active tab: deep green + bold; inactive: stone. The tap target for the FAB
always starts a **new** booking (resets wizard state).

### 2.3 Tab bar visibility

Hidden on: splash, onboarding, login/register, and every step of the booking
flow (service → consultant → schedule → confirm → success). Visible on the
five tab destinations plus packages, notifications, profile, contact.

### 2.4 Back header (`bhd`)

Used on all flow/pushed screens: square back button (34px, chevron pointing
**right** in RTL), centered bold title, balancing spacer. The section header
variant (`app-header`) shows the logo + "المتخصصون في الحوكمة والامتثال" +
page subtitle, used on tab-level pages without a back button.

---

## 3. Component inventory

| Component | Spec |
|---|---|
| **`.btn`** | Full-width, deep green bg, white text, 12px/700, 11px pad, 8px radius. Variants: `.gold` (gold bg, dark text, primary CTA), `.outline` (green border, transparent), `.danger-o` (danger border+text), `.sm`, `.lg`. |
| **`.card`** | White, `--line` border, 12px radius, 14px pad. |
| **`.badge`** | Pill: 9px/600, cream bg default. Variants: `.gold` (pending/gold), `.done` (green success), `.danger` (cancelled). |
| **`.pick`** (selectable row) | 1.5px border; selected = gold border + `#fffdf6` bg; trailing `.radio` Ø18 with gold dot when on. |
| **`.mini`** (list row) | Icon tile (34px deep green, gold-light icon) + title/subtitle + trailing price or action. |
| **`.avatar`** | Ø40, deep green bg, gold-light initial. |
| **`.bell`** | 38px rounded-square button; gold unread dot (Ø7, white ring) top-end when `unread_count > 0`. |
| **`.steps`** (wizard progress) | 4 numbered circles, gold-on-green when done/active, connecting line fills green up to current step. Labels: الخدمة، المستشار، الموعد، التأكيد. |
| **`.cal`** (calendar) | Month grid; available days = cream bg clickable; selected = gold bg white text; unavailable = greyed `#bbb2a2`, no pointer. Weekday headers: أحد … سبت. |
| **`.sl`** (time slot) | Grid 3-col; default white/bordered; selected = deep green; taken = grey, strikethrough, disabled. |
| **`.sum`** (summary) | Key/value rows, dashed divider; last row `.tot` = larger bold total. |
| **`.pill`** (filter tabs) | Equal-width pills; active = deep green white text. |
| **`.bk`** (booking card) | Title + status badge row, then meta rows (consultant, date) with icons, then 2 action buttons. |
| **`.ntf`** (notification) | Unread = 3px gold inline-start border + gold dot before title; title row carries right-aligned relative time; body is stone 9.5px. |
| **`.pkg`** (package) | Current = `.hot` gold border + soft gradient + "الحالية" badge; features as `✓` list (gold check). |
| **`.field`** | Label 10px/600 green-mid above input; inputs white, `--line` border, 8px radius. |
| **`.tabs`** (segmented) | Cream track, white active thumb with soft shadow (login/register switch). |
| **Icons** | 24×24 outline stroke icons (stroke 1.8, round caps), Lucide-compatible; star icon is filled. Icon tiles: deep green square + gold-light icon, or gold-soft square + green-mid icon. |
| **Logo** | `/logo_icon.png` — used on splash, login, section header. |

Hero/banner gradients: hero = 135° `--green-deep` → `--green-mid`;
upsell banner = 120° `#f0e3c2` → `--gold-soft` with `#e7d29a` border;
splash = 160° `--green-deep` → `--green-darker`.

---

## 4. Screens

### 4.0 Splash

Full-bleed green gradient, glassy rounded-square logo tile, brand name,
tagline ("احجز استشارتك، تابع تقاريرك، وادِر اشتراكك من مكان واحد"),
two CTAs: **ابدأ الآن** (gold) → onboarding; **متابعة كضيف** (ghost) → home
as guest. Behavior: if a valid client token exists, splash routes straight
to home; onboarding is shown once (persist a flag).

### 4.1 Onboarding

3 slides, one at a time: circular illustration tile (cal / bell / shield
icon), title, ≤2-line description, dot indicator (active dot = elongated
gold pill), **تخطّي** top-start → login, **التالي** button (last slide:
**ابدأ الآن**) → login.

Copy: «احجز موعدك بسهولة»، «تذكيرات فورية»، «مستشارون معتمدون» — see mockup
for full body text.

### 4.2 Login / register

Logo, «بوابة العميل», subtitle, white card with segmented tabs.

- **Login tab**: البريد الإلكتروني, كلمة المرور, «نسيت كلمة المرور؟» link,
  submit «تسجيل الدخول» → `POST /client/auth/login`.
- **Register tab**: الاسم الكامل, رقم الجوال, البريد الإلكتروني, كلمة
  المرور, submit «إنشاء الحساب» → `POST /client/auth/register`
  (API uses `name, email, phone, password, password_confirmation` —
  add the confirmation field).

Validation errors (422) render under each field from `errors[field][0]`;
`message` as fallback toast. On success store the token and route home.

### 4.3 Home

- **Header row**: avatar initial, greeting («مساء الخير 👋» — time-of-day
  aware) + client name, notification bell with unread dot.
  Data: `GET /client/auth/me`, unread dot from
  `GET /client/notifications/unread-count` (and live updates per
  FRONTEND_NOTIFICATIONS.md).
- **Search bar** (decorative in mockup): navigates to the booking wizard.
- **Hero «موعدك القادم»**: next upcoming booking — service/specialization,
  date/time with clock icon, consultant name + «عن بُعد»/«حضوري», gold
  «تفاصيل الموعد» → booking details. Empty state: swap hero to a «احجز
  أول استشارة» CTA. Data: `GET /client/dashboard`.
- **Quick actions 4-grid**: حجز جديد (wizard), مواعيدي, تقاريري, باقاتي.
- **Stats 3-grid**: reports count, upcoming bookings, current package name
  (from dashboard payload).
- **«خدماتنا»**: 2 preview rows (`.mini`) of consultation types; «عرض الكل»
  → full list; tapping a row deep-links into the wizard with that
  service pre-selected.
- **Upsell banner**: «ارتقِ للباقة الذهبية» gold gradient card → packages.

### 4.4 Booking wizard (4 steps, back-header + step indicator)

State kept client-side: `service`, `consultant`, `date`, `time`.

1. **اختر الخدمة** — pick-row list of offerings (icon tile, name,
   «duration · price» subtitle, radio). Mock data: الحوكمة والامتثال /
   الاستشارات الإدارية / إدارة المخاطر والامتثال / المراجعة الداخلية.
   «متابعة» disabled until picked. → see §7 for API mapping.
2. **اختر المستشار** — pick-rows with avatar initial, name, title,
   filled star + rating + job count («٣٢٠ استشارة»).
   Data: `GET /public/consultants?specialization=`. Optionally an
   «بدون تفضيل» row (consultant_id omitted).
3. **اختر الموعد** — month calendar, only available days clickable
   (`GET /public/consultants/{id}/available-dates?month=YYYY-MM`),
   then 3-col slot grid for the picked day
   (`GET /public/consultants/{id}/slots?date=`); taken slots show
   strikethrough/disabled. «متابعة» needs both date and time.
4. **تأكيد الحجز** — summary card (الخدمة/المستشار/التاريخ/الوقت/المدة +
   الإجمالي row), benefits card («تأكيد خلال يوم عمل… الجلسة حضورياً أو
   عن بُعد… إلغاء مجاني قبل ٢٤ ساعة»), gold «تأكيد الحجز» →
   `POST /client/bookings`.

### 4.5 Success

Deep-green Ø74 check circle with halo ring, «تم الحجز بنجاح!», subtitle,
gold ref chip (`GC-1027`, `dir="ltr"` — use the real `reference` from the
API), summary card (service / consultant / datetime), buttons
«عرض مواعيدي» → bookings tab, «العودة للرئيسية».

### 4.6 Bookings

Section-header title «مواعيدي», two pills — **قادمة (n)** / **سابقة (n)**.

- Upcoming card: name + status badge (`مجدول` = done/green,
  `بانتظار التأكيد` = gold), consultant row, datetime row, actions
  «تفاصيل» + «إلغاء الموعد» (danger outline → confirm dialog →
  `POST /client/bookings/{id}/cancel`).
- Past card: status `مكتمل` (green) / `ملغي` (danger), actions
  «عرض التقرير» (→ reports, when a report exists) + «إعادة الحجز»
  (→ wizard pre-fill consultant).

Data: `GET /client/bookings` with status/date filters; empty states:
«لا توجد مواعيد قادمة — احجز الآن».

### 4.7 Reports

Rows: file icon tile, title + meta (date · PDF · size «١٫٢ م.ب»).
Ready → round green download button
(`GET /client/reports/{id}/download`); not ready → gold badge
«قيد الإعداد». Data: `GET /client/reports`.

### 4.8 Packages

Stack of `.pkg` cards: name, price + «ر.س/شهر», `✓` feature list, CTA.
Current package: gold border, soft gradient, «الحالية» badge, disabled
«باقتك الحالية» button; others: «اختر الباقة» outline / «ترقية الباقة»
gold. Mock tiers: البرونزية ١,٩٠٠ / الفضية ٤,٥٠٠ / الذهبية ٩,٨٠٠ — real
data from `GET /public/packages` + `GET /client/subscriptions/active`.
Upgrade CTA → booking/subscribe payment flow per the API guide.

### 4.9 Notifications

Rows follow `docs/FRONTEND_NOTIFICATIONS.md` exactly: icon tile colored by
type, gold start-border + dot when unread, title + relative time, body.
Opening marks read (`PATCH /client/notifications/{id}/read`) and navigates
per the `type` → details-page mapping. Tapping the bell clears the home
dot only when `unread_count` hits 0 (poll `read-all` / WebSocket).

### 4.10 Profile

Green header card: gold avatar initial, name, email (`dir="ltr"`).
Menu rows (`.mi`): البيانات الشخصية (`GET/PUT /client/profile` +
avatar upload), عناويني (`/client/locations`), طرق الدفع
(`/client/payment-methods`), الإشعارات, تواصل معنا (→ contact).
Last row: تسجيل الخروج in danger color with tinted icon →
`POST /client/auth/logout` then route to login.

### 4.11 Contact

Info rows with circular icon: phone `+966 550181166`, email
`GCMC@GCMC.SA`, address «الرياض — طريق العروبة» (real values from
`GET /public/meta`). Then «أرسل رسالة» card: الموضوع + الرسالة + gold
«إرسال» → `POST /client/support-tickets` (subject/message/subject-mapped
per the support-tickets endpoints; attachments optional).

---

## 5. Navigation map

```
splash ──► onboarding ──► login/register ──► home (tab root)
  └ guest ──────────────► home
tabbar: home | bookings | [+ wizard] | reports | profile
home ─► notifications, services(=wizard step1), bookings, reports,
        packages, contact (via profile)
wizard: services → staff → schedule → confirm → success → bookings|home
```

Back button pops one step inside flows; from tab roots there is no back.
The `+` FAB always enters the wizard at step 1 with fresh state.

## 6. State & behaviour rules

- **Loading**: skeleton rows mirroring `.mini`/`.bk` shapes in cream.
- **Empty**: center icon + muted line + one CTA (never a blank list).
- **Errors**: 422 field errors inline; everything else = toast using
  `message` (already Arabic when `Accept-Language: ar`).
- **Auth**: any 401 → clear token, route to login keeping the intended
  destination.
- **Dates/times**: display in Arabic with Arab-Indic digits and
  Asia/Riyadh; send API values in ISO (`YYYY-MM-DD`, `HH:mm`).
- **Pull-to-refresh** on home, bookings, reports, notifications.

## 7. Mockup ↔ API reconciliation (read before building)

| Mockup | API reality | Rule |
|---|---|---|
| Wizard step 1 «الخدمة» lists 4 consultation types with fixed prices | Booking is **package-first** (`GET /public/packages`, PUB-01) and consultants are filtered by `specialization` (PUB-03–06) | Keep the 4-step visual. Implement step 1 as the package/offer list from `GET /public/packages` rendered in `.pick` rows (name, session count/price). Consultant filter = the picked offer's specialization if provided, else all. |
| Confirm step shows a fixed total with no payment | Real flow quotes first (`POST /client/bookings/quote`) and may require card + 3-D Secure (`requires_payment`, Moyasar) | Keep the summary card look; populate الإجمالي from `quote.amount_formatted`. If `requires_payment`, insert a payment step **between** schedule and confirm using the same card/pick styling (saved cards `.pick` rows + new-card form). Handle `SLOT_NOT_AVAILABLE` (409) by bouncing back to step 3 with the slot re-loaded. |
| Register form has 4 fields | API requires `password_confirmation` | Add the confirm-password field; layout unchanged. |
| «الخدمات» 4 fixed types | No dedicated services endpoint; offers = packages, consultant specialty = `specialization` | «خدماتنا» rows on home list packages' names/prices. |
| Success ref `GC-1027` | Real `reference` (`BK-…`) returned by `POST /client/bookings` | Display the API value verbatim in the gold chip. |
| «تفاصيل/إلغاء» on upcoming | Cancel allowed per API rule; cancellation policy shown in confirm copy | Keep «إلغاء مجاني قبل ٢٤ ساعة» copy in sync with backend config before release. |
| Guest mode reaches home | Most client endpoints need a token | Guest browsing is allowed through step 2 (public consultants/packages); force login before step 3 data (slots are public, quote/book are not — prompt login at the «متابعة» of step 3 at the latest). |

## 8. Assets

- `logo_icon.png` — app icon mark (used on splash, login, header).
- Fonts: Google Fonts `Amiri` + `IBM Plex Sans Arabic` (bundle equivalents
  for native builds).
- All icons: Lucide outline set at 24×24 (stroke 1.8, round joins);
  `star` filled.

---

*Source of truth for visuals: the approved HTML mockup of the client app
(single-file, RTL, 430px frame). This document condenses it for
implementation; when in doubt, match the mockup pixel-for-pixel.*
