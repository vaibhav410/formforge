# Deployment Guide

Three supported topologies, from laptop to cloud. The **live demo** runs topology C.

| | Topology | Queue | DB | Redis |
|---|---|---|---|---|
| A | Local dev (bare) | `queue:work` | MySQL (docker) | Redis (docker) |
| B | **docker-compose (full fidelity)** | **Horizon** | MySQL 8 | Redis 7 |
| C | Cloud free tier (Render + Vercel) | sync | Postgres 16 | — (database cache/sessions) |

---

## A. Local development

```bash
# infrastructure
docker run -d --name formforge-mysql -e MYSQL_DATABASE=formforge -e MYSQL_USER=formforge \
  -e MYSQL_PASSWORD=secret -e MYSQL_ROOT_PASSWORD=root -p 3307:3306 mysql:8
docker run -d --name formforge-redis -p 6379:6379 redis:7-alpine

# laravel
cd web
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port 8090          # ALWAYS artisan serve, not bare `php -S`
php artisan queue:work                  # second terminal

# ai service
cd ai-service
python -m venv .venv && .venv/Scripts/pip install -r requirements.txt
cp .env.example .env                    # paste your free GROQ_API_KEY
.venv/Scripts/uvicorn app.main:app --port 8001
```

## B. docker-compose (the full stack, one command)

```bash
cp .env.example .env                    # set APP_KEY + AI_SERVICE_TOKEN
cp ai-service/.env.example ai-service/.env   # paste GROQ_API_KEY
docker compose up -d --build            # → http://localhost:8080
```

Services: `mysql` + `redis` (healthchecked) → `ai` (FastAPI) → `web` (Apache/PHP 8.3; entrypoint waits for the DB, migrates, seeds the demo, caches config/routes/views) → `horizon` (queue workers) → `scheduler` (nightly analytics rollup). This is the topology that exercises Horizon and Redis exactly as the brief's "positive signals" describe.

## C. Cloud free tier — the live demo

```
Browser ──► Render web service (Docker: Apache + PHP 8.3)
                 │  DB_URL                    │ AI_SERVICE_URL + bearer token
                 ▼                            ▼
        Render Postgres 16 (free)    Vercel Python function (FastAPI) ──► Groq API
```

**Why this shape:** Vercel cannot host PHP/MySQL/workers, and Render's free tier has no background workers or Redis — so the AI layer lives on Vercel (where Python functions are first-class, `maxDuration: 60` covers LLM latency) and Laravel runs on Render with `QUEUE_CONNECTION=sync`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, Postgres via `DB_URL`. Jobs run inline; both dispatchers check task state immediately after dispatch so the UX is seamless. Full fidelity (Horizon/Redis/MySQL) remains topology B.

### Redeploying the AI service (Vercel)

```bash
cd ai-service
vercel deploy --prod --yes
vercel alias set <deployment-url> formforge-ai-vaibhav.vercel.app
```
Notes learned in production: use the **legacy `routes`** block in `vercel.json` (modern `rewrites` hand the function the rewritten path and FastAPI 404s); set env vars via the dashboard/API — **never pipe values through PowerShell** (BOM corruption); project-level Deployment Protection must stay **off** (the service has its own bearer auth and Laravel calls it server-to-server); avoid the dashboard's *Instant Rollback* (it pins production and blocks newer deploys until promoted).

### Redeploying the web app (Render)

`render.yaml` at the repo root is a one-click blueprint (`https://render.com/deploy?repo=<repo-url>`). The running service redeploys on demand:

```bash
curl -X POST https://api.render.com/v1/services/<service-id>/deploys \
  -H "Authorization: Bearer <api-key>" -d '{"clearCache":"do_not_clear"}'
```

Required env vars: `APP_KEY`, `DB_CONNECTION=pgsql`, `DB_URL` (from the database), `QUEUE_CONNECTION=sync`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `AI_SERVICE_URL`, `AI_SERVICE_TOKEN`, `AI_SERVICE_TIMEOUT=90`, `SEED_DEMO=true`, `PORT=80`, `LOG_CHANNEL=stderr`. The image is driver-aware (`pdo_mysql` **and** `pdo_pgsql`), adopts `RENDER_EXTERNAL_URL` as `APP_URL`, and trusts proxies for HTTPS.

Render notes: new Postgres instances ship with a **closed IP allowlist** (external psql needs a temporary allowlist entry via `PATCH /v1/postgres/{id}`); free web services **sleep after ~15 min idle** — first request takes ~1 min (documented in the README for reviewers).

## Troubleshooting

| Symptom | Cause → fix |
|---|---|
| Login/register button "does nothing", credentials appear in the URL | JS not loading. Stale `public/hot` file (delete it) or serving with bare `php -S` without Laravel's router (use `php artisan serve`). Livewire assets are also published to `public/vendor/livewire` as a belt-and-braces |
| First page load takes ~1 min on the demo | Render free tier cold start — by design |
| Seeder crash `undefined function fake()` in production | Faker must be a **runtime** dependency (already fixed — kept here for posterity) |
| AI generation fails with rate-limit errors | Groq free-tier TPM — the service waits out the hint and falls back to the second free model automatically; persistent failures appear in `prompt_logs` |
| 404s on `/livewire/livewire.js` | Same router-script issue as above |
