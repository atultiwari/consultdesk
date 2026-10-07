# Plan — **ConsultDesk**: a self-hosted consultation-booking app for shared hosting

> **Name:** *ConsultDesk*: a front desk for booking consultations with doctors, teachers and mentors.
> Repo: `github.com/atultiwari/consultdesk`. Local path: `/Users/atultiwari/Projects/domains/consultdesk`.
> Alternative name: *Darshan*.
>
> This is the source-of-truth build plan. Update it when decisions change.

## 1. Context

Dr. Atul Tiwari (pathologist, AI researcher, medical educator) wants a **"Book a consultation"** feature.

**Where it will run**
- **atultiwari.com:** a static React site on Hostinger. ConsultDesk will be served at a subdomain, e.g. `book.atultiwari.com`.
- **Vedant Research Labs:** a WordPress + WooCommerce site on Hostinger, where **several teachers** are bookable, e.g. `book.vedantresearchlabs.com`.
- **Other doctors and educators:** they typically have **only shared hosting (PHP + MySQL)** and no Supabase, Node or DevOps knowledge.

So it is built as an **independent, installable product**, not as code inside the portfolio repo.

**Requirements**
- **Calendar:** syncs with Google Calendar.
- **Admin panel:** a secret admin URL with password login.
- **Availability:** routine weekly slots, blocked dates and times, and booking rules.
- **Categories:** CRUD of consultation categories (services).
- **Multiple providers:** several bookable people, each with their own calendar, availability, services and payment details.
- **Payments:**
  1. **Manual UPI / bank transfer.** The customer pays the provider's VPA, submits the UTR and optionally sends a screenshot on WhatsApp. The booking is confirmed by hand through a **Telegram bot** or the **admin panel**.
  2. **Razorpay on VRL's merchant account via the Payment Links API.**
     - The server creates a link on `rzp.io` and redirects the customer to it.
     - Razorpay redirects back to ConsultDesk after payment, and a webhook confirms the booking.
     - The customer never visits the VRL site, and no domain whitelisting is needed.
     - Trade-off: the checkout shows "Vedant Research Labs" and the money settles to VRL.
     - The provider interface also allows embedded Razorpay Checkout later, on domains approved in the Razorpay dashboard.
- **UI quality:** must look distinctive, polished and responsive. No generic "AI-slop" template look.

**Why PHP:** React stays as the UI. Shared hosting runs only PHP, though, so the API that talks to MySQL, Google, Razorpay and Telegram and holds the secrets has to be PHP. The React app is built once into static files and served by the same PHP host.

## 2. Product shape

- **One installation = one organisation.** It holds many **providers** (teachers or doctors), each with many **services**. There is no SaaS multi-tenancy, and each site owner installs their own copy.
- **Install like WordPress:**
  1. Upload the release zip to the subdomain folder in Hostinger hPanel.
  2. Open `/install`.
  3. The wizard checks PHP version and extensions, asks for MySQL credentials, runs the migrations, creates the owner account, chooses the secret admin slug and writes `config.php`.
  4. Add one cron entry, shown with a copy button.
