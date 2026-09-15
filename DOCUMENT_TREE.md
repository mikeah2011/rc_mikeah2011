# Repository Document: DOCUMENT_TREE

This file presents a tree view of the repository and short explanations for each top-level component. Use it as a quick reference for where to find code, configuration, migrations, jobs, tests, and project documentation.

```
.
├── app/
│   ├── Http/
│   │   └── Controllers/
│   │       └── NotificationController.php   # API endpoints: create notification, manual retry
│   ├── Jobs/
│   │   └── DeliverNotification.php          # Background delivery job (HTTP send, retries)
│   └── Models/
│       └── Notification.php                 # Notification model (UUID PK, relations)
├── config/
│   └── notifications.php                    # Delivery / backoff / retry configuration
├── database/
│   └── migrations/                          # DB migrations (notifications, notification_attempts, jobs, failed_jobs)
├── routes/
│   └── api.php                              # API routes (POST /api/notifications, POST /api/notifications/{id}/retry)
├── tests/
│   └── Feature/                             # Feature tests (create, retry behaviour)
├── documents/
│   ├── PLAN.md                              # Implementation plan and batch rules
│   ├── CHANGELOG.md                         # Change log and strategy updates
│   ├── TECH_STACK.md                        # Tech selection and rationale
│   ├── AI_MODEL_POLICY.md                   # AI model usage & permission policy
│   ├── SETUP.md                             # Local / Docker setup instructions
│   └── AI_Coding_作业.pdf                   # Assignment / spec PDF
├── docker-compose.yml                       # Compose for app + db + queue (for Docker-capable hosts)
├── docker/
│   └── php/Dockerfile                       # PHP container image definition used by compose
├── .env.example                              # Example environment config
├── composer.json                             # PHP package manifest
├── README.md                                 # Project overview, quickstart, and document index
└── DOCUMENT_TREE.md                          # This file
```

Brief notes
- app/: application runtime code. Controllers validate and persist incoming notifications and enqueue delivery jobs. Jobs contain delivery logic (HTTP call, attempt recording, retry decision). Models encapsulate domain behavior (status, delivery_round, idempotency keys).
- config/notifications.php: configuration for retry/backoff parameters (base delay, jitter, exponent, max attempts) — tuneable without code change.
- database/migrations/: schema for notifications and attempts; migrations are included so the project can be set up on SQLite for development or a real RDBMS in production.
- routes/api.php: minimal REST surface — create and manual retry endpoints returning appropriate HTTP status (202 Accepted on enqueue).
- tests/Feature/: examples of feature tests used during development to validate core flows (creation, dispatch enqueue, manual retry). These run against SQLite in CI/local.
- documents/: canonical place for design artifacts, change log, tech rationale, AI usage notes, and setup instructions. Use the PLAN.md and AI_MODEL_POLICY.md to understand workflow & release rules.
- Docker artifacts are provided to run end-to-end locally in an environment with Docker. The repository still supports local SQLite for quick iteration.

Usage
- Quick locate: open README.md for clickable document links, or read this file when you want a compact tree view.
- To generate a more detailed site-map or export for documentation portals, use DOCUMENT_TREE.md as the canonical source.

Generated: by Copilot CLI
