# Changelog

All notable changes to FormForge. The project was built in reviewable increments — each entry maps to one or more commits on `main`.

## 1.0.0 — Live release (2026-08-05)

### Deployed
- **Web app live** on Render free tier (Docker, Postgres 16, sync queue) — `feat(deploy)`, `fix(deploy): Faker is a runtime dependency`
- **AI service live** on Vercel (Python serverless, 60s budget) — `fix(deploy): Vercel legacy routes preserve the original request path`, friendly root route
- Driver-aware Docker entrypoint (MySQL *and* Postgres, `DB_URL` support, PaaS URL adoption, trusted proxies); one-click `render.yaml` blueprint

### Fixed (found by real-browser verification against production)
- Sync-queue UX: AI-create now redirects and the import preview renders immediately when jobs complete inline — `fix(ux)`
- Import commit button: renamed the action off Livewire's reserved JS `commit` name and keyed the wizard's conditional steps so the parsing poller can't outlive its step — `fix(import)`
- Header-slot buttons (`+ New form`, `Export CSV`) rewired via global Livewire events (the layout renders that slot outside the component root) — `fix(ui)`
- Import keys from numbered/non-Latin labels normalised to the contract; import wizard scoped to the owning user — `fix(schema)`
- Livewire assets published as static files (bare `php -S` cannot route the virtual asset path) — `fix(web)`
- FormForge branding (logo mark, landing page) replacing scaffold defaults

## 0.9.0 — Quality & operations

- **Test suite**: 74 Pest tests / 254 assertions + 22 pytest — schema engine, lifecycle, public forms, builder, AI flow (faked service), import parsers pinned to committed samples, exports. Four real bugs caught and fixed by the suite — `test:`
- **Docker stack**: 3-stage web image, compose with MySQL/Redis/AI/web/Horizon/scheduler; GitHub Actions CI for both suites; Horizon dashboard gated — `feat(ops+docs)`
- Documentation set: README, DECISIONS, schema contract, exported OpenAPI spec

## 0.5.0 — Parts B, C & D feature complete

- **Word/Excel import** (Part C): deterministic parsers (headings→sections, lists→options, tables, required markers; structured + plain-header Excel layouts with sample-data inference), AI assist scoped to low-confidence fields only, preview & mapping screen, defensive error reporting, committed sample files — `feat(import)`
- **AI integration** (Part B): queued generate/edit/translate with status polling, per-user quotas, prompt-log telemetry, builder AI panel — `feat(ai)`
- **AI service**: FastAPI + Groq with the deterministic-repair → validate → feed-errors-back retry loop, model fallback, TPM rate-limit recovery, per-attempt telemetry — `feat(ai-service)`
- **Part D**: version history with per-version field diffs and rollback-as-new-version, nightly analytics rollups with event pruning, QR share — `feat(part-d)`

## 0.2.0 — Part A core

- Drag-and-drop builder (19 field types, sections, inline settings, options & conditional-logic editors, autosave, undo/redo, two-way JSON editor, publish)
- Public forms rendered purely from the schema: server-side validation compiled per request, client logic mirror, signature pad, honeypot + minimum-fill-time, funnel beacons
- Submissions (search, version-pinned detail, delete), queued CSV export, analytics dashboard — `feat(app)`

## 0.1.0 — Foundations

- Monorepo scaffold: Laravel 11 + Livewire 3 + Breeze (Pest) + Tailwind; Horizon, PhpWord, PhpSpreadsheet — `chore(web)`
- Domain schema & the SSOT engine: immutable `form_versions`, EAV answers, analytics stream/rollups, AI telemetry tables; sanitizer → validator → rule compiler → condition evaluator pipeline; `FormService` single write path; demo seeder — `feat(schema)`