- **Updates:** upload a new zip; the admin panel's "Run database updates" button applies pending migrations.
- **Three ways to put it on a site:**
  1. **Subdomain page.** `book.atultiwari.com` lists providers, or goes straight to the provider when there is only one.
  2. **Embed script** for any site, including static sites and WordPress: `<script src="https://book.x.com/embed.js" data-provider="atul" data-service="research-guidance"></script>` renders a "Book a session" button that opens an accessible modal iframe, auto-resized via `postMessage` (only messages from the booking site's origin are accepted). `data-label` changes the button text. 1.4 KB gzipped.
  3. **Direct deep links** such as `/p/atul/research-guidance`, usable in WhatsApp, Instagram bio or email signatures.
  - A WordPress shortcode plugin wrapper for the embed (`[consultdesk provider="..."]`) is a later phase.
- **Roles:**
  - **Owner:** everything, including org settings and payment gateways.
  - **Admin:** manages all providers and bookings.
  - **Provider:** manages only their own profile, calendar, availability, services and bookings, and confirms their own UPI payments.

## 3. Tech stack (shared-hosting-safe; no Node, SSH or Composer needed on the server)

| Layer | Choice | Why |
|---|---|---|
| API | **PHP 8.1+, Slim 4** (PSR-7), PDO/MySQL, `vendor/` **bundled in the release zip** | Battle-tested micro-framework that runs on any shared host |
| DB | **MySQL 8 / MariaDB 10.4+**, InnoDB, utf8mb4 | Available on every shared host |
| HTTP clients | Guzzle; Google Calendar, Razorpay and Telegram called **over plain REST**, not `google/apiclient` (about 100 MB) | Keeps the zip small |
| Mail | PHPMailer over the host's SMTP | Hostinger includes mailboxes |
| Validation | A small in-house typed validator (`Http\Validation\Input`) plus request DTOs | Validates at the boundary; no extra dependency, clean PHPStan, our own messages (changed from `respect/validation` in Phase 2) |
| Frontend | **React 19 + TypeScript + Vite**, React Router (history mode; `.htaccess` rewrites to `index.html`), TanStack Query, React Hook Form + zod | |
| UI primitives | **Radix UI** headless primitives (Dialog, Popover, Select, Tabs, Toast) with **our own CSS** (CSS variables, no Tailwind default look) | Accessible without the template aesthetic |
| Dates | `@date-fns/tz` (frontend); PHP `DateTimeImmutable` + `IntlDateFormatter` (API) | Handles timezones and DST |
| Tests | PHPUnit (API), Vitest + Testing Library (web), Playwright (E2E) | 80 % coverage gate |
| CI/Release | GitHub Actions: lint, test, build web, `composer install --no-dev -o`, zip `consultdesk-x.y.z.zip` | Gives non-technical users a single file |

## 4. Repository layout

```
consultdesk/
  api/
    public/index.php            front controller (Slim); /api/* only
    src/
      Http/        routes.php, Middleware (Auth, Csrf, RateLimit, Json envelope, ErrorHandler)
      Domain/
        Availability/  SlotEngine.php (PURE), Rules, Interval math
        Booking/       BookingService (create/hold/confirm/reject/cancel/reschedule), StatusMachine
        Provider/ Service/ Settings/ User/
      Payments/    PaymentProvider.php (interface), ManualUpi.php, RazorpayLinks.php, RazorpayCheckout.php (later)
      Calendar/    CalendarProvider.php (interface), GoogleCalendar.php (OAuth, freebusy, events)
      Notify/      Notifier.php, TelegramNotifier.php, MailNotifier.php, Outbox.php (retrying jobs)
      Infra/       Db.php (PDO), Migrator.php, Crypto.php (sodium secretbox for tokens), Clock.php
    migrations/    001_init.sql, 002_seed.sql, …
    cron.php       expire holds, send outbox, reminders (CLI or ?key= URL)
    install/       web installer
    tests/         PHPUnit (unit + integration against a test MySQL)
  web/
    src/
      app/         router, providers (QueryClient, Theme)
      design/      tokens.css, typography, components (Button, Card, Field, SlotGrid, Stepper, Badge…)
      booking/     ProviderList, ProviderPage, ServicePicker, DateSlotPicker, IntakeForm, PaymentStep, BookingStatus
      admin/       Login, Dashboard, Bookings, Providers, Services (+question builder), Availability, BlockedTimes,
                   Rules, Payments, Integrations (Google, Telegram), Users, Branding, System (updates)
      embed/       embed.ts → embed.js (vanilla, <3 KB) + postMessage resize
      api/         typed client (envelope {success,data,error,meta})
    tests/
  e2e/             Playwright
  docs/            PLAN.md, INSTALL.md (Hostinger screenshots), CONFIG.md, SECURITY.md
  .github/workflows/ ci.yml, release.yml
```

## 5. Data model (MySQL)

- `settings`: key/value JSON. Holds org name, branding (logo, accent colours, fonts), default timezone, SMTP settings and the cron key. The secret admin path is the `ADMIN_PATH` config value, not a setting, so it never reaches the database or the web bundle.
- `users`: `id`, `email`, `name`, `password_hash` (argon2id), `role` (owner | admin | provider), `provider_id` (nullable), `telegram_chat_id` (nullable), `last_login_at`.
- `sessions`: admin sign-ins. `id` is a SHA-256 of the cookie value, with the CSRF token hash, IP, user agent, and `expires_at` (8 hours idle, 30 days at most).
- `password_resets`: one-time links, `purpose` reset (30 minutes) or invite (two days); only the token's hash is stored, and a newer link retires older ones. `users` also has `password_set_at`, `invited_at` and `disabled_at`.
- `providers`: `slug`, `name`, `title`, `bio`, `photo_path`, `timezone`, `active`, `sort_order`.
  - Contact: `whatsapp`, `telegram_chat_id`, `notify_email` (provider-side emails; the owner gets a copy, or gets them alone when this is empty).
  - UPI: `upi_vpa`, `upi_payee_name`.
  - Booking rules: `min_notice_min`, `horizon_days`, `buffer_before`, `buffer_after`, `slot_interval`, `max_per_day`. All are editable per provider in the admin panel (Rules).
- `services`: `provider_id`, `slug`, `title`, `tagline`, `description`, `audience`, `duration_min` (1–1440), `price_minor`, `currency`, `requires_approval`, `payment_methods` (JSON), `questions` (JSON schema: text, textarea, select, url, checkbox, required), `active`, `sort_order`.
- `availability_rules`: `provider_id`, `weekday` (ISO: 1 = Monday … 7 = Sunday), `start_time`, `end_time`, and an optional `service_id`. If a service has its own rules, **only those apply** to it; otherwise the provider's general rules apply.
- `blocked_periods`: `provider_id` (nullable means an org-wide holiday), `start_at`, `end_at`, `all_day`, `reason`.
- `bookings`:
  - Identity: `ref` (e.g. `VRL-7F3K`; the prefix is set under Bookings, default `BOOKING_PREFIX` or `CD`), `public_token_hash` (lookups), `public_token_enc` (sodium-encrypted copy so every email can carry the status link), `provider_id`, `service_id`, `start_at`/`end_at` (UTC).
  - Customer: name, email, phone and timezone, plus `answers` (JSON).
  - Payment: `amount_minor`, `currency`, `payment_method` (upi | razorpay_link | free), `utr`, `gateway_ref`, `gateway_payment_id`.
  - Calendar: `gcal_event_id`, `meet_url`.
  - Lifecycle: `status`, `hold_expires_at`, `confirmed_by`, timestamps.
- `payment_gateways`: org-level Razorpay keys (encrypted), with optional per-provider override.
- `payment_events`: raw webhook payload; `event_id` is UNIQUE so duplicates are ignored.
- `oauth_tokens`: Google refresh token per provider, encrypted with sodium using a key from `config.php`, plus the calendars that block availability (default: primary only), the calendar events go to, and a `status` (active | broken).
- Google support tables: `google_oauth_states` (one-time, 30-minute OAuth state with an encrypted PKCE verifier) and `google_busy_cache` (free/busy answers kept 2 minutes).
- Telegram: `telegram_link_codes` (one-time, hashed, 24 h codes for `t.me/<bot>?start=<code>`) and `telegram_messages` (alerts that still carry buttons, so they can be updated when a booking is settled anywhere).
- Housekeeping: `outbox_jobs`, `login_attempts`, `sessions`, `audit_log`, `rate_limits` (fixed-window counters keyed by an HMAC of the client IP), `migrations`.

**Preventing double bookings without Postgres exclusion constraints:**
1. `BookingService::hold()` opens a transaction and runs `SELECT … FROM providers WHERE id=? FOR UPDATE`, which serialises bookings per provider.
2. Still under the lock, it reloads the weekly rules, blocked periods and blocking bookings (`confirmed`, plus `held` / `awaiting_verification` whose hold has not lapsed) and requires `SlotEngine` to offer that exact start. That covers weekly hours, the slot grid, blocked periods, notice, horizon, the gap (§8) and the daily cap. Then it inserts.
3. Integration tests race separate PHP processes (same slot, overlapping slots, and the daily cap) to prove it.
4. Connections use READ COMMITTED and strict `sql_mode`; transactions are retried on deadlock or lock-wait timeout.

**Status machine:**
- UPI: `held → awaiting_verification` (UTR submitted; the hold is extended to 24 h) `→ confirmed | rejected`. If nobody verifies within 24 h it becomes `expired` and the slot is released.
- Razorpay Payment Links: `held → confirmed` (webhook) `| expired`.
- Free services that require approval: `held → confirmed | rejected`, held for up to 24 h.
- Holds: **UPI** 30 minutes to pay and submit the UTR, then up to **24 hours** for staff to verify it; **Razorpay** 30 minutes (the link closes 90 seconds earlier); **free sessions needing approval** up to 24 hours for staff to approve. Staff can confirm or reject from the admin panel, Telegram or the email links.
- A booking whose hold has lapsed cannot be confirmed (the slot may already be rebooked). Holds and the UPI verification window never run past the session start.
- `completed` and `no_show` can only be set once the session has started.
- A UTR can be used for only one booking, ever (also after expiry or rejection), to stop one payment covering two bookings.
- Admin actions after booking: `cancelled`, `completed`, `no_show`, `rescheduled`.
- Cron expires stale holds.

## 6. Key flows

1. **Slots.** `SlotEngine` is pure and heavily unit-tested. Given a provider and service, it starts from the weekly rules for the date range and removes:
   - blocked periods (provider-specific and org-wide),
   - existing bookings, keeping the gap from §8 on both sides,
   - **Google free/busy** times across that provider's chosen calendars, cached for 2 minutes. If Google cannot be reached or access was revoked, booking stays open (only the provider's own bookings block time) and staff are emailed once when access is revoked,
   - anything inside the minimum-notice window, beyond the horizon, or on days already at `max_per_day`.

   Slots are returned in UTC and shown in the **visitor's timezone**, auto-detected and changeable.
