# Deploying ConsultDesk on Hostinger

A step-by-step guide for shared hosting on Hostinger (hPanel). The example is
**book.atultiwari.com** in single-teacher mode; the VRL site (**book.vedantresearchlabs.com**,
several teachers) uses exactly the same steps with its own domain, database and mailbox (see
[A second site](#a-second-site)).

Allow about an hour the first time. Every step has a File Manager way; where SSH is quicker, both are
shown. Payments start in **Razorpay Test Mode** (no real money); [Part 4](#part-4-switching-razorpay-to-live-real-money)
switches to live payments once everything works.

**Contents**

- [Before you start](#before-you-start)
- [Part 1: Prepare hPanel](#part-1-prepare-hpanel)
- [Part 2: Upload and install](#part-2-upload-and-install)
- [Part 3: Connect payments, alerts and calendar (test mode)](#part-3-connect-payments-alerts-and-calendar-test-mode)
- [Part 4: Switching Razorpay to live (real money)](#part-4-switching-razorpay-to-live-real-money)
- [Part 5: Put "Book a session" on atultiwari.com](#part-5-put-book-a-session-on-atultiwaricom)
- [Updating to a new version](#updating-to-a-new-version)
- [Backups](#backups)
- [A second site](#a-second-site)
- [Troubleshooting](#troubleshooting)

---

## Before you start

You need:

- A Hostinger web hosting plan with **atultiwari.com** added to it, and access to **hPanel**.
- The release zip: on GitHub open **Releases**, pick the newest `ConsultDesk v…`, and download
  `consultdesk-<version>.zip`. (Maintainers make one by pushing a tag, e.g.
  `git tag v0.8.0 && git push origin v0.8.0`; the Release workflow builds and attaches the zip.)
- A Razorpay account with **Test Mode** keys (Dashboard → Account & Settings → API Keys → Generate
  Test Key).
- About 30 minutes of uninterrupted time for Parts 1–2.

The zip has two folders:

| Folder | Where it goes | What it is |
|---|---|---|
| `public/` | its **contents** go into the subdomain's web folder | the booking site, the installer, `.htaccess` |
| `consultdesk-app/` | **next to** `public_html`, never inside it | the code, your settings (`config.php`), uploads and backups |

Keeping `consultdesk-app` outside `public_html` means nobody can download your settings or
uploads, even if something is misconfigured.

### Do you have SSH?

SSH is included on Hostinger's Premium plans and above. To check: hPanel → **Advanced → SSH
Access**. If it shows an address, port and username, you can use SSH (the guide shows the commands).
If not, do everything through **File Manager**: nothing below needs SSH.

---

## Part 1: Prepare hPanel

### 1.1 Create the booking site as its own PHP website

ConsultDesk needs a **PHP** website. Make book.atultiwari.com a website of its own on your plan (not
a subdomain inside atultiwari.com's settings): then it has its own dashboard with **PHP
Configuration**, and it works whatever atultiwari.com itself runs on (for example a Node.js app,
whose dashboard has no PHP settings).

> **Finding things in hPanel:** open **Websites**, then the site's **Dashboard**, and use the menu
> on the left. Hostinger renames items from time to time: if a name in this guide doesn't match,
> type it into the **Search** box at the top of that menu.

1. If you already created `book` under **atultiwari.com → Domains → Subdomains**, delete it there
   first (red bin icon) so the name is free.
2. hPanel → **Websites → Add website** (or **Create or migrate a website**) → an **empty / custom
   PHP website** (not WordPress, AI Builder or Node.js), with the domain **book.atultiwari.com**.
3. Its folders:

   ```
   /home/u123456789/domains/book.atultiwari.com/public_html       ← web folder
   /home/u123456789/domains/book.atultiwari.com/consultdesk-app   ← the app goes here (Part 2)
   ```

   `u123456789` stands for your Hostinger account's username (it's shown in File Manager's paths
   and under SSH Access); use your own wherever this guide shows it. `consultdesk-app` sits next to
   `public_html`, never inside it.
4. Hostinger puts a placeholder page into a new website. Open **Files → File Manager**, go to
   `domains/book.atultiwari.com/public_html`, and delete everything inside it (e.g. `default.php`,
   `index.php`) so the folder is empty before you upload.

### 1.2 Turn on HTTPS

1. hPanel → **Security → SSL**.
2. Install the free SSL certificate for **book.atultiwari.com** if it isn't listed as active.
   It can take a few minutes to issue.
3. Turn on **Force HTTPS** for the subdomain if hPanel offers it. (ConsultDesk's `.htaccess` also
   redirects to https.)

The admin area and payments only work over https.

### 1.3 Set the PHP version and extensions

hPanel → **Websites → book.atultiwari.com → Dashboard** → search the menu for **PHP Configuration**
(it's under **Advanced**).

1. **PHP version** tab: **PHP 8.3** (8.2–8.5 all work; Hostinger no longer offers 8.1). Press
   **Update** if you changed it. The version applies to every PHP website on the plan.
2. **PHP extensions** tab. Hostinger's names differ a little from ConsultDesk's list:

   | ConsultDesk needs | Tick in hPanel | Usually |
   |---|---|---|
   | pdo_mysql | `pdo` and `nd_pdo_mysql` (with `mysqlnd`) | on |
   | intl | `intl` | on |
   | sodium | `sodium` | **off: tick it** |
   | gd (photos, logos) | `gd` | on |
   | zip (in-app updates) | `zip` | on |
   | mbstring, fileinfo | `mbstring`, `fileinfo` | on |
   | exif, curl, json, openssl | built-in (greyed out, always on) | on |

   Press **Save** at the bottom.
3. **PHP options** tab (optional, for restoring large backups and big photo uploads):
   `upload_max_filesize` 64M, `post_max_size` 64M, `memory_limit` 256M.

The installer checks these again and tells you exactly what's missing.

### 1.4 Create the database

1. hPanel → **Databases → MySQL Databases** (or **Management**).
2. Create a new database, for example name `consultdesk`, user `consultdesk`, and a strong password
   (use the generator; store it in your password manager).
3. Hostinger prefixes both with your account, e.g. `u123456789_consultdesk`. Note the **full**
   database name, the **full** user name and the password. The host is `localhost`.

### 1.5 Email: one mailbox to send, any address to receive

ConsultDesk **sends** booking emails by logging in to a mailbox, so it needs a real mailbox with a
password. An **alias** or forwarder (like `contact@atultiwari.com`) has no password of its own, so
it can't be used to send, but it's perfect for **receiving**.

1. hPanel → **Emails** for atultiwari.com → create a mailbox **`bookings@atultiwari.com`** with its
   own strong password. ConsultDesk sends from it (keeping your personal mailbox's password out of
   the server's settings). Its sending settings are:
   - SMTP server `smtp.hostinger.com`, port `465` with **SSL** (or `587` with TLS)
   - login: `bookings@atultiwari.com` and its password
2. Your alias **`contact@atultiwari.com`** is where things should **arrive**: in the setup wizard
   (2.5) put it as the teacher's **Email for new bookings**. New bookings and payments to verify
   are sent there, and when customers press Reply on their booking emails, the reply goes there too.
3. Make sure **SPF and DKIM** are set for atultiwari.com (Emails → the domain → DNS settings, which
   offers a one-click fix). Without them, booking emails are more likely to land in spam.

---

## Part 2: Upload and install

### 2.1 Upload the files

**With File Manager**

1. hPanel → **Files → File Manager**, open `domains/book.atultiwari.com/`.
2. Upload `consultdesk-<version>.zip` there and **Extract** it. You get a folder
   `consultdesk-<version>/` containing `public/` and `consultdesk-app/`.
3. Move `consultdesk-<version>/consultdesk-app` up one level, so it sits at
   `domains/book.atultiwari.com/consultdesk-app` (next to `public_html`).
4. Open `consultdesk-<version>/public`, select **everything inside it** (including the hidden
   `.htaccess`; turn on "Show hidden files" in File Manager's settings if you don't see it) and move
   it into `public_html`.
5. Delete the now-empty `consultdesk-<version>` folder and the zip.

**With SSH** (same result)

```bash
cd ~/domains/book.atultiwari.com
unzip consultdesk-<version>.zip            # after uploading the zip here
mv consultdesk-<version>/consultdesk-app .
cp -a consultdesk-<version>/public/. public_html/
rm -rf consultdesk-<version> consultdesk-<version>.zip
```

### 2.2 Check permissions

Folders should be `755` and files `644` (File Manager → right-click → **Permissions**). The installer
needs to write into `consultdesk-app/` and `consultdesk-app/storage/`. Hostinger sets these
correctly by default; only change them if the installer says it can't write.

### 2.3 Run the installer

1. Open **https://book.atultiwari.com/install** in your browser.
2. If it lists problems (PHP version, an extension, permissions), fix them in hPanel and reload.
3. **Setup code**: the installer has just written a file
   `domains/book.atultiwari.com/consultdesk-app/install-code.txt`. Open it in File Manager (or
   `cat ~/domains/book.atultiwari.com/consultdesk-app/install-code.txt` over SSH) and copy the code into
   the form. This proves you control the server, so nobody else can install your site first.
4. Fill in:

   | Field | What to enter |
   |---|---|
   | Booking site address | `https://book.atultiwari.com` (pre-filled) |
   | Secret admin path | keep the suggested one (e.g. `desk-7k2m9xq4ab`) or choose your own hard-to-guess one. **Write it down**: your admin area will be `https://book.atultiwari.com/<path>` and there is no link to it anywhere. |
   | Booking codes start with | e.g. `AT` (atultiwari.com) or `VRL` (VRL). Optional; changeable later. |
   | Websites that will embed the booking pages | `https://atultiwari.com` and `https://www.atultiwari.com` (one per line) if you'll use the "Book a session" pop-up there (Part 5). |
   | Database name / user / password | from step 1.4 (the full `u123456789_…` names); host `localhost`, port `3306` |
   | Send emails from / Mailbox / password | `bookings@atultiwari.com` and its password (the mailbox from 1.5, not the alias); SMTP server `smtp.hostinger.com`, port `465`, SSL |

5. Press **Install**. It tests the database, generates the secret keys, writes
   `consultdesk-app/config.php` (readable only by your account), creates the tables, and shows you:
   - your admin address,
   - the **cron command** and a **cron web address** for the next step.

   Keep that page open (or copy both). The installer switches itself off afterwards.

### 2.4 Add the cron job (every minute)

Cron sends the emails and Telegram alerts, frees unpaid slots after their hold, and syncs Google
Calendar. ConsultDesk shows a warning in **System** if it isn't running.

1. hPanel → **Advanced → Cron Jobs**.
2. Choose **Custom** (or PHP), set it to run **every minute** (`* * * * *`).
3. Command, using the path the installer showed:

   ```
   /usr/bin/php /home/u123456789/domains/book.atultiwari.com/consultdesk-app/bin/cron.php
   ```

   If hPanel asks only for the script path, give it
   `domains/book.atultiwari.com/consultdesk-app/bin/cron.php`.

4. Save. Within two minutes, **System** in the admin area should say "Scheduled tasks: Running".

   If your plan doesn't allow every minute, use the shortest interval it offers (e.g. every 5
   minutes): everything still works, just with alerts and hold expiries up to that much later.

If the PHP command doesn't run on your plan, use the **cron web address** the installer showed
instead (it contains the secret `CRON_KEY`; keep it private):

```
curl -fsS "https://book.atultiwari.com/api/cron?key=<CRON_KEY>" > /dev/null
```

> SSH users can check PHP on the command line with `php -v`; it must be 8.1+. If it's older, use the
> full path of a newer PHP, e.g. `/opt/alt/php82/usr/bin/php`, in the cron command.

### 2.5 Create the owner account and set up the site

1. Open your admin address: `https://book.atultiwari.com/<secret path>`.
2. **Create your owner account**: your name, email and a strong password. You're signed in.
3. **Set up your site** opens:
   - **Who will people book with?** For atultiwari.com choose **Just me** (for VRL: **Several
     teachers**).
   - **Your details**: your profile, the email for new bookings, WhatsApp (pick the country), and
     your UPI ID and payee name for UPI payments.
   - **Starter sessions**: tick the ones you want, edit title, length and price, or skip.
4. **Branding**: site name (e.g. "Dr. Atul Tiwari"), colour preset, and logo.
5. **My profile → Weekly hours** and **Booking rules**: check your hours, notice period and buffers.
6. Open **https://book.atultiwari.com** in a private window: your sessions should be listed.

### 2.6 First test booking (UPI)

1. In a private window, book a free session and a paid one with **UPI**.
2. Check the emails arrive (to the customer address you used and to your notification address).
3. In the admin **Dashboard**, verify the test UTR and confirm; the customer gets the confirmation.
4. Cancel the test bookings afterwards (Bookings → open → Cancel).

---

## Part 3: Connect payments, alerts and calendar (test mode)

### 3.1 Razorpay in Test Mode

1. Razorpay Dashboard → switch to **Test Mode** (toggle at the top).
2. **Account & Settings → API Keys → Generate Test Key**. Copy the **Key ID** (`rzp_test_…`) and
   the **Key Secret** (shown once).
3. Add them to ConsultDesk, either way:
   - **Recommended:** File Manager → `consultdesk-app/config.php` → **Edit**, and add these lines
     inside the `return [ … ];` list (keys from config survive a database restore):

     ```php
     'RAZORPAY_KEY_ID' => 'rzp_test_xxxxxxxxxxxxxx',
     'RAZORPAY_KEY_SECRET' => 'xxxxxxxxxxxxxxxxxxxxxxxx',
     'RAZORPAY_WEBHOOK_SECRET' => 'choose-a-long-random-secret-of-32-characters',
     ```

     For the webhook secret, make up a long random string (e.g. from your password manager).
   - Or in the admin area: **Payments → Razorpay (organisation account)** → paste the keys →
     **Save keys**. It then shows a webhook secret once: copy it for step 4.
4. **Webhook** (so a booking confirms even if the customer closes the tab after paying):
   Razorpay Dashboard (still **Test Mode**) → **Account & Settings → Webhooks → Add New Webhook**:
   - URL: `https://book.atultiwari.com/api/webhooks/razorpay`
   - Secret: the `RAZORPAY_WEBHOOK_SECRET` from step 3 (or the one the Payments page showed)
   - Active events: tick **payment_link.paid**
   - Save. If the same Razorpay account already has a webhook for another website, add this as a
     second one and leave the other alone.
5. Admin → **Payments**: it should show "Test mode" and the Key ID. Press **Check connection**.
6. Still on Payments: make sure **Online with Razorpay** is ticked under "Ways to pay", and press
   **Offer online payment on every paid session** (or tick "Razorpay payment link" per session).

**Test it:** in a private window book a paid session, choose **Pay online**, and on Razorpay's page
pay with a test method: UPI ID `success@razorpay`, or one of the test cards in Razorpay's docs
(search "Razorpay test card details"). You land back on the booking page showing **Confirmed**, and
the confirmation email arrives. In Razorpay (Test Mode) → **Payment Links**, the link shows as paid.

### 3.2 Telegram alerts (optional)

Create the bot with @BotFather as in [INSTALL.md → Telegram bot](INSTALL.md#telegram-bot-phase-3-optional),
then add to `config.php`:

```php
'TELEGRAM_BOT_TOKEN' => '123456789:…',
'TELEGRAM_WEBHOOK_SECRET' => 'another-long-random-secret-of-32-plus-characters',
'TELEGRAM_BOT_USERNAME' => 'your_bot',
```

Connect the webhook, either way:

- **SSH:** `php ~/domains/book.atultiwari.com/consultdesk-app/bin/telegram.php set-webhook`
- **No SSH:** open this address once in your browser (replace both values; the secret is the
  `TELEGRAM_WEBHOOK_SECRET` above):

  ```
  https://api.telegram.org/bot<TELEGRAM_BOT_TOKEN>/setWebhook?url=https://book.atultiwari.com/api/webhooks/telegram&secret_token=<TELEGRAM_WEBHOOK_SECRET>
  ```

  Telegram answers `"ok":true`.

Then in the admin area: **My profile → Connections → Telegram → Connect**, and press Start in
Telegram. Make a test booking: the alert arrives with Confirm/Reject buttons.

### 3.3 Google Calendar (optional)

Follow [INSTALL.md → Google Calendar](INSTALL.md#google-calendar-phase-4-optional), using the
redirect URI `https://book.atultiwari.com/api/google/callback`, and add `GOOGLE_CLIENT_ID` and
`GOOGLE_CLIENT_SECRET` to `config.php`. Then **My profile → Connections → Google Calendar → Connect**.

### 3.4 Test checklist before going live

- [ ] Free booking: confirmed at once (or after your approval if the session needs it), emails arrive.
- [ ] UPI booking: UTR submitted, verified from the dashboard and from Telegram.
- [ ] Razorpay test payment: confirmed automatically; also try closing the tab right after paying
      (the webhook confirms it within a minute).
- [ ] A hold that isn't paid expires after 30 minutes and the slot is free again.
- [ ] Coupon: a test code lowers the price; a 100% code makes it free.
- [ ] "My bookings": the emailed sign-in link works.
- [ ] System page: cron running, no failed jobs, no pending database updates.
- [ ] Backup: System → Backups → Download backup works. Keep that file and `config.php`.

---

## Part 4: Switching Razorpay to live (real money)

Do this only when every item above works in Test Mode.

1. **Activate your Razorpay account** for live payments: Razorpay Dashboard → complete KYC/business
   details until the account shows as activated.
2. Switch the Razorpay Dashboard to **Live Mode** → **Account & Settings → API Keys → Generate Live
   Key**. Copy the **Key ID** (`rzp_live_…`) and **Key Secret**.
3. **Live webhook** (webhooks are separate for Test and Live mode): in **Live Mode** → **Webhooks →
   Add New Webhook**: the same URL `https://book.atultiwari.com/api/webhooks/razorpay`, a **new**
   long random secret, event **payment_link.paid**.
4. Wait until no customer is in the middle of a Razorpay test payment (holds last 30 minutes), then
   edit `consultdesk-app/config.php`:

   ```php
   'RAZORPAY_KEY_ID' => 'rzp_live_xxxxxxxxxxxxxx',
   'RAZORPAY_KEY_SECRET' => 'live key secret',
   'RAZORPAY_WEBHOOK_SECRET' => 'the new live webhook secret',
   'PAYMENTS_LIVE' => '1',
   ```

   `PAYMENTS_LIVE` is the safety switch: without it ConsultDesk refuses live keys, so real money can
   never be switched on by accident or from the browser. (If you saved keys on the Payments page
   instead, remove them there first, or save the live keys there after adding `PAYMENTS_LIVE`.)
5. Admin → **Payments**: it now shows **Live** and the `rzp_live_` Key ID. Press **Check connection**.
6. **One real test with a few rupees:** admin → **Coupons → New coupon**, ₹ off, an amount that
   leaves ₹1–₹5 of your cheapest paid session (e.g. ₹495 off a ₹499 session), limited to 1 use. Book
   that session with the coupon, choose **Pay online**, pay with your own card or UPI, and check the
   booking confirms. Then **refund** it in Razorpay → **Transactions → Payments → Refund**, and
   delete the coupon.
7. Keep the Test Mode keys somewhere safe in case you want to test again later.

To go back to test mode: put the `rzp_test_` keys and test webhook secret back and remove the
`PAYMENTS_LIVE` line.

> UPI with manual UTR doesn't involve Razorpay: it pays straight to your UPI ID and works the same in
> test and live.

---

## Part 5: Put "Book a session" on atultiwari.com

Two ways (see [INSTALL.md → Putting booking on another site](INSTALL.md#putting-booking-on-another-site-phase-5)):

- **A link:** point a button to `https://book.atultiwari.com` (or a session:
  `https://book.atultiwari.com/p/<your-slug>/<session-slug>`). Nothing else to configure.
- **The pop-up:** add to atultiwari.com's HTML:

  ```html
  <script src="https://book.atultiwari.com/embed.js" data-provider="<your-slug>" defer></script>
  ```

  For the pop-up to load, book.atultiwari.com must allow atultiwari.com to frame it: you entered it
  in the installer, or edit `public_html/.htaccess` and add it to the `frame-ancestors` list,
  e.g. `frame-ancestors 'self' https://atultiwari.com https://www.atultiwari.com;`. If atultiwari.com
  has its own Content-Security-Policy, add `https://book.atultiwari.com` to its `script-src` and
  `frame-src`.

---

## Updating to a new version

### From the admin area (0.9.0 and later)

Like WordPress, ConsultDesk updates itself:

1. Admin → **System → Updates**. It checks GitHub once a day (the Dashboard shows "ConsultDesk x.y.z
   is available" when there's something new); press **Check for updates** to ask now.
2. Read the release notes, type your password and press **Update to x.y.z**. It:
   - saves a database backup to `consultdesk-app/storage/backups/` (`consultdesk-before-update-…`),
   - downloads the release from GitHub and checks its **signature**: only zips signed with
     ConsultDesk's release key are installed, so a tampered or fake download is refused,
   - swaps in the new code and web files, keeping `config.php`, your uploads, backups and the embed
     list in `.htaccess`,
   - runs any database updates.
   If something fails while swapping the code, the previous code is put back automatically. The
   previous code also stays in `storage/updates/previous-<version>` until the next update.
3. Press **Reload**. Done.

Over SSH you can do the same with `php ~/domains/book.atultiwari.com/consultdesk-app/bin/update.php`
(`check`, or `apply`).

**Pre-releases:** updates offer only stable releases. To also get pre-releases (for testing), add
`'UPDATE_CHANNEL' => 'beta',` to `config.php`.

**Needs:** the `zip` PHP extension (hPanel → PHP Configuration → extensions) and write permission on
`consultdesk-app` and the web folder (Hostinger's defaults are fine). If something's missing, the
Updates panel says what.

### By hand (and once, from 0.8.0 to 0.9.0)

0.8.0 doesn't have the updater yet, so move to 0.9.0 by hand once; after that, use the button.

1. **Back up** first: admin → **System → Backups → Download backup**, and download
   `consultdesk-app/config.php`.
2. Download the new release zip and extract it somewhere (e.g. in `domains/book.atultiwari.com/`).
3. Replace the app code, keeping your settings and uploads:
   - In `consultdesk-app/` replace `src/`, `vendor/`, `migrations/`, `bin/` and `http.php` with the
     new ones. **Do not** touch `config.php` or `storage/`.
   - With SSH:

     ```bash
     cd ~/domains/book.atultiwari.com
     unzip consultdesk-<new>.zip
     rsync -a --delete --exclude config.php --exclude storage --exclude .installed consultdesk-<new>/consultdesk-app/ consultdesk-app/
     ```
4. Replace the web files: copy the new `public/` contents into `public_html`, but **keep your
   `.htaccess`** if you added embed sites to it (or copy the `frame-ancestors` line into the new
   one). The old `assets/` folder can be deleted first; the new one replaces it.
5. Admin → **System**: if it lists database updates, press **Run database updates**.
6. Open the booking site and the admin area to check, then delete the extracted release folder.

## Backups

- **ConsultDesk:** admin → **System → Backups → Download backup** (asks for your password). It holds
  every booking, teacher, session and setting. Saved secrets inside it stay encrypted with your
  `APP_KEY`, so **keep a copy of `config.php`** with your backups; without it a backup can't be
  restored. Restore from the same page (password + typing `RESTORE`), or over SSH with
  `php consultdesk-app/bin/restore.php <file>`.
- **Hostinger** also keeps automatic backups (hPanel → **Files → Backups**) of files and databases.
- Do a ConsultDesk backup before every update and once a month.

---

## A second site

For **book.vedantresearchlabs.com** (VRL, several teachers), repeat everything with VRL's own:

- a new PHP website **book.vedantresearchlabs.com** (web folder
  `domains/book.vedantresearchlabs.com/public_html`),
- app folder `domains/book.vedantresearchlabs.com/consultdesk-app` (each site has its own),
- database, mailbox (e.g. `bookings@vedantresearchlabs.com`), installer run, cron job,
- Razorpay webhook (the same Razorpay account can serve both sites: add one webhook per site), and
  Telegram bot (a bot can only point at one site, so create a second bot for VRL).

In the setup wizard choose **Several teachers**, and set the booking codes to `VRL`. Add teachers
under **Providers**, and invite their logins under **Users**.

---

## Troubleshooting

| What you see | What to do |
|---|---|
| `/install` says the `consultdesk-app` folder wasn't found | It must be next to `public_html` (e.g. `domains/book.atultiwari.com/consultdesk-app`), not inside it, and contain `http.php` and `vendor/`. |
| A blank page or "500 Internal Server Error" | The website's **Advanced → PHP Configuration**: PHP 8.2+ and the extensions in 1.3 (especially `sodium`). The error log is in hPanel → **Advanced → Error Logs** (or `public_html/error_log`). |
| The site loads but every page says "This site is not configured yet" | `consultdesk-app/config.php` is missing or has an error; check the error log for "configuration error". |
| Booking pages show 404 for every path except the home page | `.htaccess` wasn't copied into `public_html` (File Manager hides dotfiles until you turn on "Show hidden files"). |
| Emails don't arrive | Check System → "Emails, calendar and alerts" for failed jobs and the last error; check the mailbox password and port 465/SSL; set SPF/DKIM (1.5). |
| System says cron isn't running | Check the cron job's command path and that it runs every minute; try the cron web address instead. |
| Razorpay page opens but the booking stays "Awaiting payment" | The webhook URL or secret doesn't match (Test vs Live mode have separate webhooks). The return from Razorpay also confirms it, so this mostly shows when the tab was closed. |
| "Only Test Mode keys can be used" on the Payments page | Add `'PAYMENTS_LIVE' => '1'` to `config.php` (Part 4). |
| Admin path forgotten | It's `ADMIN_PATH` in `consultdesk-app/config.php`. |
| Forgot the owner password | Use "Forgot your password?" on the sign-in page (email), or over SSH: `php consultdesk-app/bin/user.php reset-password --email=you@example.com`. |
