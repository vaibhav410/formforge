# Project Structure

Monorepo with two deployable services plus shared deliverables.

```
formforge/
├── web/                          # Laravel 11 application (UI, domain, storage, queues)
│   ├── app/
│   │   ├── Enums/                # FieldType (the 19-type SSOT), statuses, sources
│   │   ├── Schema/               # ★ The schema engine — single source of truth
│   │   │   ├── FormSchema.php            #   value object over the schema array
│   │   │   ├── SchemaFactory.php         #   canonical constructors (palette/seeder/importers)
│   │   │   ├── SchemaSanitizer.php       #   normalise untrusted JSON; lenient AI/import repair
│   │   │   ├── FormSchemaValidator.php   #   strict structural+semantic validation (JSON paths)
│   │   │   ├── ValidationRuleCompiler.php#   schema → Laravel rules, per request
│   │   │   └── ConditionEvaluator.php    #   server-side conditional-logic authority
│   │   ├── Services/
│   │   │   ├── FormService.php           #   THE single write path (create/save/publish/rollback)
│   │   │   ├── SubmissionService.php     #   validate → store pipeline, encryption, files
│   │   │   ├── AnalyticsService.php      #   funnel reads (raw now, rollups at scale)
│   │   │   ├── Ai/AiServiceClient.php    #   the only class that knows FastAPI exists
│   │   │   └── Import/                   #   WordParser, ExcelParser, LabelTypeInferencer
│   │   ├── Jobs/                 # RunAiTaskJob, ProcessImportJob, ExportSubmissionsJob,
│   │   │                         # AggregateAnalyticsJob (scheduled nightly)
│   │   ├── Livewire/             # Builder/, Forms/, Ai/, Imports/, Submissions/,
│   │   │                         # Versions/, Analytics/ — full-page components
│   │   ├── Http/Controllers/     # Public form, preview, beacons, export download
│   │   ├── Policies/FormPolicy.php       # ownership = tenant boundary
│   │   ├── DTO/ · Events/ · Exceptions/
│   │   └── Models/               # Form, FormVersion, Submission(+Answer), AiTask,
│   │                             # PromptLog, Import, FormEvent(+Daily), FormExport
│   ├── database/                 # 13 migrations (indexes in-line), factories, DemoSeeder
│   ├── resources/
│   │   ├── js/                   # app.js, builder.js (Sortable+history+QR), public.js
│   │   │                         # (Alpine logic mirror, beacons, signature pad)
│   │   └── views/                # livewire/* pages, public/* renderer, components/
│   ├── routes/                   # web.php, auth.php, console.php (scheduler)
│   ├── tests/                    # Pest: Unit/ + Feature/ (74 tests)
│   ├── Dockerfile                # 3-stage: node assets → composer → php:8.3-apache
│   └── docker/                   # entrypoint.sh (driver-aware), php.ini
│
├── ai-service/                   # FastAPI microservice — the only LLM caller
│   ├── app/
│   │   ├── main.py               # routes, auth dependency, error contract
│   │   ├── config.py · auth.py
│   │   ├── models/schema.py      # ★ Pydantic mirror of the schema contract
│   │   ├── models/api.py         # request/response DTOs + AttemptLog telemetry
│   │   └── services/             # prompts.py (documented strategy), groq_client.py,
│   │                             # json_repair.py, generator.py (repair/retry loop)
│   ├── api/index.py              # Vercel serverless entrypoint
│   ├── tests/                    # pytest (22 tests, scripted fake Groq)
│   ├── Dockerfile · vercel.json · requirements.txt
│
├── docs/                         # SCHEMA.md (the contract), API_DOCUMENTATION.md,
│   │                             # DATABASE.md, SECURITY.md, DEPLOYMENT.md, TESTING.md,
│   │                             # PROJECT_STRUCTURE.md, ai-service-openapi.json
├── samples/                      # job-application.docx, event-feedback-structured.xlsx,
│                                 # vendor-contacts-plain.xlsx — parser contract fixtures
├── docker-compose.yml            # full-fidelity stack (MySQL/Redis/Horizon/scheduler)
├── render.yaml                   # one-click free-tier blueprint
├── .github/workflows/ci.yml     # both test suites on every push
├── README.md · DECISIONS.md · CHANGELOG.md
```

## Where to start reading

1. `docs/SCHEMA.md` — the contract everything derives from.
2. `web/app/Schema/` — the engine enforcing it (start with `FormService`).
3. `ai-service/app/services/generator.py` — how LLM output becomes trustworthy.
4. `web/app/Services/Import/WordParser.php` — the deterministic-first import philosophy.
