# Translation Management Service

An API-driven translation management backend built with Laravel 12. It stores translations
for an arbitrary number of locales and tags, exposes a searchable CRUD API secured with
Laravel Sanctum, and serves a large, frequently-changing dataset to frontend applications
(e.g. Vue.js) through a cache-backed, high-performance JSON export endpoint.

# Contents
- [Requirements](#requirements)
- [Installation](#installation)
- [Authentication](#authentication)
- [API Endpoints](#api-endpoints)
- [Database Design](#database-design)
- [Performance](#performance)
- [Cache Invalidation](#cache-invalidation)
- [Seeding 100k+ Records](#seeding-100k-records)
- [Testing](#testing)
- [CDN Readiness](#cdn-readiness)
- [Design Decisions](#design-decisions)
- [Known Limitations](#known-limitations)

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 8
- Redis 7

## Installation

```bash
git clone <repo-url> tms
cd tms
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan serve
```

## Authentication

The API uses **Laravel Sanctum** personal access tokens (bearer tokens), not sessions/cookies.

1. Obtain a token:

   ```bash
   curl -X POST http://localhost:8080/api/login \
     -H "Content-Type: application/json" \
     -d '{"email":"test@example.com","password":"password"}'
   ```

   Response:

   ```json
   { "data": { "token": "1|abc...", "token_type": "Bearer", "user": { "id": 1, "name": "Test User", "email": "test@example.com" } } }
   ```

2. Send it on every protected request:

   ```bash
   curl http://localhost:8080/api/translations \
     -H "Authorization: Bearer 1|abc..."
   ```

3. `POST /api/logout` revokes the token currently in use.

Unauthenticated requests to protected routes receive `401 Unauthenticated`.

## API Endpoints

All endpoints below (except `/api/login`) require `Authorization: Bearer <token>`.

| Method | URI                          | Description                                    |
|--------|------------------------------|-------------------------------------------------|
| POST   | `/api/login`                 | Authenticate, receive a bearer token             |
| POST   | `/api/logout`                | Revoke the current token                         |
| GET    | `/api/translations`          | Paginated search/list                            |
| POST   | `/api/translations`          | Create a translation                             |
| GET    | `/api/translations/{id}`     | Retrieve a translation                           |
| PUT    | `/api/translations/{id}`     | Update a translation                             |
| DELETE | `/api/translations/{id}`     | Delete a translation                             |
| GET    | `/api/translations/export`   | Full `{locale: {key: content}}` JSON export      |

Full request/response schemas: **OpenAPI/Swagger UI at `/api/documentation`**
(raw spec at `storage/api-docs/api-docs.json`, regenerate with `php artisan l5-swagger:generate`).

### List / search

```
GET /api/translations?key=welcome&locale=en&tag=mobile&content=Welcome&per_page=15&page=1
```

All filters are optional and combinable. Results are paginated (`data` + `meta`/`links`) —
the endpoint never returns the full table.

### Create

```json
POST /api/translations
{
  "key": "welcome.message",
  "locale": "en",
  "content": "Welcome",
  "tags": ["web", "mobile"]
}
```

`locale` must reference an existing `locales.code`. `(locale, key)` must be unique. Unknown
tags are created on the fly; the write (translation + tag sync) happens in one DB transaction.

### Update

`PUT /api/translations/{id}` accepts any subset of `key`, `locale`, `content`, `tags` and
validates the resulting `(locale, key)` pair for uniqueness (excluding the record itself).

### Export

```
GET /api/translations/export
GET /api/translations/export?locale=en
GET /api/translations/export?tag=mobile
```

```json
{
  "en": { "welcome.message": "Welcome", "login": "Login" },
  "fr": { "welcome.message": "Bienvenue", "login": "Connexion" }
}
```

## Database Design

```
locales               tags                  translations                 translation_tag
------------------    ----------------      ---------------------------  ---------------------
id (PK)                id (PK)               id (PK)                     id (PK)
code (unique)           name (unique)         locale_id (FK -> locales)   translation_id (FK)
name                                          translation_key             tag_id (FK)
timestamps                                    content                     timestamps
                                              timestamps                  UNIQUE(translation_id, tag_id)
                                              UNIQUE(locale_id, translation_key)
                                              INDEX(translation_key)
```

- **No per-language columns.** Locales are rows, not columns — adding German or Japanese is
  an `INSERT INTO locales`, never a migration.
- **`translations.content` holds one string per (locale, key)** — this is the normalized,
  3NF-correct shape for translated content instead of a wide table with one column per locale.
- **Tags are many-to-many** via `translation_tag`, so a translation can carry any combination
  of `web`/`mobile`/`desktop`/... without schema changes either.
- **`UNIQUE(locale_id, translation_key)`** is enforced at the database level (not just in
  validation) and also serves as the lookup index for "all translations in locale X" queries,
  since `locale_id` is its leftmost column — a separate single-column index on `locale_id`
  would be redundant, so it was intentionally omitted (fewer indexes = cheaper writes).
- Likewise **`UNIQUE(translation_id, tag_id)`** on the pivot doubles as the index for
  "all tags of translation X"; a dedicated index on `tag_id` was added for the reverse lookup
  ("all translations with tag Y").
- All foreign keys cascade on delete, so removing a translation or a tag cleans up
  `translation_tag` automatically without extra application code.

## Performance

Measured locally against a **100,002-row** dataset (7 locales) seeded via
`php artisan translations:seed --count=100000`, served through Docker's Nginx + PHP-FPM
(opcache enabled):

| Endpoint                              | Measured response time |
|----------------------------------------|------------------------|
| `GET /api/translations`                | ~37ms                  |
| `GET /api/translations?locale=en`      | ~14ms                  |
| `GET /api/translations?tag=mobile`     | ~81ms                  |
| `GET /api/translations?key=nav`        | ~36ms                  |
| `GET /api/translations/export` (miss)  | ~470ms                 |
| `GET /api/translations/export` (hit)   | ~45ms                  |

(measured with `curl -w time_total` against `docker compose up` — Nginx + PHP-FPM +
`config:cache`/`route:cache`, real MySQL 8 and Redis containers, no artificial warmup beyond
one request.)

These are local, hardware-dependent numbers, not guarantees — see the automated
[performance benchmark suite](#testing) for a repeatable (if more lenient) check.

Techniques used to hit these numbers:

- **Indexes** described above cover every filter/lookup path used by the API.
- **Selective columns**: `select()`s in `TranslationService` never pull unused columns, and
  the export path uses `DB::table()` (query builder), not Eloquent, avoiding model
  hydration/attribute-casting overhead entirely.
- **No N+1s**: the list endpoint eager-loads `locale:id,code` and `tags:id,name` — 1 query
  for the page + 1 for locales + 1 for tags, regardless of page size (covered by an automated
  query-count test). Tag/locale filters compile to `WHERE EXISTS` sub-queries on the *same*
  query rather than triggering extra round-trips.
- **Pagination**: `GET /api/translations` always paginates (`per_page`, default 15) — it never
  loads the full table into memory, satisfying the 100k+ scalability requirement.
- **Export uses a cursor, not `->get()`**: `buildExportJson()` streams rows via
  `DB::table(...)->cursor()` over `stdClass` objects (no Eloquent hydration) and writes JSON
  incrementally into a string, keeping memory bounded and avoiding a giant in-memory array of
  model instances.
- **Bulk seeding**: `translations:seed` inserts in configurable batches (`DB::table()->insert()`
  with hundreds/thousands of rows at a time) instead of one `INSERT` per row via Eloquent.
- **Redis-backed caching** (below) means a warm export is a single Redis `GET`, not a query at all.

## Cache Invalidation

The export endpoint is the most expensive read, so its result is cached in Redis:

```
GET /export → Redis (tag: translations-export) → hit? return cached JSON
                                                 → miss? build JSON via cursor query → cache → return
```

Every write path (`create`, `update`, `delete` in `TranslationService`) ends by calling
`Cache::tags(['translations-export'])->flush()`. Because the whole export cache lives under
one Redis tag, **any** change — a new translation, an edited key/content, a locale swap, a
retagging, or a deletion — invalidates **every** cached export variant (global, per-locale,
per-tag) in one call. This trades a bit of cache-hit-rate after a write for guaranteed
correctness, which the requirements explicitly prioritize ("the export must always reflect
the latest database state").

Cache tagging requires a tag-capable store (Redis or the array store); this is why
`CACHE_STORE=redis` in Docker/production and `CACHE_STORE=array` in `phpunit.xml` for tests.

### Database → Redis → CDN

```
MySQL (source of truth)
   │  write invalidates the tag
   ▼
Redis (export cache, tag: translations-export)
   │  served with Cache-Control + ETag
   ▼
CDN / browser cache (optional, in front of the export endpoint)
```

The export response sets `Cache-Control: public, max-age=30, must-revalidate` and an `ETag`
derived from the payload's hash. A CDN (CloudFront, Cloudflare, etc.) placed in front of
`/api/translations/export` can safely cache the response for a short TTL and use conditional
`If-None-Match` requests to cheaply confirm freshness — bounding staleness to `max-age`
without adding load to the origin. For stricter freshness, a real deployment would issue an
explicit CDN purge/invalidation call for the export path alongside the existing Redis-tag
flush in `TranslationService::flushExportCache()`.

## Seeding 100k+ Records

```bash
# inside the app container, or locally if you have PHP/Composer set up
php artisan translations:seed --count=100000

# customize
php artisan translations:seed --count=250000 --locales=en,fr,es,de,it --tags=web,mobile,desktop --chunk=2000
```

Design of the seeder (`app/Console/Commands/SeedTranslations.php`):

- Generates `ceil(count / locales)` **unique keys** once, then creates one row per
  `(locale, key)` pair — this mirrors how real i18n data looks (the same key exists in every
  locale) and makes the `(locale_id, translation_key)` uniqueness trivially guaranteed, with
  no collision retries.
- Inserts in configurable batches (`--chunk`, default 2000) via `DB::table()->insert()` —
  no Eloquent model instantiation, no per-row queries.
- Attaches a random 0–3 tags per translation using the first/last id of each inserted batch
  (`PDO::lastInsertId()` returns the *first* id of a multi-row insert on MySQL) so the pivot
  rows can be bulk-inserted too, without re-querying for ids.
- Runs each batch inside a transaction so a failure mid-run cannot leave orphaned pivot rows.
- 100,000 records seed in roughly 8–10 seconds locally.

`php artisan db:seed` (the default `DatabaseSeeder`) creates the login-ready test user
(`test@example.com` / `password`) plus a small 2,000-row dataset for quick local development;
use the dedicated command above for the full performance-scale dataset.

# Testing

```bash
php artisan test                       # everything, including the performance smoke tests
php artisan test --exclude-group=performance   # fast run: auth, CRUD, search, export, security
php artisan test --group=performance           # only the benchmark suite
```

Coverage (see `tests/`):

- **Auth** (`tests/Feature/Auth/LoginTest.php`): valid/invalid login, unauthenticated access
  rejected (401), authenticated access allowed.
- **CRUD** (`tests/Feature/Translations/TranslationCrudTest.php`): create/read/update/delete,
  validation errors, duplicate `(locale, key)` rejection, unknown-locale rejection, tag
  attach/detach, 404s.
- **Search** (`tests/Feature/Translations/TranslationSearchTest.php`): filter by key, content,
  locale, tag, combined filters, pagination, SQL-injection-safe parameter handling, and an
  explicit query-count assertion guarding against N+1 regressions.
- **Export** (`tests/Feature/Translations/TranslationExportTest.php`): JSON shape, multi-locale
  grouping, locale/tag filtering, and — importantly — that an update or delete is reflected on
  the **very next** export call (proves cache invalidation actually works, not just that
  caching exists).
- **Service unit tests** (`tests/Unit/TranslationServiceTest.php`): the same business rules
  exercised directly against `TranslationService`, independent of HTTP/routing.
- **Performance** (`tests/Feature/Performance/PerformanceBenchmarkTest.php`, tagged
  `#[Group('performance')]`): seeds ~3,000 rows and times the four key endpoints, printing
  `[benchmark] ...` lines to stderr. Thresholds are deliberately generous (2–3s) because CI/
  sandbox hardware varies — they catch gross regressions, not micro-timing. For the real
  `<200ms` / `<500ms` production targets, benchmark manually against the full 100k dataset as
  shown in [Performance](#performance).

`phpunit.xml` runs the suite against a dedicated `tms_testing` MySQL database
(`RefreshDatabase` wraps each test in a transaction) with `CACHE_STORE=array`, so tests never
touch your dev data or require a running Redis.

## Docker

```bash
docker compose up -d --build   # start nginx, app (php-fpm), mysql, redis
docker compose exec app php artisan migrate --force
docker compose ps              # check status
docker compose logs -f app     # tail app logs
docker compose down            # stop
docker compose down -v         # stop and wipe the mysql volume
```

Services:

- **nginx** — reverse proxy / static file server, published on `localhost:8080`.
- **app** — PHP 8.2-FPM (Alpine), with `pdo_mysql` and `opcache` (incl. JIT) enabled; talks to
  Redis via the pure-PHP `predis` client (no native extension to compile).
- **mysql** — MySQL 8, published on `localhost:3307` (see the port note above), with an
  init script that also creates the `tms_testing` database used by the test suite.
- **redis** — Redis 7, published on `localhost:6379`.

## CDN Readiness

The service is stateless (tokens are DB-backed, not server sessions) so it horizontally scales
behind a load balancer without sticky sessions. The export endpoint specifically is
CDN-friendly:

- It's a plain `GET` with cacheable, deterministic responses per query-string combination.
- It sets `Cache-Control` and `ETag` headers (see [Cache Invalidation](#cache-invalidation)).
- Filtering (`?locale=`, `?tag=`) naturally partitions the cache key space, so a CDN can cache
  each variant independently.

Bearer-token-protected endpoints (everything else) are intentionally **not** cache-control'd
for shared/CDN caching — they're per-user and should stay at the origin or in a private cache.

## Design Decisions

- **Service layer, not repositories.** `TranslationService` centralizes the CRUD +
  search + export business logic (transactions, cache invalidation, tag syncing). A generic
  repository/interface layer was deliberately skipped — Eloquent already *is* the data-access
  abstraction here, and wrapping it again would add indirection without adding testability or
  swappability that this project actually needs.
- **Form Requests own validation**, including the cross-field `(locale, key)` uniqueness rule
  (built with `Rule::unique(...)->where(...)`), keeping controllers to HTTP-only concerns.
- **API Resources** (`TranslationResource`) shape the wire format independently of the DB
  schema (`translation_key` → `key`, `locale_id` → `locale` code, tags → plain name array).
- **Query builder for export, Eloquent for CRUD/search.** CRUD and search benefit from
  Eloquent's relationships, casts, and mass-assignment protection; the export's only job is to
  move rows to JSON as fast as possible, where Eloquent hydration is pure overhead.
- **Cache-tag flush over fine-grained invalidation.** A single tag flush is simple, correct by
  construction, and cheap at this scale; targeted per-locale/tag invalidation would add
  complexity for a marginal hit-rate gain that wasn't worth it in a 2-hour-scoped build.
- **`predis` as the Redis client everywhere** (`REDIS_CLIENT=predis`), including inside Docker.
  It's a pure-PHP client, so neither the app image nor a native host setup needs to compile the
  `redis` PECL extension — a simpler, faster-building image at a negligible latency cost for
  this workload (network-bound, not CPU-bound).

## Known Limitations

- **`LIKE '%term%'` content/key search** cannot use a standard B-tree index efficiently (the
  leading wildcard prevents index range scans), so it degrades to a table scan as the dataset
  grows. At 100k rows this is still fast enough locally, but a production system with millions
  of rows should add a MySQL **FULLTEXT** index (`FULLTEXT(content)`, queried via
  `MATCH ... AGAINST`) or move search to dedicated infrastructure (e.g. Meilisearch/OpenSearch)
  — intentionally not implemented here per the assessment's scope constraints.
- **Export cache invalidation is coarse** (single tag, flushes all locale/tag variants on any
  write) rather than surgically invalidating only the affected combination — a deliberate
  simplicity/correctness trade-off explained above.
- **No fine-grained authorization** (e.g. per-user ownership of translations) — any
  authenticated user can manage any translation, which matches the assessment's scope
  ("avoid complex authorization").
- The seeder's random word generation is not guaranteed unique key text collision-free across
  independent runs on the *same* un-truncated table for the *same* locale (extremely unlikely
  in practice due to the appended sequential index, but not mathematically guaranteed) —
  acceptable for a performance-dataset generator.