2. **Book.** Pick a service, then a date and slot, then fill the intake form, then pay.
   - Spam protection: a honeypot field, per-IP rate limits (IPv6 grouped by /64; a trusted proxy's client-IP header is honoured only when configured), a per-email booking limit, at most 3 open (unpaid/unapproved) bookings per email, and names that may not contain links or markup. Cloudflare Turnstile is optional.
   - The status page lives at `/b/{ref}?t={token}`, and that link is also emailed.
   - Public API (Phase 2): `GET /api/providers`, `GET /api/providers/{slug}`, `GET /api/providers/{slug}/services/{service}/slots?from&to`, `POST /api/bookings`, `GET /api/bookings/{ref}?t=`, `POST /api/bookings/{ref}/utr`. POST bodies must be `application/json`. Free services without approval are confirmed straight away.
   - Emails: each booking event fans out into one outbox job per email (customer, or staff = provider `notify_email` + owners). Emails are rendered at send time; an event that is stale when cron runs (e.g. "held" after the customer already paid) sends nothing.
3. **Manual UPI.**
   - The UI shows the amount, a `upi://pay?pa&pn&am&cu=INR&tn={ref}` deep link on mobile and a QR code on desktop, using the provider's own VPA.
   - The customer submits the **12-digit UTR** (validated), and can optionally tap **"Send screenshot on WhatsApp"**, which opens `wa.me/<provider whatsapp>?text=<prefilled ref, amount, slot>`.
   - Status becomes `awaiting_verification`.
   - **UTRs are single-use, forever** (a unique key, kept after expiry or rejection). Because of this, the payment step must stop customers from paying for a hold that is about to lapse:
     - before the UPI button, a plain notice: pay **once**, for this booking only (ref and amount shown), and submit the UTR straight after paying; one UTR can confirm only one booking;
     - a live countdown to `hold_expires_at`, with a warning in the last 10 minutes not to start a payment that cannot be submitted in time;
     - once the hold has lapsed, the page hides the UPI link and QR, says **do not pay**, and offers to pick a new slot;
     - the UTR field sits right next to the payment button, so paying and submitting happen together;
     - a duplicate-UTR error explains that an expired booking's payment cannot be reused and gives the provider's WhatsApp link pre-filled with the old ref, so the provider can sort it out by hand.
   - The **Telegram bot** messages the provider's chat (the owners' chats only when the provider has not linked Telegram) with **[✅ Confirm] [❌ Reject]** buttons. Requests to approve free sessions get the same buttons.
   - Confirm is one tap. Reject asks first (**[Yes, reject] [↩ Back]**), because a mistaken reject tells a paying customer their booking failed.
   - The webhook verifies the `X-Telegram-Bot-Api-Secret-Token` header and that the chat may act: the provider's chat, that provider's own user, or an owner or admin. Pressing a button on a booking that was already settled explains what happened and shows the outcome.
   - When a booking is settled anywhere (Telegram, admin panel, expiry), earlier alerts are edited to show the outcome and lose their buttons.
   - Chats are linked with one-time links (`php bin/telegram.php link provider <slug>`, later from the admin panel) and unlinked with `/stop`. Linking and button actions work only in **private** chats, and the person pressing must be that chat, because in a group every member sees the buttons. Owner/admin links expire after 15 minutes; provider links after 24 hours.
   - The same confirm action is available in the admin panel.
