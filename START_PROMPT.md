# Kick-off prompt for a new Claude Code session

Open a new Claude Code conversation with the working directory set to
`/Users/atultiwari/Projects/domains/consultdesk`, then paste everything inside the box below.

---

```text
You're building ConsultDesk, a self-hosted consultation-booking app. Doctors, teachers and mentors install it on ordinary shared hosting (PHP 8.1+ and MySQL/MariaDB, e.g. Hostinger), usually on a subdomain such as book.atultiwari.com or book.vedantresearchlabs.com.

The full, approved plan is in docs/PLAN.md. Read it end to end before you do anything else. It's the source of truth for scope, stack, data model, flows, design direction, security and phases. If something in it looks wrong or underspecified, stop and ask me; don't silently deviate. If we change a decision, update docs/PLAN.md in the same PR.

Non-negotiables (the reasons are in the plan):
- Shared-hosting-safe. Runtime is PHP 8.1+ (Slim 4) and MySQL only. vendor/ is bundled in the release zip. No Node, SSH or Composer is needed on the server, and setup is a web installer plus one hPanel cron entry.
- React 19 + TypeScript + Vite frontend, built to static files and served by the same host.
- Multiple providers (teachers/doctors) per installation, each with their own Google Calendar, availability, services, UPI details and Telegram chat. Roles: owner, admin, provider.
- Payments come through a pluggable PaymentProvider interface:
  (1) manual UPI. The customer submits a UTR, with an optional WhatsApp screenshot deep link. A human confirms through the Telegram bot buttons or the admin panel.
  (2) Razorpay Payment Links API on Vedant Research Labs' account: create the link server-side, redirect to rzp.io, return to our status page, and treat the webhook as the source of truth.
- The admin panel lives at a secret slug and uses password login (argon2id, httpOnly cookie session, CSRF, rate limiting).
- The UI must look distinctive and polished, not generic AI-template output. It is themeable through tokens: an atultiwari H&E preset and a VRL preset. It must be responsive at 375, 768 and 1280 px, support light and dark themes, meet WCAG AA, and honour reduced motion. Use the frontend-design / motion-design skills before building UI.
- TDD throughout (RED → GREEN → refactor), with 80% minimum coverage and at least 90% on the domain core (SlotEngine, booking state machine, concurrency-safe hold()).
- Secrets go only in config.php or env, never in git. Tokens are encrypted at rest with sodium. Webhooks must have their HMAC verified and be idempotent.

How to work:
1. Start with Phase 0 (Bootstrap) from docs/PLAN.md §9:
   - git init on branch main, with .gitignore and .editorconfig
   - api/ skeleton (composer.json with Slim 4, PHPUnit, PHPStan, PHP-CS-Fixer)
   - web/ skeleton (Vite + React + TS, Vitest, ESLint, Prettier)
   - e2e/ (Playwright)
   - a docker-compose.yml for LOCAL DEV ONLY (PHP 8.1 + Apache, MariaDB 10.6, Mailpit)
   - GitHub Actions ci.yml
   - a README.md with dev commands
   Then ask me before creating the GitHub repo atultiwari/consultdesk and pushing.
2. After that, do one phase per branch and PR, in the order given in §9. At the start of each phase, write a short task list. When the phase ends:
   - run the full test and lint suite
   - use the code-reviewer agent, plus the security-reviewer agent after phases 2, 6 and 7
   - open the PR with a summary and test plan
   - STOP and wait for my review before starting the next phase.
3. Use conventional commits (feat:, fix:, chore:, …).
4. Never use real payment keys, real UPI IDs or my real phone number in code, tests or fixtures. Use obvious placeholders, plus Razorpay test-mode keys that I'll provide when we reach phase 7.
5. Things that need me (Google Cloud OAuth client, Telegram bot token from @BotFather, Razorpay test keys, the Hostinger subdomain): when a phase needs one, give me exact step-by-step instructions, and put them in docs/INSTALL.md as well.

Begin by reading docs/PLAN.md. Then give me a 5–10 line summary of your understanding and the Phase 0 task list, and start Phase 0.
```
