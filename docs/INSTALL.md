# Installing ConsultDesk

> The one-click installer and release zip arrive in Phase 8. This page collects the steps that need
> you (accounts, credentials, hosting settings) as each phase introduces them.

## Configuration values

ConsultDesk reads `api/config.php` (written by the installer) and falls back to environment variables
with the same names. Never commit real values.

| Key | What it is |
|---|---|
| `APP_URL` | Public address of the booking site, e.g. `https://book.atultiwari.com` |
| `APP_KEY` | `base64:` followed by 32 random bytes. Encrypts tokens at rest. Generate with `php -r 'echo "base64:".base64_encode(random_bytes(32));'` and **keep a backup**: losing it breaks status links in old emails. |
| `CRON_KEY` | At least 32 random characters; only needed if the cron is triggered by URL |
| `MEDIA_PATH` | Optional. Folder for uploaded logos and provider photos; defaults to `api/storage/media`. Keep it outside the public web folder and writable by PHP. |
| `ADMIN_PATH` | The secret first part of the admin address, e.g. `desk-7q2x9m4k` for `https://<site>/desk-7q2x9m4k`. 8–64 lowercase letters, digits and dashes, starting with a letter or digit; not `api`, `assets`, `install` or `embed-js`. Leave empty to switch the admin area off. |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MySQL / MariaDB database |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD` | Outgoing mail (see below) |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender address and name on booking emails |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_BOT_USERNAME` | Optional Telegram bot (see below). Leave the token empty to turn Telegram off. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Optional Google Calendar integration (see below). Leave empty to turn it off. |
| `TRUSTED_PROXIES`, `TRUSTED_PROXY_HEADER` | Only if the site sits behind a proxy such as Cloudflare: the proxy's IP ranges (comma-separated CIDRs) and the header carrying the visitor's IP (e.g. `CF-Connecting-IP`). Leave empty otherwise; rate limits then use the connecting IP. |

## Outgoing email (Phase 2)

Booking emails are sent over SMTP. On Hostinger, use a mailbox from your hosting plan:

1. hPanel → **Emails** → choose the domain → **Create email account**, e.g. `bookings@atultiwari.com`. Set a strong password.
2. Use these settings:
   - `SMTP_HOST` = `smtp.hostinger.com`
   - `SMTP_PORT` = `465` with `SMTP_ENCRYPTION` = `ssl` (or `587` with `tls`)
   - `SMTP_USER` = the full mailbox address
   - `SMTP_PASSWORD` = the mailbox password
   - `MAIL_FROM` = the same mailbox address
3. In hPanel → **Emails** → **Email deliverability**, make sure SPF, DKIM and DMARC show as set, so booking emails do not land in spam.

## Cron (Phase 2)

One scheduled task expires lapsed holds and sends queued emails. Without it, no emails go out.

1. hPanel → **Advanced** → **Cron Jobs**.
2. Type: **PHP**. Command (adjust the path to where `api/` is uploaded):
   `php /home/<your-user>/domains/<your-domain>/public_html/api/bin/cron.php`
3. Schedule: every minute (`* * * * *`).

If a host can only call a URL, call `https://<your-site>/api/cron` with the header `X-Cron-Key: <CRON_KEY>`
(or, as a last resort, `?key=<CRON_KEY>`). The CLI form is preferred because the key never appears in logs.

The local-development `CRON_KEY` from `docker-compose.yml` is refused on any `https://` site. There is no committed `APP_KEY`: every install generates its own.

## Telegram bot (Phase 3, optional)

The bot sends "verify this UPI payment" and "approve this request" alerts with **Confirm** and
**Reject** buttons. Email keeps working without it.

### 1. Create the bot (about 2 minutes, on your phone)

1. In Telegram, open a chat with **@BotFather** (blue tick) and send `/newbot`.
2. Give it a display name, e.g. `Dr. Atul Tiwari Bookings`.
3. Give it a username ending in `bot`, e.g. `AtulTiwariBookingsBot`.
4. BotFather replies with a **token** like `123456789:AA...`. Treat it like a password.
5. Optional: send `/setprivacy` → choose the bot → `Enable`, and `/setjoingroups` → `Disable` if
   alerts should only go to private chats.