4. **Razorpay (VRL) Payment Links.**
   - `POST /v1/payment_links` is called with `reference_id=booking.id`, `expire_by=now+20m`, `callback_url=/b/{ref}?t=…` and `notes.source="consultdesk"`, then the customer is redirected to `short_url`.
   - The `/api/webhooks/razorpay` endpoint receives `payment_link.paid` / `payment_link.expired`, verifies the HMAC-SHA256 of the raw body, deduplicates on the event id, and then calls `confirm()`.
   - The callback signature is verified only to update the UI immediately; the webhook is the source of truth.
5. **`confirm()`**:
   - Runs Google `events.insert` on the provider's calendar with:
     - the customer as an attendee,
     - `conferenceData.createRequest` (creates the Google Meet link),
     - `sendUpdates=all` (Google emails the invite to the customer).
   - Stores the event id and Meet URL.
   - Sends a confirmation email (PHPMailer) and posts to Telegram.
   - Writes the audit log.

   With Google connected, the customer's confirmation email waits about 2 minutes so it carries the Meet link. The event id is derived from the booking, so a retried calendar job never creates a duplicate.

   Rejecting or cancelling a booking deletes the calendar event and frees the slot. Side effects go through the **outbox**, so a Google or SMTP failure is retried by cron rather than losing the booking.
