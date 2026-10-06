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
  2. **Embed script** for any site, including static sites and WordPress: `<script src="https://book.x.com/embed.js" data-provider="atul" data-service="research-guidance"></script>` renders a "Book a session" button that opens an accessible modal iframe, auto-resized via `postMessage`.
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

- `settings`: key/value JSON. Holds org name, branding (logo, accent colours, fonts), default timezone, the admin slug, SMTP settings and the cron key.
- `users`: `id`, `email`, `password_hash` (argon2id), `role` (owner | admin | provider), `provider_id` (nullable), `telegram_chat_id` (nullable), `last_login_at`.
- `providers`: `slug`, `name`, `title`, `bio`, `photo_path`, `timezone`, `active`, `sort_order`.
  - Contact: `whatsapp`, `telegram_chat_id`, `notify_email` (provider-side emails; the owner gets a copy, or gets them alone when this is empty).
  - UPI: `upi_vpa`, `upi_payee_name`.
  - Booking rules: `min_notice_min`, `horizon_days`, `buffer_before`, `buffer_after`, `slot_interval`, `max_per_day`. All are editable per provider in the admin panel (Rules).
- `services`: `provider_id`, `slug`, `title`, `tagline`, `description`, `audience`, `duration_min` (1–1440), `price_minor`, `currency`, `requires_approval`, `payment_methods` (JSON), `questions` (JSON schema: text, textarea, select, url, checkbox, required), `active`, `sort_order`.
- `availability_rules`: `provider_id`, `weekday` (ISO: 1 = Monday … 7 = Sunday), `start_time`, `end_time`, and an optional `service_id`. If a service has its own rules, **only those apply** to it; otherwise the provider's general rules apply.
- `blocked_periods`: `provider_id` (nullable means an org-wide holiday), `start_at`, `end_at`, `all_day`, `reason`.
- `bookings`:
  - Identity: `ref` (e.g. `CD-7F3K`), `public_token_hash` (lookups), `public_token_enc` (sodium-encrypted copy so every email can carry the status link), `provider_id`, `service_id`, `start_at`/`end_at` (UTC).
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
6. **Google OAuth per provider.** In their own panel, each provider clicks **"Connect Google Calendar"** and chooses which calendars block their availability and which calendar receives events. (Until the admin panel exists: `php bin/google.php connect|status|calendars|set-calendars|disconnect <slug>`.) The flow uses PKCE, a one-time 30-minute state and the narrowest scopes: `calendar.events`, `calendar.freebusy`, `calendar.calendarlist.readonly`, `openid email`. A partial grant is revoked and refused.
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
- Light and dark themes, `prefers-reduced-motion` honoured, WCAG AA contrast, full keyboard support (Radix), and touch targets of at least 44 px.
- Before building any UI, use the `frontend-design` / `motion-design` skills, and review the screens in the browser at 375, 768 and 1280 px.

## 8. Seed data — service templates from research

The anchors are Topmate peer Dr. Avneesh Khare (medical AI, ₹2,999–3,499 for 60 min) and Indian AI mentors (₹500–2,000). The Calendly reference charges $197 for 60 min. Shipped as templates the installer can apply:

| Service | Duration | Seed price |
|---|---|---|
| Intro / fit call (requires approval) | 15 min | Free |
| AI-in-Medicine career guidance (students, residents, doctors) | 30 min | ₹1,499 |
| Research & thesis guidance (AI/ML study design, datasets, methodology, paper review) | 60 min | ₹2,999 |
| Project / code review (DL pathology/imaging, AI tool builds) | 60 min | ₹3,499 |
| AI tools for clinicians & educators (hands-on) | 45 min | ₹1,999 |
| Health-AI startup / product consult | 60 min | ₹4,499 |
| Institutional workshop / FDP / invited talk (scoping call) | 30 min | Free (requires approval) |
| *(phase 2)* Mentorship bundle 4 × 45 min, valid 90 days · Priority DM async question | — | ₹8,999 · ₹499–999 |

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
- Holds: 60 min for UPI, 20 min for Razorpay links, 24 h for free sessions awaiting approval. A UPI booking awaiting verification is held for 24 h after the UTR is submitted.

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
6. **Admin API and UI.** Auth, CSRF and roles, plus every panel in §6.7, including the service question builder.
7. **Razorpay Payment Links.** The gateway settings screen, link creation, the webhook (signature check and idempotency) and return handling. Tested end-to-end with **VRL test-mode keys**.
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
- CSP headers in the shipped `.htaccess`.
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
