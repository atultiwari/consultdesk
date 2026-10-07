# ConsultDesk

ConsultDesk is a self-hosted app for booking consultations with doctors, teachers and mentors. It's built to run on ordinary shared hosting with just PHP and MySQL. Each provider's calendar syncs with Google Calendar, and one installation can hold several providers. Bookings can be paid by manual UPI, confirmed through a Telegram bot or the admin panel, or through Razorpay Payment Links. A secret admin panel manages availability, blocked times, services and rules.

**Status:** Phase 8: release zip, web installer and the Hostinger deployment guide. Built on the earlier phases: booking API, email and cron, Telegram, Google Calendar, the public booking site, the admin panel (with setup wizard, coupons, backups) and Razorpay Payment Links (test mode, with a switch for live payments).

- **Deploy on Hostinger (step by step): [docs/DEPLOY-HOSTINGER.md](docs/DEPLOY-HOSTINGER.md)**
- Configuration and features: [docs/INSTALL.md](docs/INSTALL.md)
- Build plan: [docs/PLAN.md](docs/PLAN.md)
- Kick-off prompt for a new Claude Code session: [START_PROMPT.md](START_PROMPT.md)

## Repository layout

| Path | What it is |
|---|---|
| `api/` | PHP 8.1+ API (Slim 4). PHPUnit, PHPStan (level 8), PHP-CS-Fixer (PER-CS 2.0) |
| `web/` | React 19 + TypeScript + Vite frontend. Vitest, ESLint, Prettier |
| `e2e/` | Playwright end-to-end tests at 375 / 768 / 1280 px |
| `docker/`, `docker-compose.yml` | **Local development only**: PHP 8.1 + Apache, MariaDB 10.6, Mailpit |
| `.github/workflows/ci.yml` | Lint, static analysis, tests with coverage gate, build, E2E |
| `release/`, `.github/workflows/release.yml` | The release zip: `release/build.sh` builds it; pushing a `v*` tag publishes it |

## Local development

Prerequisites: Docker Desktop, Node 22+, and (optionally) PHP 8.1+ with Composer on the host for fast unit runs.

```bash
cp .env.example .env          # throwaway local-dev values
php -r 'echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;' >> .env   # local encryption key
docker compose up -d --build  # API on :8080, MariaDB on :3307, Mailpit UI on :8025
docker compose exec api composer install
```

Create the schema and some placeholder data (a `demo` provider with the §8 medical starter sessions; a real install uses the setup wizard instead):

```bash
docker compose exec api composer migrate
docker compose exec api php bin/seed-dev.php
```

Try it: `curl http://localhost:8080/api/providers/demo`. Emails land in Mailpit at http://localhost:8025 after the cron runs:

```bash
docker compose exec api php bin/cron.php
```

Then start the frontend (proxies `/api` to `:8080`):

```bash
cd web && npm install && npm run dev   # http://localhost:5173
```

The admin panel is at http://localhost:5173/desk-local-dev (set `ADMIN_PATH` in `.env` to change
it). Create yourself a local account first; it asks for a password:

```bash
docker compose exec api php bin/user.php create --email=owner@example.test --role=owner
```

### API (`api/`)

Run inside the container (PHP 8.1, pcov for coverage) with `docker compose exec api <cmd>`, or on the host:

| Command | Does |
|---|---|
| `composer test` | All PHPUnit suites. Integration tests need `DB_HOST`, `DB_TEST_NAME`, `DB_USER`, `DB_PASSWORD` (set in the container) and are skipped without them |
| `composer test:unit` | Unit suite only |
| `composer test:coverage` | Tests + text and Clover coverage (needs pcov — use the container) |
| `composer coverage:check` | Fails if line coverage < 80 % overall or < 90 % in `src/Domain/` |
| `composer migrate` / `composer migrate:status` | Apply / list pending migrations (reads `DB_*` env vars) |
| `php bin/cron.php` | Expire lapsed holds, send queued emails, prune rate limits (run every minute in production) |
| `php bin/seed-dev.php` | Local only: demo provider, weekly hours and service templates |
| `php bin/telegram.php info \| set-webhook \| link provider <slug> \| link user <email>` | Telegram bot setup (see docs/INSTALL.md) |
| `php bin/google.php connect \| status \| calendars \| set-calendars \| disconnect <slug>` | Google Calendar per provider (see docs/INSTALL.md) |
| `composer stan` | PHPStan level 8 |
| `composer cs` / `composer cs:fix` | Check / fix code style |

### Web (`web/`)

| Command | Does |
|---|---|
| `npm run dev` | Vite dev server |
| `npm test` / `npm run test:coverage` | Vitest (80 % thresholds enforced) |
| `npm run lint` | ESLint, zero warnings allowed |
| `npm run format` / `npm run format:check` | Prettier |
| `npm run typecheck` | TypeScript |
| `npm run build` | Production build to `web/dist` |

### E2E (`e2e/`)

Runs against the local stack (API in docker, seeded with `seed-dev.php`):

```bash
cd e2e && npm install && npx playwright install chromium
npm test   # starts the Vite dev server automatically; set E2E_BASE_URL to test another host
```

The Telegram-confirmation step runs when the API has Telegram settings and the test knows the secret:
put `TELEGRAM_BOT_TOKEN` (any `123456789:` + 35 characters) and `TELEGRAM_WEBHOOK_SECRET` in `.env`,
`docker compose up -d api`, then `TELEGRAM_WEBHOOK_SECRET=<same> npm test`.

Design QA helpers (need the dev server running):

| Command | Does |
|---|---|
| `npx tsx scripts/screenshots.ts` | Every page at 375 / 768 / 1280 px, light and dark → `e2e/screenshots/` |
| `npx tsx scripts/contrast.ts` | WCAG AA check of every text/background token pair, all presets and themes |

## Secrets

Never commit secrets. Runtime secrets live in `config.php` (written by the installer) or environment variables; both are git-ignored. `.env.example` holds only throwaway local-dev placeholders.