6. **Google OAuth per provider.** In their own panel, each provider clicks **"Connect Google Calendar"** and chooses which calendars block their availability and which calendar receives events. (Until the admin panel exists: `php bin/google.php connect|status|calendars|set-calendars|disconnect <slug>`.) The flow uses PKCE, a one-time 30-minute state and the narrowest scopes: `calendar.events`, `calendar.freebusy`, `calendar.calendarlist.readonly`, `openid email`. Each link is bound to the Google account that is expected to sign in (any other account, or a partial grant, is refused and revoked), and replacing a working connection needs an explicit `--replace`. Free/busy is fetched per UTC week, only inside the bookable window, before `hold()` takes its lock; outages are remembered for a minute. The invite the customer receives carries no free-text answers.
   - The installation needs one Google Cloud OAuth client, set up with a step-by-step guide in INSTALL.md.
   - The app **must be published to "In production"**, because in "Testing" mode refresh tokens expire after 7 days. An unverified app works for up to 100 users, with a warning screen.
7. **Admin.**
   - Lives at `/{secretAdminSlug}`, never linked anywhere.
   - Email and password login with argon2id hashes.
   - Session: httpOnly, Secure, SameSite=Strict cookie, plus a CSRF token.
   - Login is rate-limited (5 attempts per 15 min per IP and account). Optional TOTP is a later phase.
   - Panels: Dashboard (pending verifications, today/upcoming), Bookings (filter and act), Providers, Services with the question builder, Availability, Blocked times, Rules, Payments, Integrations, Users, Branding and System (updates, cron health).