### 2. Configure ConsultDesk

| Key | Value |
|---|---|
| `TELEGRAM_BOT_TOKEN` | the token from BotFather |
| `TELEGRAM_WEBHOOK_SECRET` | 32+ random letters/digits, e.g. from `php -r 'echo bin2hex(random_bytes(24));'` |
| `TELEGRAM_BOT_USERNAME` | the bot's username, without `@` |

### 3. Connect the webhook

The site must be on `https://` (Telegram refuses plain http). Then run, once:

```bash
php api/bin/telegram.php set-webhook
php api/bin/telegram.php info      # shows the webhook URL and any delivery errors
```

### 4. Link the chats that should get alerts

```bash
php api/bin/telegram.php link provider <provider-slug>   # the provider's own chat
php api/bin/telegram.php link user <owner-email>         # the owner, as fallback for providers without Telegram
```

Each command prints a `https://t.me/<bot>?start=...` link that works once: 24 hours for a provider,
15 minutes for an owner or admin (those links grant approval rights, so open them straight away and
don't forward them). Open it on the phone that should receive alerts and tap **Start**.

Alerts and buttons work only in a **private chat** with the bot, not in groups. Sending `/stop` to the
bot unlinks that chat.

### What the bot sends

- **With buttons** (someone needs to decide): a UPI payment to verify (✅ Confirm / ❌ Reject) and a
  free request that needs approval.
- **Notices** (no buttons): a new booking that confirmed itself (paid online with Razorpay, or a free
  session that needs no approval), and **Refund needed** when an online payment arrives after the
  hold ended, with the Razorpay payment id to find it in the dashboard.

Alerts go to the provider's linked chat, or to the owners' chats if the provider has none.

### Trying the bot on your own computer

Telegram can't reach `localhost`, so instead of the webhook, let ConsultDesk fetch the bot's
messages itself (this also keeps cron running, which is what sends the alerts):

```bash
docker compose exec api php bin/telegram.php poll
```

Leave it running and use the bot as normal; Ctrl+C stops it. It removes the webhook, so on a live
site run `php bin/telegram.php set-webhook` again afterwards.

## Google Calendar (Phase 4, optional)

Each provider can connect their own Google account so that their calendar blocks clashing slots, and
confirmed bookings appear on it with a Google Meet link and an invite to the customer. The site needs
one Google Cloud "OAuth client"; every provider then connects their own account to it.

### 1. Create the OAuth client (about 10 minutes, once per site)

1. Go to <https://console.cloud.google.com/>, sign in, and create a project (e.g. `ConsultDesk`).
2. **APIs & Services → Library**: search for **Google Calendar API** and click **Enable**.
3. **APIs & Services → OAuth consent screen** (called "Google Auth Platform" in newer consoles):
   - User type **External**. App name, support email and developer email as you like.
   - **Data access / Scopes → Add or remove scopes**, add:
     `.../auth/calendar.events`, `.../auth/calendar.freebusy`, `.../auth/calendar.calendarlist.readonly`,
     plus `openid` and `.../auth/userinfo.email`.
   - **Audience / Publishing status: click "Publish app" → In production.** In "Testing" mode Google
     expires the connection after 7 days. An unverified app works for up to 100 accounts; providers
     will see a "Google hasn't verified this app" screen and choose **Advanced → Go to … (unsafe)**,
     which is expected for a private installation.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Application type **Web application**.
   - **Authorised redirect URI**: `https://<your-booking-site>/api/google/callback`
     (exactly `APP_URL` + `/api/google/callback`).
5. Copy the **Client ID** and **Client secret** into `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.

### 2. Connect each provider

```bash
php api/bin/google.php connect <provider-slug> <provider's-google-email>
```

This prints a Google link that works once, for 30 minutes, and **only for that Google account**:
anyone else who opens it is refused. Send it to the provider; they sign in, tick all the calendar
permissions and allow. The page then says "Google Calendar connected".

To move a provider to a different Google account, add `--replace` (the old access is revoked).

By default only the provider's primary calendar blocks time and receives events. To change that:

```bash
php api/bin/google.php calendars <provider-slug>          # list calendar ids
php api/bin/google.php set-calendars <provider-slug> --busy=<id>,<id> --target=<id>
php api/bin/google.php status <provider-slug>
```

If a provider removes the app's access in their Google account, bookings keep working (without the
clash check), staff get one email, and `connect` must be run again. `disconnect` revokes access and
forgets the tokens.

Free/busy is fetched per week and cached for 2 minutes; if Google is unreachable, that is remembered
for a minute and bookings stay open.

## Admin panel (Phase 6)

The admin area is at `https://<your-booking-site>/<ADMIN_PATH>`. Anywhere else, including a wrong
guess at the path, shows the ordinary "page not found", and the path itself never appears in the
site's code. Choose something unguessable, and don't link to it from public pages.

### First owner

Like WordPress, a site with no accounts yet asks for the owner the first time anyone opens
`https://<your-booking-site>/<ADMIN_PATH>`: **Create your owner account** (name, email, a password of
at least 10 characters). The owner is signed in and taken straight to **Set up your site**. The form
disappears for good once an account exists.

Two optional settings in `.env` (or `config.php`):

| Setting | What it does |
|---|---|
| `OWNER_EMAIL`, `OWNER_NAME` | Fill in the form, so you only type the password. |
| `OWNER_PASSWORD` (with `OWNER_EMAIL`) | Creates the owner automatically on an empty database: handy when you reset a test site often. **System** reminds you to remove it on a real site. |
| `SETUP_KEY` | The form also asks for this key, so nobody else can claim a freshly uploaded site first. |

`php bin/install.php` applies database updates and creates the owner from `.env` when it can; on
your own computer, `php bin/install.php --fresh` backs up and then empties the database to start
again (it refuses on a real `https://` site).

You can also create accounts from the server's shell:

```bash
php bin/user.php create --email=you@example.com --role=owner --name="Your Name"
```

It asks for the password twice without showing it (at least 10 characters; a short phrase works
well). Other commands:

```bash
php bin/user.php create --email=teacher@example.com --role=provider --provider=<provider-slug>
php bin/user.php reset-password --email=you@example.com
php bin/user.php list
```

`reset-password` also signs that person out everywhere.

### Setting up your site

After the first sign-in, the dashboard shows **Set up your site** until it's done (owners only; it
lives at `<ADMIN_PATH>/setup`). It asks three things, and every answer can be changed later:

