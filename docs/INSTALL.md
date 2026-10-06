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
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MySQL / MariaDB database |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USER`, `SMTP_PASSWORD` | Outgoing mail (see below) |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender address and name on booking emails |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_BOT_USERNAME` | Optional Telegram bot (see below). Leave the token empty to turn Telegram off. |
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

Each command prints a `https://t.me/<bot>?start=...` link, valid once for 24 hours. Open it on the
phone that should receive alerts and tap **Start**. Sending `/stop` to the bot unlinks that chat.