## 7. Design direction (avoid the generic AI look)

- **Themeable through tokens**, so each site keeps its own identity. Each installation sets accent and secondary colours, display and body fonts, radius and a logo.
  - atultiwari.com preset: H&E palette (haematoxylin `#5b3f8c`-ish, eosin pink, warm "lab bench" paper), *Instrument Serif* + *Geist*, matching the portfolio.
  - VRL preset: VRL brand colours.
- **Signature pieces:**
  - An editorial provider header with a serif name, credentials line and a short "what you'll walk away with".
  - Service cards that show duration and price clearly, with a "who it's for" tag.
  - A **week-strip date picker plus a slot grid** grouped Morning / Afternoon / Evening, with the visitor's timezone shown inline.
  - A 4-step stepper with a sticky summary panel on desktop that becomes a bottom sheet on mobile.
  - A status page styled like an appointment slip, or a clinic token slip, with a ref number and QR code.
- Light and dark themes, `prefers-reduced-motion` honoured, WCAG AA contrast, full keyboard support, and touch targets of at least 44 px.
- **As built (Phase 5):**
  - Presets are token sets selected by `data-preset` on `<html>`: `neutral` (system fonts, the default), `he` (portfolio tokens: haematoxylin `#40297a`, eosin `#b02a60`, lab-bench paper; Instrument Serif + Geist) and `vrl` (from the VRL site: violet `#3b2e7e`, rose accent, Bricolage Grotesque + Figtree, larger radii). Each has a tuned dark theme.
  - `GET /api/site` returns the org name, preset and optional brand colours (settings key `site`, sanitised; edited in Branding in Phase 6). Custom colours apply to the light theme only.
  - Fonts are self-hosted from `@fontsource/*`; only the active preset's fonts download. The last preset is cached so returning visitors get it before the API answers.
  - Motion: one signature curve `cubic-bezier(.22,1,.36,1)`, durations 160 / 260 / 420 ms, a short rise-in for entrances, stagger capped under 200 ms.
  - Plain CSS on tokens instead of Radix: the few interactive pieces (radio-based week strip and slot grid, `<details>` bottom sheet, native selects) are accessible with native elements.
  - Every token pair used for text passes WCAG AA in all presets and themes (`e2e/scripts/contrast.ts`). Lighthouse (mobile): provider 97, booking 94, status 96 performance; 100 accessibility.
- Before building any UI, use the `frontend-design` / `motion-design` skills, and review the screens in the browser at 375, 768 and 1280 px.

## 8. Seed data — starter sessions from research

The prices come from a survey of 32 Indian price points (Topmate, Preply, UrbanPro, mentorship
platforms, government honorarium norms): see [research/session-pricing.md](research/session-pricing.md).
The setup wizard (`<ADMIN_PATH>/setup`) offers three sets from `ServiceTemplates::sets()`; each price
is the early-career/student end of the band, and the wizard shows the full band beside it.

| Set | Sessions (price) |
|---|---|
| Any teacher or consultant | Free intro call 15 min · Quick clarity call 30 min ₹499 · One-to-one 60 min ₹999 · CV and LinkedIn review 30 min ₹499 · Mock interview 60 min ₹1,499 · Tutoring 60 min ₹449 · Career roadmap 45 min ₹799 |
| Medical AI, research and careers | Free intro call · Medical AI career roadmap 45 min ₹999 · Thesis clinic 60 min ₹1,499* · Statistics review 60 min ₹1,999* · Manuscript review 45 min ₹2,999* · ML code review 60 min ₹1,499 · Medical career guidance 30 min ₹699 · Health-tech startup advice 60 min ₹4,999* |
| Institutions | Invited talk · Hands-on workshop · Faculty development programme: free requests needing approval*, fee agreed afterwards |

\* requires approval. Free sessions are `free`; approval sessions offer UPI; others offer UPI and Razorpay.
The medical set is also what `bin/seed-dev.php` gives the local "demo" provider.

