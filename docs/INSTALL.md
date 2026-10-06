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

Create the first account from the server's shell (the installer will do this in Phase 8):

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

### Who can do what

- **Owner** and **admin**: every provider and booking, adding providers, closing the whole
  organisation for a holiday. (Owners also get Users, Branding and Payments in Phase 6b.)
- **Provider**: their own profile, UPI details, booking rules, sessions, weekly hours, blocked times
  and bookings, and nothing else.

### Signing in

- A sign-in lasts 8 hours of inactivity and 30 days at most. "Sign out everywhere" ends every
  session for that account.
- After 5 wrong passwords in 15 minutes, from one address or for one account, sign-in pauses for
  that address or account.
- "Forgot your password?" emails a link that works once, for 30 minutes. It needs outgoing email and
  the cron (above) to be set up. Asking for a link never says whether the address has an account.

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
