MCQ done
Tutorial done
Matching done
checklist
# Translation Management Service

An API-driven translation management backend built with Laravel 12. It stores translations
for an arbitrary number of locales and tags.

# Contents
- [Requirements](#requirements)
- [Installation](#installation)
- [Authentication](#authentication)
- [API Endpoints](#api-endpoints)
- [Seeding 100k+ Records](#seeding-100k-records)
- [Testing](#testing)

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
php artisan migrate
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


## Seeding 100k+ Records

```bash
php artisan translations:seed --count=100000

# customize
php artisan translations:seed --count=250000 --locales=en,fr,es,de,it --tags=web,mobile,desktop --chunk=2000
```

# Testing

```bash
php artisan test                       # everything, including the performance smoke tests
php artisan test --exclude-group=performance   # fast run: auth, CRUD, search, export, security
php artisan test --group=performance           # only the benchmark suite
```