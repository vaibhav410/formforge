# API Documentation

FormForge exposes two HTTP surfaces: the **Laravel web application** (session-authenticated UI + public form endpoints) and the **FastAPI AI service** (bearer-token REST consumed by Laravel). The machine-readable spec for the AI service is in [ai-service-openapi.json](ai-service-openapi.json) and served live at `/docs` on the deployed service.

---

## 1. Laravel application

Base URL (live demo): `https://formforge-zzoq.onrender.com`

### 1.1 Public endpoints (no authentication)

| Method | Path | Description |
|---|---|---|
| `GET` | `/` | Product landing page |
| `GET` | `/f/{public_id}` | Public fill page for a **published** form (404 for drafts). Logs a deduplicated `view` event |
| `POST` | `/f/{public_id}` | Submit a response. Server-side validation is compiled from the stored schema; throttled per form + IP (default 10/min); honeypot + minimum-fill-time bot checks |
| `GET` | `/f/{public_id}/thanks` | Post-submission page (message comes from the schema's settings) |
| `POST` | `/f/{public_id}/event` | Analytics beacon: `{event: "start"\|"field_focus"\|"abandon", field_key?}`. Throttled 120/min per IP |
| `GET` | `/up` | Health check (used by the hosting platform) |

**Submission request** is a normal form POST whose field names are the schema's field `key`s (checkboxes as `key[]`, address as `key[line1]`… , files as multipart). Two protocol fields are required: `_token` (CSRF) and `_rt` (encrypted render timestamp used for duration + bot detection).

**Validation errors** follow Laravel conventions: HTTP 302 back with an `errors` session bag (HTML flow), field-keyed messages.

### 1.2 Authenticated app (session auth via Breeze)

All routes below require login; form-scoped routes additionally enforce **owner-only** access through `FormPolicy`.

| Method | Path | Description |
|---|---|---|
| `GET` | `/dashboard` | Form list with search + status filter |
| `GET` | `/forms/ai` | AI generation page (per-user hourly quota) |
| `GET` | `/forms/import` | Word/Excel import wizard |
| `GET` | `/forms/{uuid}/builder` | Drag-and-drop builder (Livewire) |
| `GET` | `/forms/{uuid}/preview` | Render the current draft as the public page (submissions disabled) |
| `GET` | `/forms/{uuid}/submissions` | Paginated, searchable submissions with expandable detail |
| `GET` | `/forms/{uuid}/versions` | Version history with diffs and one-click rollback |
| `GET` | `/forms/{uuid}/analytics` | Views/starts/completion/drop-off dashboard |
| `GET` | `/exports/{uuid}/download` | Download a finished CSV export (owner only) |
| `GET` | `/horizon` | Queue dashboard (gated; demo account allowed) |

Mutations (create/edit/publish/rollback/delete, AI edit, import commit, export request) are **Livewire actions** carried over `POST /livewire/update` with CSRF — they are UI transport, not a public API contract.

### 1.3 Rate limits

| Limiter | Scope | Default |
|---|---|---|
| `public-submit` | form + IP | 10/minute (`FORM_SUBMISSION_RATE_LIMIT`) |
| `public-events` | IP | 120/minute |
| `ai-generation` | user | 10/hour (`AI_GENERATION_RATE_LIMIT`) |

---

## 2. AI service (FastAPI)

Base URL (live): `https://formforge-ai-vaibhav.vercel.app` · Interactive docs: `/docs`

**Authentication:** every `/v1/*` call requires `Authorization: Bearer <AI_SERVICE_TOKEN>` (the shared secret configured on both sides; compared in constant time). Laravel is the only intended client.

### `GET /health`
```json
{"status":"ok","provider":"groq","model":"llama-3.3-70b-versatile",
 "fallback":"llama-3.1-8b-instant","key_configured":true}
```

### `POST /v1/forms/generate`
```jsonc
// request
{ "prompt": "internship application with education history and resume upload",
  "locale": "hi" }            // optional
// 200 response
{ "schema": { /* full form schema, contract-valid */ },
  "model": "llama-3.3-70b-versatile",
  "total_latency_ms": 4181, "prompt_tokens": 1176, "completion_tokens": 982,
  "attempts": [ { "attempt": 1, "model": "llama-3.3-70b-versatile",
                  "outcome": "success", "latency_ms": 4181,
                  "prompt_tokens": 1176, "completion_tokens": 982 } ] }
```

### `POST /v1/forms/edit`
```jsonc
{ "prompt": "add an emergency contact section; make phone required",
  "schema": { /* current schema */ } }
```
Returns the **complete** updated schema (never a diff); existing ids/keys are preserved unless the instruction says otherwise.

### `POST /v1/forms/translate`
```jsonc
{ "target_language": "Hindi", "schema": { /* current schema */ } }
```
Translates only human-visible strings (labels, descriptions, placeholders, option labels, settings messages); keys, values, types, validation and logic are untouched.

### Error contract

| Status | Meaning |
|---|---|
| `401` | missing/invalid bearer token |
| `422` | the model could not produce a contract-valid schema after all repair attempts — body carries `detail` plus the full per-attempt telemetry so failures are debuggable |
| `503` | service token not configured |

Every LLM round-trip (including failures) is returned in `attempts[]` and persisted by Laravel into `prompt_logs`.

### Example
```bash
curl -X POST https://formforge-ai-vaibhav.vercel.app/v1/forms/generate \
  -H "Authorization: Bearer $AI_SERVICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"prompt": "simple contact form with name, email and message"}'
```