1. **Who will people book with?** *Just me* (a personal site such as yourname.com: the booking page
   opens straight on your sessions, and "Add provider" is hidden) or *Several teachers* (an academy
   or practice). You can switch a single-teacher site to several teachers at any time from
   Branding → Site mode. Going back to one teacher is only possible while one teacher is active.
2. **Your first teacher:** name, title, about, timezone, the email for new bookings, and optional
   WhatsApp and UPI details. Starter weekly hours are added (Mon–Fri 10–13 and 16–19, Sat 10–13)
   if the teacher has none; edit them under Hours.
3. **Starter sessions:** tick any of the suggested sessions, change the title, length and price, or
   skip and create your own. Suggestions come in three sets (any teacher, medical AI and research,
   institutions). Each shows the price band Indian providers charge; see
   [research/session-pricing.md](research/session-pricing.md) for the sources.

A fresh install has no demo data. `bin/seed-dev.php` (the "Dr. Demo Placeholder" provider) is for
local development only.

### Who can do what

- **Owner**: everything, including Users, Branding, Payments and System.
- **Admin**: every provider and booking, adding providers, closing the whole organisation for a
  holiday.
- **Provider**: their own profile, UPI details, booking rules, sessions, weekly hours, blocked times
  and bookings, and nothing else.

### Adding people

Owners invite people from **Users → Invite someone**. The invitee gets an email with a link to choose
their own password; it works once, for two days, and **Resend invite** sends a fresh one. Changing
someone's role signs them out so the change applies at once; **Disable** ends their sessions and stops
them signing in until they are enabled again. Owners can't demote or disable themselves; ask another
owner, or use `php bin/user.php` on the server.

