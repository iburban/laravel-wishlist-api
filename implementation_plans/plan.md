# Wishlist API — Implementation Plan

Back-end-only Laravel JSON API for an e-commerce "Wishlist" feature: users register,
log in (token-based), browse products, and add / view / remove products on their own
wishlist.

## 0. Verified environment (from skeleton inspection)

| Thing | Value |
|---|---|
| Laravel | **v13.15.0** (`composer.json` requires `^13.8`) |
| PHP | 8.3.14 |
| Composer | 2.8.3 |
| Dev DB | MySQL 8 (in untracked `.env`) |
| Test DB | SQLite `:memory:` (already set in `phpunit.xml`) |
| Sanctum | **not installed** — added in Phase 2 via `php artisan install:api` |
| `routes/api.php` | **does not exist** — created by `install:api` |
| `bootstrap/app.php` | already has `shouldRenderJsonWhen(api/*)`; `install:api` adds the `api:` routing entry |
| User model | uses Laravel 13 attribute config `#[Fillable]` / `#[Hidden]` (not `$fillable`/`$hidden`) |
| `.env` hygiene | `.env` gitignored and **never committed** (`git log --all -- .env` empty); only `.env.example` tracked |

**Laravel-13-specific commands/conventions to use:**
- Add Sanctum + API routing with **`php artisan install:api`** (do *not* hand-wire or use the L10/L11 `sanctum:install`).
- Keep the User model's PHP-attribute style; add `HasApiTokens` as a `use` trait.
- Register error normalisation in `bootstrap/app.php`'s `withExceptions()` closure (no `app/Exceptions/Handler.php` in L13).

---

## 1. Data model

### `users` (skeleton — unchanged)
Existing migration. We only add the `HasApiTokens` trait to the model.

### `personal_access_tokens` (added by `install:api`)
Sanctum's standard migration. Engine-agnostic. Untouched.

### `products`
| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `bigIncrements` | PK |
| `name` | `string` (255) | not null |
| `description` | `text` | nullable |
| `price` | `decimal(10,2)` | not null; model cast `decimal:2` (stays a string, no float drift) |
| `created_at` / `updated_at` | `timestamps` | |

### `wishlists`
One flat table = one implicit wishlist per user.

| Column | Type | Constraints / notes |
|---|---|---|
| `id` | `bigIncrements` | PK |
| `user_id` | `foreignId` → `users.id` | `constrained()->cascadeOnDelete()`, indexed |
| `product_id` | `foreignId` → `products.id` | `constrained()->cascadeOnDelete()`, indexed |
| `created_at` / `updated_at` | `timestamps` | |
| — | **`unique(['user_id','product_id'])`** | **DB-level source of truth for duplicate prevention** |

Notes:
- The composite unique index also serves "all rows for this user" lookups (leftmost-prefix `user_id`).
- `decimal`, `foreignId`, `unique` all behave identically on MySQL 8 and SQLite → migrations stay engine-agnostic. No MySQL-only DDL.
- FK cascade is enforced at DB level (MySQL always; SQLite when `PRAGMA foreign_keys=ON`). The app never relies on cascade for correctness — ownership scoping is enforced in queries; the unique index is enforced by both engines.

### Eloquent models & relationships
- `User` → `hasMany(Wishlist)`; `use HasApiTokens, HasFactory, Notifiable`.
- `Product` → `hasMany(Wishlist)`; `#[Fillable(['name','price','description'])]`; cast `price => decimal:2`.
- `Wishlist` → `belongsTo(User)`, `belongsTo(Product)`; `#[Fillable(['user_id','product_id'])]`.

---

## 2. Error envelope (one consistent shape)

All error responses for `/api/*` use:

```json
{ "message": "Human-readable summary.", "errors": { "field": ["..."] } }
```

