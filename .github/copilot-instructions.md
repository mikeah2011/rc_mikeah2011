# Repository instructions

## Commands

This project uses PHP 8.3+, Laravel 13, PHPUnit 12, and PostgreSQL 17.
Frontend assets use Vite 8 and Tailwind CSS 4.

| Task | Command |
| --- | --- |
| Start local PostgreSQL | `docker compose up -d --wait postgres` |
| Bootstrap a fresh checkout | `composer setup` |
| Apply migrations | `php artisan migrate` |
| Start development environment | `composer dev` |
| Run only the API server | `php artisan serve` |
| Process notifications | `php artisan queue:work database --queue=notifications` |
| Run the scheduler locally | `php artisan schedule:work` |
| Build assets | `npm run build` |
| Run Vite separately | `npm run dev` |
| Run all tests | `composer test` |
| Run one test file | `composer test -- tests/Feature/DeliveryApiTest.php` |
| Run one test method | `composer test -- --filter=DeliveryApiTest::test_delivery_is_accepted_and_duplicate_is_idempotent` |
| Check PHP formatting | `vendor/bin/pint --test` |
| Format a changed PHP file | `vendor/bin/pint app/Services/DeliveryService.php` |

`composer setup` installs Composer dependencies, copies `.env.example` if needed,
generates an application key, runs migrations, installs npm dependencies with
`--ignore-scripts`, and builds assets. Configure the database before running it.
Use it for initial setup, not routine restarts: persisted encrypted fields depend
on the existing `APP_KEY`.

`.env.example` selects PostgreSQL and the `notifications` database queue.
`DB_QUEUE_CONNECTION` must use the same Laravel database connection as the
application; keep the database queue's `after_commit` setting `false` so the job
insert participates in the Delivery transaction. Match the worker's queue name
to `NOTIFICATION_QUEUE` when changing it. Preserve the timeout ordering:
endpoint HTTP timeout (at most 30 seconds) < job timeout (40 seconds) <
queue `retry_after` (90 seconds by default).

## Architecture

The Chinese-language `README.md` explains the system boundary and reliability
decisions. This is the reliable HTTP-delivery core of an internal notification
service: callers submit a resolved `endpoint_key` and supplier-formatted content.
Event routing, templates, and provider adapters are future extensions, not
current capabilities. Each Delivery targets exactly one Endpoint.

The request path is `routes/api.php` -> `AuthenticateApiClient` ->
`StoreDeliveryRequest` -> `DeliveryController` -> `DeliveryService`.
The service resolves the client's granted, active endpoint, validates dynamic
headers, computes the request hash, and inserts both Delivery and
`DeliverNotification` in one database transaction. The API returns 202 after
persistence; it does not wait for the supplier.

The worker loads the Delivery and current Endpoint configuration from the
database, records a DeliveryAttempt, sends HTTP outside the transaction, then
persists the result. Deliveries and attempts are the business source of truth;
Laravel's `jobs` and `failed_jobs` are queue infrastructure, not delivery status.
Jobs carry only the delivery UUID and round, never the business body or credentials.
Changing an Endpoint affects subsequent attempts of existing deliveries.

`DeliveryStatus` defines `pending`, `processing`, `delivered`, and `failed`.
Retryable failures return to `pending`; terminal failures remain queryable and
can be manually retried through the API. A manual retry increments
`delivery_round`, resets the round's attempt count, and retains prior attempts.
Preserve round and terminal-state guards in both normal completion and the
job's `failed()` callback so obsolete jobs cannot overwrite a newer round. An
attempt is counted before outbound I/O. If a Worker recovers after a stopped
attempt, that attempt becomes `abandoned` but still consumes the Endpoint's
`max_attempts` budget; an exhausted round must fail without another HTTP call.
The job `failed()` callback must likewise close only unfinished attempts in its
current round before marking a nonterminal Delivery failed.

Endpoint/client provisioning uses Artisan commands:
`notifications:client-create {name}`, `notifications:endpoint-upsert {key}`,
and `notifications:client-grant {client} {endpoint}`. The grant arguments are
client name and endpoint key. Endpoint upsert accepts HTTPS URLs without
embedded credentials and prompts interactively for hidden static-header JSON.
Client creation prints the API key once and stores only its SHA-256 hash.

`routes/console.php` schedules delivery pruning at 02:15 and failed-job pruning
at 02:30. Delivery retention defaults to 30 days after success and 90 days after
failure; deleting a Delivery cascades to its attempts. `/healthz` is Laravel's
health endpoint; `/readyz` checks database connectivity.

## API and persistence conventions

- API clients authenticate with `X-API-Key`; middleware stores the authenticated
  `ApiClient` in the request's `api_client` attribute, not Laravel's user session.
  The `deliveries` rate limiter is keyed by client ID. Ungranted/inactive
  endpoints and cross-client delivery access return 404.
- Requests require `content_type` and exactly one of `payload` or `body_base64`.
  Field presence matters: `payload: null` still counts as supplied. Payloads are
  JSON-encoded; base64 permits exact binary bodies. The decoded/encoded body
  limit defaults to 1 MiB via `config/notifications.php`.
- Dynamic header names are lowercased and sorted, checked against the Endpoint's
  allowlist, and rejected for reserved headers such as `Authorization` and
  `Idempotency-Key`. Supplier requests merge static and dynamic headers and add
  the stored content type and original idempotency key. Redirects are disabled.
- Ingress idempotency is unique on `(api_client_id, idempotency_key)`, not per
  endpoint. The hash covers endpoint ID, content type, normalized headers, and
  exact body bytes. Do not introduce JSON key canonicalization casually: it
  changes request identity. Identical duplicates return the original Delivery
  with 202 and `created: false`; different content returns 409. Preserve both the
  database uniqueness constraint and the service's concurrent-insert handling.
- Delivery is at-least-once, not exactly-once. The outbound idempotency key stays
  stable across attempts and manual retries; suppliers must deduplicate side
  effects. HTTP 2xx succeeds; 408, 429, 5xx, and connection failures retry.
  Other HTTP statuses, including redirects, fail permanently. Retry scheduling
  uses the Endpoint's backoff array, repeats its last entry, and applies
  50%-150% jitter with a one-second minimum. Endpoint `max_attempts` controls
  delivery retries separately from Laravel job `$tries`/`$maxExceptions`.
- Eloquent encrypts Delivery `body` and `dynamic_headers` and Endpoint
  `static_headers`; these fields are hidden from serialization. Return
  `DeliveryResource`'s explicit status metadata rather than raw models.
  Resources are globally unwrapped (no `data` envelope), timestamps use Atom
  strings, and API exceptions render as JSON.
- Lazy loading is prevented outside production. Eager-load required
  relationships, as the worker does for `endpoint`. Attempt uniqueness is
  `(delivery_id, delivery_round, attempt_number)`; interrupted processing
  attempts become `abandoned` when the worker recovers.

## Test conventions

Tests extend `Tests\TestCase`, use PHPUnit `test_*` methods and
`LazilyRefreshDatabase`, and currently live in `tests/Feature`.
`phpunit.xml` selects in-memory SQLite, a fixed testing encryption key, array
cache/session stores, and the synchronous queue.

API tests use `Queue::fake()` to avoid sending notifications. Worker tests use
`Http::preventStrayRequests()` with `Http::fake()` and invoke the job directly;
`withFakeQueueInteractions()` exposes release/failure assertions.
`QueuePersistenceTest` deliberately switches to a real database queue on the
test database connection to verify reference-only job payloads and rollback
when job insertion fails. Do not replace that queue with a fake.
