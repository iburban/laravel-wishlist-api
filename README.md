# Wishlist API

[![CI](https://github.com/iburban/laravel-wishlist-api/actions/workflows/ci.yml/badge.svg)](https://github.com/iburban/laravel-wishlist-api/actions/workflows/ci.yml)

A back-end-only **Laravel 13** JSON API for an e-commerce "Wishlist" feature: users register and
log in (token-based auth via **Laravel Sanctum**), browse a public product catalog, and add / view /
remove products on their own wishlist. Runs on **MySQL** (SQLite in-memory for the test suite).

> The full design spec — data model, endpoint contract, error envelope, and test plan — lives in
> [`implementation_plans/plan.md`](implementation_plans/plan.md). This README is the operator/consumer guide.

## Requirements

- **Docker** (Docker Engine + Compose v2) — the only requirement for the one-command path, **or**
- **PHP 8.3+**, **Composer 2**, and **MySQL 8** (or SQLite) for the local path.

## Run it

### Path A — Docker (one command)

```bash
docker compose up --build
```

That's it. The app container reads its `APP_KEY` and database settings from the Compose
`environment` block, so **no `.env` file is needed**. On first start it waits for MySQL, runs
migrations, and seeds the catalog; the API is served at **http://localhost:8080**.

- Idempotent: a second `docker compose up` re-runs migrations (no-op) and **skips seeding** when the
  catalog is already populated — no duplicate rows, no unique-key failures.
- Logs stream to the container's stdout/stderr (`docker compose logs app`).
- Tear down (including the database volume) with `docker compose down -v`.

### Path B — Local (no Docker)

Defaults to SQLite, so it works with zero database setup:

```bash
cp .env.example .env        # ships with DB_CONNECTION=sqlite
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve           # http://127.0.0.1:8000
```

To use MySQL instead, set `DB_CONNECTION=mysql` and the `DB_*` credentials in `.env` before migrating.

### Seeded data

The seeder creates **~10 products** and one test account:

| Field | Value |
|---|---|
| email | `test@example.com` |
| password | `password` |

`GET /api/products` is **public**, so you can browse the catalog before registering.

## API

Base URL: `http://localhost:8080/api` (Docker) or `http://127.0.0.1:8000/api` (local).
All responses are JSON. Authenticated routes expect `Authorization: Bearer <token>`.
Success payloads are wrapped in `data`; see [Error responses](#error-responses) for the error shape.

| Method | Path | Auth | Body |
|---|---|---|---|
| POST | `/api/register` | public | `name, email, password, password_confirmation` |
| POST | `/api/login` | public | `email, password` |
| POST | `/api/logout` | token | — |
| GET | `/api/products` | public | — |
| GET | `/api/wishlist` | token | — |
| POST | `/api/wishlist` | token | `product_id` |
| DELETE | `/api/wishlist/{product}` | token | — |

### Register — `POST /api/register` → 201

```bash
curl -X POST http://localhost:8080/api/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Ada Lovelace","email":"ada@example.com","password":"password123","password_confirmation":"password123"}'
```
```json
{
  "data": {
    "user": { "id": 2, "name": "Ada Lovelace", "email": "ada@example.com", "created_at": "2026-06-11T04:14:22.000000Z" },
    "token": "1|kv0xPHZlNXcZFkvoeV7zRQGoYOCi22KNmOHeOHST4f434e0b"
  }
}
```
Registration logs you straight in — the response carries a token, same shape as login.

### Login — `POST /api/login` → 200

```bash
curl -X POST http://localhost:8080/api/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","password":"password123"}'
```
```json
{
  "data": {
    "user": { "id": 2, "name": "Ada Lovelace", "email": "ada@example.com", "created_at": "2026-06-11T04:14:22.000000Z" },
    "token": "2|Ocfo71hEWRKYE3u3UzmNfBGiUNyq6JeKjNMxHjz1371731c2"
  }
}
```

### Logout — `POST /api/logout` → 200

Revokes the current token. Reusing it afterwards returns `401`.

```bash
curl -X POST http://localhost:8080/api/logout \
  -H 'Accept: application/json' -H 'Authorization: Bearer 2|Ocfo71h...'
```
```json
{ "message": "Logged out." }
```

### List products — `GET /api/products` → 200 (public)

```bash
curl http://localhost:8080/api/products -H 'Accept: application/json'
```
```json
{
  "data": [
    { "id": 1, "name": "Aeron Ergonomic Office Chair", "description": "Breathable mesh task chair with adjustable lumbar support.", "price": "1395.00", "created_at": "2026-06-11T04:13:52.000000Z" },
    { "id": 2, "name": "Mechanical Keyboard (Brown Switches)", "description": "Tactile hot-swappable keyboard with PBT keycaps.", "price": "119.99", "created_at": "2026-06-11T04:13:52.000000Z" }
  ]
}
```
`price` is a decimal **string** (`"1395.00"`), not a float — see [Design decisions](#design-decisions).

### Add to wishlist — `POST /api/wishlist` → 201

```bash
curl -X POST http://localhost:8080/api/wishlist \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 2|Ocfo71h...' \
  -d '{"product_id":1}'
```
```json
{
  "data": {
    "id": 1,
    "product": { "id": 1, "name": "Aeron Ergonomic Office Chair", "description": "Breathable mesh task chair with adjustable lumbar support.", "price": "1395.00", "created_at": "2026-06-11T04:13:52.000000Z" },
    "created_at": "2026-06-11T04:14:23.000000Z"
  }
}
```
The product is eager-loaded; `user_id` is never exposed.

### Get wishlist — `GET /api/wishlist` → 200

Returns **only the authenticated user's** items.

```bash
curl http://localhost:8080/api/wishlist \
  -H 'Accept: application/json' -H 'Authorization: Bearer 2|Ocfo71h...'
```
```json
{
  "data": [
    { "id": 1, "product": { "id": 1, "name": "Aeron Ergonomic Office Chair", "description": "Breathable mesh task chair with adjustable lumbar support.", "price": "1395.00", "created_at": "2026-06-11T04:13:52.000000Z" }, "created_at": "2026-06-11T04:14:23.000000Z" }
  ]
}
```

### Remove from wishlist — `DELETE /api/wishlist/{product}` → 200

`{product}` is the **product id**, scoped to the caller. Removing a product that isn't on your
wishlist (including one on someone else's) returns a generic `404` — see below.

```bash
curl -X DELETE http://localhost:8080/api/wishlist/1 \
  -H 'Accept: application/json' -H 'Authorization: Bearer 2|Ocfo71h...'
```
```json
{ "message": "Product removed from wishlist." }
```

### Error responses

One consistent envelope: `{ "message": ... }`, with an `errors` map only on `422`.

| Status | When | Body |
|---|---|---|
| **422** | Validation failure | `{ "message": "The name field is required. (and 2 more errors)", "errors": { "name": ["The name field is required."], "email": ["The email field is required."], "password": ["The password field is required."] } }` |
| **401** | Missing/invalid/revoked token | `{ "message": "Unauthenticated." }` |
| **404** | Not found — unknown route, or a wishlist item that isn't yours | `{ "message": "Resource not found." }` |
| **409** | Adding a product already on your wishlist | `{ "message": "Product is already in your wishlist." }` |

The `404` body is deliberately generic — it never names the model or echoes "no query results", so
the API can't be probed to infer whether another user holds a given item.

## Testing

```bash
php artisan test
```

The suite runs on **SQLite `:memory:`** (configured in `phpunit.xml`) — no database setup, and it's the
exact path CI runs on each push. Coverage of note:

- **Auth lifecycle** — register, login, logout, and that a revoked token is rejected (`401`).
- **Ownership isolation** — `GET /api/wishlist` returns only the caller's items; one user can't remove another's.
- **No existence leak** — removing a foreign/missing item is an identical generic `404`; the body hides the model class.
- **Atomic duplicate prevention** — a second add of the same product is `409`, and exactly one row exists.
- **No `user_id` leak** — wishlist and user payloads never expose internal/credential fields.
- **No N+1** — listing the wishlist eager-loads products in a single query regardless of item count.
- **Auth-guard** — every protected route rejects unauthenticated access with `401` JSON; public routes stay reachable.

## Design decisions

- **Atomic duplicate prevention, no race.** A wishlist can hold a product at most once, enforced by a
  DB `UNIQUE(user_id, product_id)` index — the single source of truth. `add` is a bare scoped insert
  (`$user->wishlists()->create(...)`) that catches `UniqueConstraintViolationException` and returns
  `409`; there is **no `SELECT`-then-`INSERT` pre-check**, so two concurrent identical adds can't both
  succeed. This is the right model *for a wishlist*, where the only contention is a duplicate add and a
  loser simply gets a clean `409`. **If this modeled real inventory/stock — where overselling matters — a
  unique index wouldn't be enough.** I'd reach for one of:
  - **Pessimistic:** `DB::transaction(fn () => …)` with `->lockForUpdate()` on the stock row, decrementing inside the lock.
  - **Optimistic:** an atomic conditional update — `UPDATE … SET stock = stock - 1 WHERE id = ? AND stock >= ?` — treating *zero affected rows* as "out of stock".

  The trade-off: pessimistic locking is simplest to reason about but serializes contending writers and
  can deadlock under load; the conditional update is lock-free and scales better but pushes the
  application to handle the "lost update" (zero-rows) branch explicitly. A wishlist needs neither — the
  constraint-based approach is the cheapest correct option here.
- **Ownership scoping, 404 not 403.** Every wishlist read/write goes through `$user->wishlists()`, never
  a global lookup. A row that isn't yours is indistinguishable from one that doesn't exist — both return
  a generic `404`, never `403`, so existence is never leaked (no IDOR).
- **Money as `DECIMAL(10,2)`** with a `decimal:2` Eloquent cast (serialized as the string `"19.99"`) —
  not a float (rounding drift) and not integer cents (extra conversion at every boundary).
- **Bad login is `422`, not `401`.** Invalid credentials surface as a `ValidationException` on the
  `email` field; `401` is reserved strictly for missing/invalid/revoked **tokens**.
- **Sanctum token auth.** Tokens are issued on register/login; `logout` revokes the current token via
  `currentAccessToken()->delete()`.

## What I'd harden next

Deferred on purpose to hold scope to the brief — each is a small, well-understood addition:

- **Rate limiting** on `login`/`register` (a `throttle:` middleware) to blunt credential-stuffing and signup spam.
- **Token expiration** (Sanctum `expiration`) as a TTL backstop alongside logout-based revocation.
- **Stronger password rules** via `Rules\Password::defaults()` (length + breach checks) instead of bare `min:8`.
