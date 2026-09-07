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
- [Seeding 100k+ Records](#seeding-100k-records)
- [Testing](#testing)
- [Design Decisions](#design-decisions)

## Requirements

- PHP 8.2+
- Composer 2
- MySQL 8
- Redis 7

## Installation

```bash
git clone https://github.com/AhtashamYousaf/tms.git
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

## Seeding 100k+ Records

```bash
php artisan translations:seed --count=100000

# customize
php artisan translations:seed --count=250000 --locales=en,fr,es,de,it --tags=web,mobile,desktop --chunk=2000
```

## Testing

```bash
php artisan test
php artisan test --exclude-group=performance   # fast run: auth, CRUD, search, export, security
php artisan test --group=performance           # only the benchmark suite
```

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
- **`predis` as the Redis client everywhere** (`REDIS_CLIENT=predis`)