**Intake questions**
- **Every service asks for:** name, email, WhatsApp, role, institution, "What do you want to walk away with?" and optional links.
- **Per-service extras:**
  - Thesis: stage, data type, ethics status, deadline.
  - Code review: repo link, framework.
  - Workshop: audience, headcount, format, dates.
- **Disclaimer on medical services:** "No identifiable patient data; educational guidance, not clinical advice."

**Default rules:**
- Timezone: Asia/Kolkata.
- Minimum notice: 24 h.
- Booking horizon: 30 days.
- Buffers: 10 min before and 10 min after, customisable per provider. Between two of a provider's sessions the required gap is the **larger** of the two buffers (not their sum). Against external busy time (Google), a session's own before/after buffers apply.
- Slot interval: 30 min.
- At most 3 sessions per day.
- Holds: 30 min for UPI (to submit the UTR), 30 min for Razorpay links, 24 h for free sessions awaiting approval. A UPI booking awaiting verification is held for 24 h after the UTR is submitted.

## 9. Phases (TDD throughout; one PR per phase)

0. **Bootstrap.**
   - `git init /Users/atultiwari/Projects/domains/consultdesk` and create the GitHub repo `atultiwari/consultdesk`.
   - Commit this plan as `docs/PLAN.md`.
   - Add CI, PHP-CS-Fixer/PHPStan, ESLint/Prettier, and a Docker compose (PHP 8.1 + MariaDB) for **local dev only**.
1. **Domain core.**
   - Migrations and the migrator.
   - `SlotEngine` (DST, buffers, notice, horizon, daily cap, blocked periods, busy merge).
   - The booking status machine and the concurrency-safe `hold()`.
   - PHPUnit coverage of at least 90 % on the domain code.
