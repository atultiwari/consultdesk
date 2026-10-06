# ConsultDesk

ConsultDesk is a self-hosted app for booking consultations with doctors, teachers and mentors. It's built to run on ordinary shared hosting with just PHP and MySQL. Each provider's calendar syncs with Google Calendar, and one installation can hold several providers. Bookings can be paid by manual UPI, confirmed through a Telegram bot or the admin panel, or through Razorpay Payment Links. A secret admin panel manages availability, blocked times, services and rules.

**Status:** Phase 1 (domain core) done: schema and migrator, slot engine, booking state machine and a concurrency-safe `hold()`. No HTTP endpoints or UI yet.

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

## Local development

Prerequisites: Docker Desktop, Node 22+, and (optionally) PHP 8.1+ with Composer on the host for fast unit runs.

```bash
cp .env.example .env          # throwaway local-dev values
docker compose up -d --build  # API on :8080, MariaDB on :3307, Mailpit UI on :8025
docker compose exec api composer install
```

Then start the frontend (proxies `/api` to `:8080`):

```bash
cd web && npm install && npm run dev   # http://localhost:5173
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

```bash
cd e2e && npm install && npx playwright install chromium
npm test   # starts the Vite dev server automatically; set E2E_BASE_URL to test another host
```

## Secrets

Never commit secrets. Runtime secrets live in `config.php` (written by the installer) or environment variables; both are git-ignored. `.env.example` holds only throwaway local-dev placeholders.
