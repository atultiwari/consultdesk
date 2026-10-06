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

If a host can only call a URL, use `https://<your-site>/api/cron?key=<CRON_KEY>` instead. The CLI form
is preferred because the key never appears in access logs.