2. **Public API and manual UPI.** Providers, services, slots, create booking, UTR submit, status page; outbox and cron; PHPMailer.
3. **Telegram bot.** Notifier and webhook (confirm/reject), plus mapping chats to providers.
4. **Google Calendar.** OAuth per provider, calendar selection, free/busy fed into slots, event create/delete with Meet.
5. **Public web UI.** Design system and tokens, then the booking flow, status page, branding presets and the embed script.
6. **Admin API and UI**, in two PRs.
   - **6a.** Sign-in at the secret `ADMIN_PATH` (argon2id, throttled per IP and per email, sessions of 8 hours idle and 30 days at most, a `__Host-` cookie with `SameSite=Strict`, CSRF header on every write), email password reset, roles (owner and admin see everything; a provider only their own), and the panels: Dashboard (payments to verify, requests to approve, today, coming up), Bookings (filters, detail with history, confirm/reject/cancel/complete/no-show), Providers (profile, contact, UPI, rules), Sessions with the question builder, Weekly hours and Blocked times. Every change is audited. `bin/user.php` creates accounts and resets passwords from the shell.
   - **6b.** Owner-only Users (email invites with a two-day link; role changes; disabling), Branding (name, preset, colours, logo upload), Payments (each provider's UPI details) and System (cron heartbeat, outbox health and retry, version, "Run database updates"); for everyone, My account (name, password, own Telegram), provider photos, and a Connections tab for Telegram and Google Calendar. Uploads are PNG/JPEG/WebP under 2 MB, re-encoded with GD and served from outside the web root.
7. **Razorpay Payment Links.** The gateway settings screen, link creation, the webhook (signature check and idempotency) and return handling. Tested end-to-end with **VRL test-mode keys**.
   - One link per booking, made when the customer books, for the exact amount, expiring with the hold (30 minutes for online payment). Confirmed by the signed webhook or the signed return redirect, whichever comes first; both must be signed by the account the booking is paid into. Late payments are recorded, not confirmed, and flagged for a refund; links of lapsed or cancelled bookings are cancelled.
   - Organisation keys plus an optional per-teacher account (`payment_gateways.provider_id`). Owner switches turn UPI and Razorpay on or off site-wide. Test-mode keys only until live payments are signed off.
8. **Installer and release.** Web installer, updater, the `release.yml` zip, and an INSTALL.md walkthrough of Hostinger subdomain setup, cron and the Google OAuth client.
9. **Go live:**
   - Install ConsultDesk at `book.atultiwari.com`, using the atultiwari preset.
   - Install it at `book.vedantresearchlabs.com` with multiple teachers.
   - In the **atultiwari-resume-webapp** repo (a separate small PR):
     - add a "Book a session" CTA in the Hero/Contact sections and the nav, linking to the subdomain or using `embed.js`,
     - update the CSP in `public/.htaccess` with `frame-src https://book.atultiwari.com` and `script-src` for `embed.js` if embedding,
     - relax `tests/app.test.tsx:16-20` if needed.
   - On VRL: add a **second** webhook in the Razorpay dashboard pointing to `https://book.vedantresearchlabs.com/api/webhooks/razorpay` for `payment_link.*`, and confirm that the existing WordPress webhook ignores those events.
10. **Later:** WordPress shortcode plugin, bundles and credits, self-serve reschedule/cancel, WhatsApp reminders through a business API, testimonials, TOTP 2FA, embedded Razorpay Checkout on approved domains.

## 10. Security checklist (run security-reviewer at the end of phases 2, 6 and 7)

- PDO prepared statements only.
- All input validated, with an `{success, data, error}` envelope and no stack traces in production.
- Secrets live in `config.php`, outside the web root where the host allows, and are never committed. Gateway, OAuth and Telegram tokens are encrypted at rest with sodium.
- Webhooks: HMAC check with `hash_equals`, idempotency on the event id, and a replay window.
- Telegram: secret-token header plus the chat-id allowlist.
- Login: argon2id, rate limits, and session ID regeneration on login.
- CSRF on every state-changing admin route.
- CSP headers in the shipped `.htaccess` (Phase 8). For the static web app:
  - `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: <allowed logo/photo hosts>; font-src 'self'; connect-src 'self'; frame-ancestors <'self' + sites allowed to embed>; base-uri 'none'; form-action 'self'; object-src 'none'` (the brand-colour `<style>` needs `'unsafe-inline'` for styles or a nonce);
  - `Referrer-Policy: no-referrer` (status URLs carry the token; the page also sets the meta tag), `X-Content-Type-Options: nosniff`, HSTS, a `Permissions-Policy`, `Cross-Origin-Opener-Policy: same-origin`;
  - `Cache-Control: no-store` on `/b/*`; no `X-Frame-Options` on pages meant to be embedded (it cannot express an allowlist; `frame-ancestors` does);
  - the SPA fallback rewrite must not swallow `/embed.js` or `/api/*`.
- `/install` locks itself once installation is done.
- Public status pages are reachable only with the token. The token is looked up by hash and kept otherwise only encrypted (`public_token_enc`); unknown ref and wrong token return the same 404.
- Uploads (provider photos only) are checked by MIME, re-encoded with GD, and stored outside executable paths.

## 11. Verification

- **Unit and integration:**
  - `composer test` (PHPUnit, including concurrent double-booking tests against MariaDB in Docker).
  - `npm test` (Vitest).
  - Coverage gate at 80 %.
- **E2E (Playwright)** against `docker compose up`:
  - book → UPI → confirm via a mocked Telegram callback → status shows Confirmed;
  - an admin blocks a date and its slots disappear;
  - a provider sees only their own bookings;
  - two parallel bookings of the same slot result in exactly one success and one 409.
- **Real integrations on staging** (a test subdomain on Hostinger):
  - Google: connect, book, and see the event with a Meet link and an invite email.
  - Telegram: the confirm button works.
  - Razorpay: a VRL test-mode payment link is paid, the webhook confirms the booking, and the return page updates.
- **Shared-hosting proof:** install from the release zip on a fresh Hostinger subdomain with no SSH, using only the web installer and an hPanel cron.
- **Responsive and design QA:** screenshots at 375, 768 and 1280 px in light and dark themes; a Lighthouse score of at least 90 for performance and accessibility on the public booking page.