`errors` is present **only** on 422 (matches Laravel's native `ValidationException` shape, so we
don't fight the framework). All other errors are `{ "message": "..." }`.

| Status | When | Body |
|---|---|---|
| 401 | No/invalid Sanctum token on a protected route | `{"message":"Unauthenticated."}` |
| 403 | Reserved for genuine authorization failures (not used by current endpoints — see note) | `{"message":"..."}` |
| 404 | Resource not found, **including removing an item not in MY wishlist** (no existence leak) | `{"message":"Resource not found."}` |
| 409 | Adding a product already on MY wishlist (DB unique violation) | `{"message":"Product is already in your wishlist."}` |
| 422 | Validation failure | `{"message":"...","errors":{...}}` |

Success responses use API Resources, which wrap payloads in `data` (`{"data": ...}`).

**Why 404 (not 403) on remove-not-mine:** removing another user's item and removing a
non-existent item return the *same* 404, so the API never reveals whether a given
`(user,product)` pairing exists for someone else → no IDOR existence leak.

**On 403:** the current endpoints don't need it — ownership is enforced by *scoping queries
to `auth()->id()`*, so a foreign item is simply "not found" (404). The envelope/handler still
supports 403 for correctness, but no route emits it. Noted to avoid an invented use.

**Normalisation** happens in `bootstrap/app.php` `withExceptions()`:
- `AuthenticationException` → 401 envelope.
- `ModelNotFoundException` / `NotFoundHttpException` → 404 envelope (generic message, no model name).
- `DuplicateWishlistItemException` (custom) → 409 envelope (or via the exception's own `render()`).
- `ValidationException` keeps Laravel's native 422 shape (already matches our envelope).
Because `shouldRenderJsonWhen(api/*)` is already set, `/api/*` always returns JSON, never an HTML redirect.

---

## 3. Endpoints

Base prefix `/api` (from `routes/api.php`). Public = no auth; Protected = `auth:sanctum`.

| Method | Path | Auth | Request body | Success | Error codes |
|---|---|---|---|---|---|
| POST | `/api/register` | public | `{name, email, password, password_confirmation}` | **201** `{"data":{"user":{...},"token":"..."}}` | 422 |
| POST | `/api/login` | public | `{email, password}` | **200** `{"data":{"user":{...},"token":"..."}}` | 422 |
| POST | `/api/logout` | **protected** | — | **200** `{"message":"Logged out."}` | 401 |
| GET | `/api/products` | **public** | — | **200** `{"data":[Product,...]}` | — |
| GET | `/api/wishlist` | **protected** | — | **200** `{"data":[WishlistItem,...]}` (product eager-loaded) | 401 |
| POST | `/api/wishlist` | **protected** | `{product_id}` | **201** `{"data":WishlistItem}` | 401, 422, 409 |
| DELETE | `/api/wishlist/{product}` | **protected** | — | **200** `{"message":"Product removed from wishlist."}` | 401, 404 |

Decisions baked in from review answers:
- **Register returns a token** (201), same `{user, token}` shape as login → client is authenticated immediately.
- **Add takes `product_id` in the JSON body** (validated by a Form Request), not the URL.
- **Remove returns 200 + message** (not 204), keeping every response a JSON envelope.
- **Remove identifies the product by id in the URL** (`/api/wishlist/{product}`), scoped to the caller.

Additional decisions (resolved with reviewer):
- **`GET /api/products` is PUBLIC**, outside the `auth:sanctum` group. An e-commerce catalog is
  browse-before-login in the real flow, and a public route sitting alongside the guarded ones
  demonstrates the routing/middleware fundamental the brief lists as an objective. The three
  wishlist routes still prove the 401 guard.
- **`POST /api/logout` is PROTECTED** — revokes the *current* token via
  `$request->user()->currentAccessToken()->delete()`, returns 200 `{"message":"Logged out."}`.
  This is the closing bracket of the token auth the brief asked for (it demonstrates token
  revocation), not a new feature. Lands in **Phase 2**.
- **Invalid login credentials → 422** (`ValidationException` on the `email` field, "These
  credentials do not match our records."), matching Laravel's built-in convention. `401` is
  reserved strictly for missing/invalid *tokens*.

### Public vs protected routing split
`routes/api.php` declares two groups, which is itself the routing/middleware fundamental on display:
- **Public** (no middleware): `POST /api/register`, `POST /api/login`, `GET /api/products`.
- **Protected** (`->middleware('auth:sanctum')`): `POST /api/logout`, `GET /api/wishlist`,
  `POST /api/wishlist`, `DELETE /api/wishlist/{product}`.

### Resource shapes (API Resources for every response)
- `UserResource`: `{id, name, email, created_at}`.
- `ProductResource`: `{id, name, description, price, created_at}` (`price` as `"19.99"` string).
- `WishlistResource`: `{id, product: ProductResource (whenLoaded), created_at}` — **never exposes `user_id`**.
- `AuthResource` (or structured controller payload): `{user: UserResource, token: string}` → wrapped in `data`.

---

## 4. Architecture

- **Thin controllers**, constructor-injected services (no facades/singletons reached for inside controllers):
  - `AuthController(AuthService $auth)` → `register()`, `login()`, `logout()` (revokes current token; thin, no service needed).
  - `ProductController` → `index()` (trivial; lists via the model/Resource).
  - `WishlistController(WishlistService $wishlist)` → `index()`, `store()`, `destroy()`.
- **Service / action layer:**
  - `AuthService::register(array $data): array` → creates user, issues token, returns `[user, token]`.
  - `AuthService::login(array $data): array` → verifies creds (throws `ValidationException` on mismatch), issues token.
  - `WishlistService::list(User $user): Collection` → `$user->wishlists()->with('product')->latest()->get()` (**eager-load → no N+1**).
  - `WishlistService::add(User $user, int $productId): Wishlist` → **atomic** insert; on unique violation throws `DuplicateWishlistItemException`.
  - `WishlistService::remove(User $user, int $productId): void` → `$user->wishlists()->where('product_id',$productId)->firstOrFail()->delete()` → scoped; missing ⇒ `ModelNotFoundException` ⇒ 404.
- **Form Requests** for validation: `RegisterRequest`, `LoginRequest`, `StoreWishlistRequest`.
- **API Resources** for every response shape (above).
- **Custom exception:** `DuplicateWishlistItemException` (409).

### Atomic, concurrency-safe duplicate prevention (the crux)
The DB `unique(user_id, product_id)` index is the source of truth — **no SELECT-then-INSERT
pre-check**. `WishlistService::add()`:
```
use Illuminate\Database\UniqueConstraintViolationException;

try {
    return $user->wishlists()->create(['product_id' => $productId]);
} catch (UniqueConstraintViolationException $e) {
    throw new DuplicateWishlistItemException();   // → 409
}
```
**Why this class, not a SQLSTATE sniff:** PDO_SQLite does **not** reliably report `23000` for a
unique violation (it often surfaces `HY000`), so `$e->getCode() === '23000'` would *miss* on the
SQLite `:memory:` test DB, re-throw, and turn the 409 test into a 500. Catching
`Illuminate\Database\UniqueConstraintViolationException` instead lets the **framework** do the
engine-specific detection — confirmed present in v13.15: `Connection.php` wraps a query error in
this class when the per-driver `isUniqueConstraintError()` matches, and both
`SQLiteConnection::isUniqueConstraintError()` and `MySqlConnection::isUniqueConstraintError()`
exist. So the catch is portable across MySQL 8 and SQLite.

Two concurrent identical adds: exactly one INSERT wins at the DB, the loser catches the
`UniqueConstraintViolationException` → clean 409, never a 500. (`product_id` validity is enforced
earlier by `exists:products,id` in the Form Request → bad id is 422, not 409.)

---

## 5. Validation rules (Form Requests)

| Request | Rules |
|---|---|
| `RegisterRequest` | `name`: required, string, max:255 · `email`: required, email, max:255, unique:users · `password`: required, string, min:8, confirmed |
| `LoginRequest` | `email`: required, email · `password`: required, string |
| `StoreWishlistRequest` | `product_id`: required, integer, exists:products,id |

All return the native 422 envelope on failure.

---

## 6. Test plan (PHPUnit; all green on SQLite `:memory:`, `RefreshDatabase`)

### Feature — `tests/Feature/`
**Auth (`AuthTest`)**
- register success → 201, body has `data.token` + `data.user`, user row exists, password hashed.
- register validation: missing fields / invalid email / duplicate email / unconfirmed password → 422 with `errors`.
- login success → 200, returns a token.
- login wrong password / unknown email → 422 (`email` error).
- login validation (missing fields) → 422.
- **logout** → authenticated `POST /api/logout` → 200 `{"message":"Logged out."}`, then the **same token** on a protected route (e.g. `GET /api/wishlist`) → **401** (token revoked).

**Auth guard (`AuthGuardTest`)**
- Unauthenticated `GET/POST /api/wishlist`, `DELETE /api/wishlist/{id}`, `POST /api/logout` → **401 JSON** (assert `Content-Type: application/json`, not a redirect).
- Unauthenticated `GET /api/products` → **200** (public route, no guard).

**Products (`ProductTest`)**
- `GET /api/products` **without a token** → 200, returns all seeded products in `ProductResource` shape (catalog is public).

**Wishlist (`WishlistTest`)**
- add product → 201, row in `wishlists`, response is `WishlistResource` with product.
- **list returns ONLY my items**: seed user B with their own items; user A's `GET /api/wishlist` excludes B's rows (isolation assertion).
- **no N+1**: assert the product relation is eager-loaded (`DB::enableQueryLog()` / fixed query count, or `relationLoaded('product')`).
- remove my item → 200 `{"message":...}`, row gone.
- **duplicate add → 409** (and only one row exists).
- **remove another user's item → 404**; remove a product not on my list → 404 (same response → no leak).
- add with missing / non-integer / non-existent `product_id` → 422.

### Unit — `tests/Unit/`
**`WishlistServiceTest`**
- `add` returns a persisted `Wishlist`.
- `add` twice for same `(user, product)` → throws `DuplicateWishlistItemException`.
- `remove` for an item not owned → throws `ModelNotFoundException`.
- `list` is scoped to the user and eager-loads `product`.

**`AuthServiceTest`**
- `register` creates user + returns token string.
- `login` with wrong password throws `ValidationException`.

---

## 7. Phases (one commit each — you commit)

| # | Scope | Commit message |
|---|---|---|
| 1 | This `implementation_plans/plan.md` | `Add implementation plan` |
| 2 | `php artisan install:api` (Sanctum + `routes/api.php` + api routing); `HasApiTokens` on User; `AuthService`, `RegisterRequest`, `LoginRequest`, `UserResource`, `AuthController`; **register + login + logout** routes (logout protected, revokes current token); **auth tests** (incl. logout → token revoked → 401) | `Add token auth with tests` |
| 3 | `Product` model + migration + factory; `ProductSeeder` (~10 rows) wired into `DatabaseSeeder`; `GET /api/products`; `ProductResource`; product/list test | `Add products and listing endpoint` |
| 4 | `Wishlist` model + migration (`unique(user_id,product_id)` + FKs/indexes); `WishlistService`, `DuplicateWishlistItemException`, `StoreWishlistRequest`, `WishlistResource`; add/list/remove endpoints with ownership scoping + eager-load + atomic dup-prevention — **plus the wishlist feature + unit suite** (add, isolation/only-my-items, no-N+1, remove, 409 duplicate, 404-not-mine, 422 validation) | `Add wishlist endpoints with ownership scoping and duplicate prevention` |
| 5 | Central error-envelope normalisation in `bootstrap/app.php` (401 / 404-generic, validation left native); **consolidated auth-guard test** (every protected route → 401 JSON; public routes reachable) + **error-handling tests** (404 body hides model class, unknown-route 404, 409 not re-handled) | `Add central error handling and auth-guard tests` |
| — | Reviewer subagent pass after Phase 5; applied the triaged fixes (route `whereNumber`, `validated()` value, drop `user_id` from fillable, delete skeleton example tests, +payload/envelope assertions) | `Address review findings` |
| 6a | `Dockerfile` + `docker-compose` (app + MySQL 8, PHP 8.3 pinned, one-command `up` with DB-wait, migrate, idempotent seed, `stderr` logging) + GitHub Actions workflow running the suite on SQLite `:memory:` | `Add Docker setup and CI` |
| 6b | `README.md` (setup, env, migrate/seed, run, test; API docs with request/response examples; design-decisions incl. **real-inventory concurrency via `lockForUpdate` / atomic conditional `UPDATE` contrasted with the wishlist's constraint-based dup-prevention**) + `requests.http`; reconcile this phase table to what shipped | `Add README, API docs, and requests.http` |

### Verification each phase
`php artisan test` (SQLite `:memory:`) **and** `curl` against `http://laravel-wishlist-api.test/api/...`
(register → capture token → authed product/wishlist calls, incl. the 409 and 404 paths). No browser.

---

## 8. Decisions resolved with reviewer

1. `GET /api/products` → **PUBLIC** (catalog is browse-before-login; demonstrates the public/protected routing split).
2. Bad login credentials → **422** (Laravel convention); 401 reserved strictly for missing/invalid tokens.
3. **`POST /api/logout` added** (protected; revokes current token) — the closing bracket of token auth, in Phase 2.
4. Duplicate-prevention catches **`UniqueConstraintViolationException`** (framework does engine-specific detection), not a `23000` SQLSTATE sniff which is unreliable on PDO_SQLite.

No open questions — ready for your review/commit, then Phase 2.