Everyone can change their own name and password, and link their own Telegram, under **My account**.

### Branding, photos and connections

- **Branding** sets the organisation name, the look (Neutral, H&E or Vedant Research Labs), optional
  brand colours and a logo. Each provider's **Profile** tab takes a photo.
- Uploads must be PNG, JPEG or WebP under 2 MB. They are re-encoded on the server (which removes
  location data and anything hidden in the file) and stored in `MEDIA_PATH`, so include that folder
  in your backups. The server needs PHP's GD extension (standard on Hostinger).
- A provider's **Connections** tab replaces `bin/telegram.php link` and `bin/google.php connect`:
  it gives the one-time Telegram link, the Google consent link, and lets them pick which calendars
  block bookings and which one gets new sessions.

### System

**System → Backups** downloads a backup of everything (bookings, teachers, sessions, settings) as a
`.sql.gz` file, after you type your password again, and restores one: choose the file, type your
password and `RESTORE`. Restoring signs everyone out and first saves what was there to
`storage/backups/` (the last five are kept). Only backups made by the same site restore: each one is
signed with a key derived from `APP_KEY`, and saved Razorpay secrets inside it stay encrypted with
that key, so keep `APP_KEY` with your backups. From the shell: `php bin/backup.php [--out=FILE]`
and `php bin/restore.php FILE`.

**System** also shows whether cron has run in the last five minutes, the email/calendar/Telegram queue
(with **Retry failed jobs**), the version, and any database updates waiting after an upgrade, with a
**Run database updates** button. Take a backup before running updates. Sign-in keeps working while an
update is waiting, so you can always reach this page after uploading a new version (or run
`php bin/migrate.php` on the server instead). The database user needs permission to alter tables for
updates to run from the web.

### Signing in

- A sign-in lasts 8 hours of inactivity and 30 days at most. "Sign out everywhere" ends every
  session for that account.
- After 5 wrong passwords in 15 minutes from one address, that address must wait out the 15 minutes.
  After 20 wrong passwords for one account from anywhere, the account pauses too, except from
  addresses where it has signed in before, so a stranger cannot lock the owner out.
  `php bin/user.php reset-password` lifts a pause straight away.
- "Forgot your password?" emails a link that works once, for 30 minutes; a newer link replaces older
  ones, and an account gets at most three an hour. It needs outgoing email and the cron (above).
  Asking for a link never says, or takes longer to say, whether the address has an account.
- The admin area needs `APP_URL` to start with `https://` (plain HTTP is allowed only for
  `localhost` and `*.test` while developing).
- Changes to a provider's UPI ID, payee name, notification email or WhatsApp number are recorded with
  their old and new values.

## How long slots are held

| Situation | Slot held for |
|---|---|
| Customer chose UPI and hasn't sent the UTR yet | 30 minutes |
| UTR sent, waiting for the teacher or an admin to verify | up to 24 hours |
| Customer chose to pay online (Razorpay) | 30 minutes |
| Free session that needs approval | up to 24 hours |

None of these runs past the start of the session. When a hold runs out, the slot is freed and the
customer is told not to pay. Staff can confirm or reject from the admin panel, Telegram or the links in
the emails.

## Online payments with Razorpay (Phase 7, optional)

Customers can pay online (card, any UPI app, netbanking or wallet) on Razorpay's page. ConsultDesk
makes a **payment link for each booking**, for its exact amount, when the customer books; there is
nothing to set up per session or per teacher in Razorpay. The booking confirms itself as soon as the
payment goes through.

> For now only **Test Mode** keys are accepted, so no real money moves.

### 1. Get your keys

1. Sign in to the [Razorpay Dashboard](https://dashboard.razorpay.com) and switch to **Test Mode**
   (top bar).
2. **Account & Settings → API Keys → Generate Test Key.** Keep the Key ID (`rzp_test_…`) and the Key
   Secret; Razorpay shows the secret only once.

### 2. Add them to ConsultDesk

**Either** put them in `.env` (or `config.php`), where they survive a database reset:

```
RAZORPAY_KEY_ID=rzp_test_…
RAZORPAY_KEY_SECRET=…
RAZORPAY_WEBHOOK_SECRET=…   # any 16+ characters; type the same into the Razorpay webhook (step 3)
```

The Payments page then shows them as "from the server's .env file". **Or** save them on the
Payments page as below. Keys saved there win over `.env`; removing them goes back to the `.env` keys.

In the admin panel, **Payments → Razorpay (organisation account)**: paste the Key ID and Key
Secret and **Save keys**. ConsultDesk stores the secret encrypted, then shows:

- the **webhook URL**, `https://<your-booking-site>/api/webhooks/razorpay`, and
- a **webhook secret**, shown only this once (make a new one any time with **New webhook secret**).

**Check connection** confirms Razorpay accepts the keys.

### 3. Add the webhook in Razorpay

**Account & Settings → Webhooks → Add new webhook** (still in Test Mode): paste the URL and the
secret, tick **payment_link.paid**, and save. (That is the only event ConsultDesk needs; others are
recorded and ignored.) Without the webhook, a booking still confirms when the customer comes back from
Razorpay, but not if they close the tab after paying, so do add it.

If the same Razorpay account already sends webhooks somewhere else (for example a WordPress site),
add this as a **second** webhook; leave the existing one as it is. ConsultDesk ignores events for
payments it didn't create, and only the `payment_link.*` events above are needed here.

Saving the **same** Key ID again (for example to update the secret) keeps the webhook secret; a
different account gets a new one. Keys can't be removed or swapped while customers still hold unpaid
links made with them (at most half an hour).

### 4. Turn it on for sessions

- **Payments → Ways to pay** switches UPI and Razorpay on or off for the whole site.
- Each paid session lists the ways it accepts (**Providers → Sessions → Edit**: "UPI" and "Razorpay
  payment link"). Customers see a way to pay only when it is switched on, ticked on the session and set
  up for that teacher (a UPI ID for UPI; Razorpay keys for Razorpay). Razorpay is offered only for
  sessions priced in INR that don't need approval (paying online confirms at once).
- Make a test booking and pay with one of Razorpay's
  [test cards](https://razorpay.com/docs/payments/payments/test-card-details/).

### A teacher's own Razorpay account

By default every online payment goes into the organisation's account. Under **Payments → Teachers
with their own Razorpay account**, pick a teacher and paste their keys: their sessions are then paid
into their own account. They add a webhook in their own Razorpay Dashboard with the same URL and the
secret ConsultDesk shows for them.

### What happens when…

- **The customer pays:** the booking is confirmed (by the webhook, or as soon as Razorpay sends the
  customer back, whichever comes first), with the usual emails, calendar event and alerts.
- **The hold runs out first (30 minutes):** the link closes 90 seconds before the hold ends and is
  cancelled at Razorpay, so it can't be paid late.
- **A payment arrives after the hold ended:** the booking is not confirmed (the slot may be taken);
  the customer and staff get an email, and the booking shows "refund it from the Razorpay
  Dashboard" with the payment id.
- **A paid booking is cancelled:** refund it from the Razorpay Dashboard; the booking page shows the
  payment id to look for.

On your own computer, Razorpay can't reach the webhook, but bookings still confirm when the customer
is sent back to the booking page.

## Putting booking on another site (Phase 5)

Add this where the "Book a session" button should appear (any HTML page, including WordPress in a
Custom HTML block):

```html
<script src="https://<your-booking-site>/embed.js" data-provider="<provider-slug>"
        data-service="<service-slug>" data-label="Book a session" async></script>
```

`data-service` is optional (without it the button opens the provider's page), and `data-provider` too
(without it, the list of everyone taking bookings). Or simply link to
`https://<your-booking-site>/p/<provider-slug>` from anywhere, e.g. a WhatsApp or Instagram bio.

If the other site sends a Content-Security-Policy, allow the booking site in `frame-src` and, for the
script, in `script-src`.
