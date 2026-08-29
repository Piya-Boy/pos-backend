# back.md — Laravel Backend Spec (port cp-pos Apps Script → Laravel + Google Sheets)

> **Status**: Spec for coding AI. Claude = PM.
> **Goal**: Reimplement the cp-pos Apps Script backend (`Code.js`/`Services.js`/`Admin.js`/`Database.js`) as a Laravel 12 REST API, keeping Google Sheets as the source-of-truth DB. Business logic ported 1:1.
> **Consumer**: the Flutter frontend (`front.md`) — same request/response shapes the frontend `ApiClient` expects.
> **Note**: Thai user-facing error/message strings stay verbatim (frontend shows them). Code/comments English.

---

## 0. Locked decisions

| Area | Decision |
|---|---|
| Framework | Laravel 12 (exists at ``, empty skeleton) |
| DB | **Google Sheets** = source of truth. No MySQL for domain data. |
| Sheets access | Google Sheets REST API **v4 over Laravel `Http` client** (no `google/apiclient` package) |
| Google auth | **Service Account** (JSON key) → sign JWT (RS256) → exchange for OAuth2 access token; cache token in Redis until expiry |
| Staff auth | Staff sheet + `PINHash` (salted SHA-256), same as cp-pos. Laravel issues an **opaque bearer token stored in Redis** (`auth:{token}` → session JSON). **Not Sanctum** — Sanctum needs a MySQL `personal_access_tokens` table, which contradicts the no-MySQL-domain rule. Ports the cp-pos CacheService auth model directly. |
| Lock | **Redis** atomic lock (`Cache::lock`) replaces Apps Script `LockService` |
| Cache | **Redis** — catalog/settings/session mirror + Google token, to cut Sheets read quota |
| Realtime | **Polling** this phase (frontend polls REST). Reverb/WebSocket = later phase. |
| Scope | **All endpoints**: customer + kitchen/staff/cashier ops + admin (~20 methods) |

**Why Redis is mandatory**: Sheets API ≈ 60 reads/min/user, 300 writes/min. No real row-lock or transaction. Redis provides (1) atomic lock for order submit / close table / settings save (idempotency + race safety, ports `LockService.tryLock`), (2) cache to keep polling under quota, (3) Google access-token store.

---

## 1. Architecture

```
Flutter (front.md ApiClient)
   │  REST JSON  { ok, data, error{code,message,details} }
   ▼
Laravel 12 API
   ├─ Routes (routes/api.php)  — public + auth-protected groups
   ├─ Controllers (thin)       — validate input, call services, wrap response
   ├─ Middleware               — StaffAuth (bearer → session), role gate
   ├─ Services (business logic) — ported from Services.js / Admin.js
   │    OrderService, CatalogService, AuthService, OpsService,
   │    PaymentService, AdminService, SettingsService
   ├─ Sheets layer             — ★ only code that touches Google Sheets
   │    SheetsClient (HTTP v4) + GoogleTokenProvider (SA JWT)
   │    SheetRepository (readObjects/append/update/upsert/find — ports Database.js)
   ├─ Support                  — IdempotencyManager, LockManager (Redis),
   │    CatalogCache, Totals calculator, Hash/Uuid/Money helpers
   └─ Redis                    — lock + cache + auth tokens + Google token
   │  Sheets API v4
   ▼
Google Spreadsheet (14 sheets, schema from cp-pos/Database.js SHEET_SCHEMAS)
```

**Golden rule**: every Sheet read/write goes through `SheetRepository`. Services never call `SheetsClient` directly. Swapping Sheets→MySQL later touches only the repository.

---

## 2. Sheet schema (source `cp-pos/Database.js:1-15`)

14 sheets, headers verbatim. Repository maps row ↔ associative array keyed by header.

| Sheet | Key | Headers |
|---|---|---|
| Tables | TableID | TableID, Name, Zone, Token, Status, CurrentSessionID, CreatedAt, UpdatedAt |
| Categories | CategoryID | CategoryID, Name, Icon, SortOrder, Status, CreatedAt, UpdatedAt |
| MenuItems | ItemID | ItemID, CategoryID, Name, Price, Description, ImageURL, Status, SortOrder, IsPopular, CreatedAt, UpdatedAt |
| Options | OptionID | OptionID, ItemID, GroupName, Label, Price, InputType, IsRequired, SortOrder, Status |
| AddOns | AddOnID | AddOnID, Name, Price, LinkedItemID, LinkedCategoryID, Status, SortOrder |
| Promotions | PromoID | PromoID, Code, Name, Description, DiscountType, DiscountValue, MinSpend, StartDate, EndDate, BannerImage, Status |
| OrderSessions | SessionID | SessionID, TableID, OpenTime, CloseTime, Status, Subtotal, Discount, ServiceCharge, Vat, Total, PromoCode, PaymentMethod, CreatedBy, IdempotencyKey, UpdatedAt |
| OrderItems | OrderItemID | OrderItemID, SessionID, RequestKey, ItemID, ItemName, Qty, UnitPrice, OptionsJSON, AddOnsJSON, Note, LineTotal, Status, KitchenNote, CreatedAt, UpdatedAt |
| CallLogs | LogID | LogID, TableID, SessionID, Type, Status, AssignedStaffID, IdempotencyKey, CreatedAt, AcceptedAt, CompletedAt |
| Payments | PaymentID | PaymentID, SessionID, IdempotencyKey, Amount, Method, Reference, PaidAt, StaffID |
| Transactions | TransactionID | TransactionID, Type, IdempotencyKey, EntityID, Status, CreatedAt, UpdatedAt, ResultJSON |
| Staff | StaffID | StaffID, Name, PINHash, Role, Status, MustChangePin, CreatedAt, UpdatedAt, LastLogin |
| Settings | Key | Key, Value, UpdatedAt |
| AuditLog | (append) | Timestamp, StaffID, Action, EntityType, EntityID, DetailJSON |

**Status enums**: Table AVAILABLE/OCCUPIED/PAYMENT_PENDING/DISABLED; Session OPEN/PAYMENT_PENDING/PAID/CLOSED/CANCELLED; OrderItem NEW/PREPARING/READY/SERVED/CANCELLED; Call OPEN/ASSIGNED/DONE; Roles ADMIN/KITCHEN/STAFF/CASHIER.

---

## 3. Sheets layer (the only Google-touching code)

### 3.1 GoogleTokenProvider
- Load Service Account JSON (path from `.env` `GOOGLE_SA_KEY_PATH` or inline `GOOGLE_SA_KEY_JSON`).
- Build JWT: header `{alg:RS256,typ:JWT}`, claim `{iss: client_email, scope: "https://www.googleapis.com/auth/spreadsheets", aud: "https://oauth2.googleapis.com/token", iat, exp: iat+3600}`. Sign RS256 with private key.
- POST `https://oauth2.googleapis.com/token` grant `urn:ietf:params:oauth:grant-type:jwt-bearer` → `access_token`.
- Cache token in Redis key `google:sa:token` with TTL = expiry − 60s. Reuse until near expiry.

### 3.2 SheetsClient (HTTP v4)
- Base `https://sheets.googleapis.com/v4/spreadsheets/{SPREADSHEET_ID}` (`.env GOOGLE_SPREADSHEET_ID`). Bearer = token from provider.
- `getValues(range)` → GET `/values/{range}` (e.g. `Tables!A1:Z`).
- `appendValues(range, rows)` → POST `/values/{range}:append?valueInputOption=RAW`.
- `updateValues(range, rows)` → PUT `/values/{range}?valueInputOption=RAW`.
- `batchGet(ranges[])` → GET `/values:batchGet?ranges=...` (fetch several sheets in one call — quota saver).
- Retry on 429/5xx with backoff (max 3). Throw `AppError('SHEETS_ERROR', ...)` on final failure.

### 3.3 SheetRepository (ports Database.js helpers)
Interface (per sheet, header-keyed rows):
- `all(sheet): array` → ports `readSheetObjects_` (row 1 = headers, `_row` = 1-based row index for updates). Uses `CatalogCache` where applicable.
- `find(sheet, keyField, value): ?array` → `findObject_`.
- `append(sheet, rows): void` → `appendObjects_`.
- `update(sheet, keyField, keyValue, patch): array` → `updateObject_` (locate row, patch only present columns, return updated).
- `upsert(sheet, keyField, object): array` → `upsertObject_`.
- Column-index resolution from header row; date cells → ISO string.
- **Write path MUST read fresh (never cached)**: `find`/`update`/`upsert` resolve the target row by re-reading the sheet live (`getValues`), never from `CatalogCache`. A stale `_row` index writes the wrong row. Only read-only catalog/settings reads may use cache.
- **Write path caveat**: `update` reads sheet to find row index, then writes one range. Wrap mutating flows in a Redis lock (§4) to avoid lost updates.
- **Manual-edit hazard (Sheet is human-editable by design)**: Redis locks only serialize Laravel writes — they cannot stop a human editing the sheet in the Google UI mid-write, which shifts row indexes. Mitigations: (a) resolve row by **key column match at write time**, not by a previously cached `_row`; (b) prefer append-only for OrderItems/Payments/AuditLog/Transactions (never rewrite historical rows); (c) document that admins should edit catalog/settings sheets, not live OrderSessions/OrderItems, while service is running.

---

## 4. Concurrency, idempotency, cache

### 4.1 LockManager (Redis) — ports `LockService`
- `withLock(name, ttlMs, fn)`: acquire `Cache::lock($name, $ttlSeconds)`; if not acquired within block time → throw `AppError('BUSY', 'ระบบกำลัง... กรุณาลองอีกครั้ง')` (message per call site, verbatim from cp-pos). Release in finally.
- Lock names: `lock:order` (submit), `lock:payment` (close table), `lock:call` (callStaff), `lock:settings` (save settings). Mirror cp-pos `tryLock` timeouts: order 15s, payment 15s, call 5s, settings 10s.

### 4.2 IdempotencyManager — ports Transactions sheet flow (`Services.js:396-415`)
- `begin(type, key)`: check `Transactions` by IdempotencyKey. If COMPLETED → return cached `ResultJSON` (short-circuit). If PROCESSING/exists → mark PROCESSING. Else append new PROCESSING row.
- `complete(key, entityId, result)`: set COMPLETED + store `ResultJSON`.
- `fail(key)`: set FAILED.
- Used by `submitOrder` and `closeTable`. Combined with LockManager: acquire lock → `begin` (return if cached) → do work → `complete`/`fail`.

### 4.3 CatalogCache (Redis)
- `public-catalog` (categories+menu+options+addons+promotions joined, `Services.js:71`) TTL 120s. Invalidate on any admin catalog save/archive (`clearPublicCatalogCache_`).
- `settings-map` TTL 120s. Invalidate on settings save.
- `auth:{token}` staff session TTL = `AUTH_TTL_SECONDS` (define in config, default 6h). Refresh TTL on each `requireAuth`.

---

## 5. Auth (staff PIN → bearer token)

Ports `Admin.js:18-84`.
- **Token store**: opaque `Str::random(64)` in Redis `auth:{token}`. Do NOT use Sanctum (needs MySQL table). Middleware reads Redis directly.
- **Salt**: read/create `AUTH_SALT` — store in `.env` (preferred, stable across restarts) or Redis. Must never change once staff PINs exist, or all logins break. PIN hash = `sha256(salt + ':' + pin)`.
- **login** (`loginStaff`): validate PIN `^[A-Za-z0-9]{4,12}$`; match Staff row by `PINHash` + `Status=ACTIVE`; prefer `expectedRole`, else fall back to ADMIN. Issue opaque token (`Str::random(64)` or Sanctum), store session `{staffId,name,role,issuedAt,mustChangePin}` in Redis `auth:{token}`. Update `LastLogin`. Audit LOGIN. Return `{token, user}`.
- **logout**: delete `auth:{token}`.
- **changeMyPin**: require auth; new PIN `^[A-Za-z0-9]{6,12}$`; reject if == initial PIN (`zaq1234`); update PINHash + `MustChangePin=false`. Audit.
- **requireAuth(token, roles[])** middleware: 401 `AUTH_REQUIRED` if no token; `AUTH_EXPIRED` if not in Redis; reload Staff, ensure `Status=ACTIVE` (`PERMISSION_DENIED`); enforce `roles` unless role==ADMIN (ADMIN passes all); refresh TTL.

---

## 6. Endpoints (routes/api.php)

All responses wrap `{ ok: bool, data?: T, error?: {code, message, details?} }`. **HTTP 200 always** — errors carried in body, mirroring cp-pos `api_`. Frontend checks `response.ok !== true` from the body and ignores HTTP status (`App.html:113`), so this is required for parity.

**Global envelope enforcement** (critical): register a handler in `bootstrap/app.php` `->withExceptions()` that catches ALL exceptions for `/api/*` and renders the envelope with HTTP 200:
- `AppError` → `{ok:false, error:{code, message, details}}`.
- Laravel `ValidationException` (422) → `{ok:false, error:{code:'VALIDATION', message: firstError, details: allErrors}}`.
- Any other `Throwable` → `{ok:false, error:{code:'SERVER_ERROR', message:'เกิดข้อผิดพลาด กรุณาลองใหม่'}}` (log the real error).
Success responses wrap via a helper `apiOk($data)` → `{ok:true, data:$data}`. Controllers return `apiOk(...)`; they never build the raw envelope by hand.

### 6.1 Public (customer) — no auth
| Method+Path | Body | Service | Source |
|---|---|---|---|
| POST `/api/bootstrap` | `{tableToken?}` | SettingsService.bootstrap | `getPublicBootstrap` Services.js:1 |
| POST `/api/customer` | `{tableToken}` | CatalogService.customerData | `getCustomerData` Services.js:48 |
| POST `/api/order/submit` | `{tableToken, idempotencyKey, promoCode, items[]}` | OrderService.submit | `submitOrder` Services.js:159 |
| POST `/api/order/status` | `{tableToken, sessionId}` | OrderService.status | `getOrderStatus` Services.js:147 |
| POST `/api/call` | `{tableToken, type, idempotencyKey}` | OrderService.callStaff | `callStaff` Services.js:292 |

### 6.2 Auth
| POST `/api/auth/login` | `{pin, expectedRole?}` | AuthService.login |
| POST `/api/auth/logout` | `{token}` | AuthService.logout |
| POST `/api/auth/change-pin` | `{token, newPin}` | AuthService.changePin |

### 6.3 Ops (auth: role-gated)
| POST `/api/ops/dashboard` | `{token, view}` view=KITCHEN/STAFF/CASHIER/ALL | OpsService.dashboard | `getOpsDashboard` Admin.js:86 |
| POST `/api/ops/order-status` | `{token, orderItemId, status, kitchenNote?}` | OpsService.updateOrderItem (KITCHEN→PREPARING/READY, STAFF→SERVED, ADMIN→any) | Admin.js:153 |
| POST `/api/ops/call-status` | `{token, logId, status}` status=ASSIGNED/DONE | OpsService.updateCall | Admin.js:174 |
| POST `/api/ops/close-table` | `{token, sessionId, method, reference?, idempotencyKey}` | PaymentService.closeTable (lock+idempotency, receipt) | Admin.js:191 |

### 6.4 Admin (auth: ADMIN only)
| POST `/api/admin/data` | `{token}` | AdminService.getData (all sheets + summary + orderUrl per table) | Admin.js:262 |
| POST `/api/admin/settings` | `{token, settings{...}}` | SettingsService.save (brand keys, hex/percent/url validation, polling 5–60) | Admin.js:287 |
| POST `/api/admin/entity` | `{token, entity, data{...}}` | AdminService.saveEntity (8 entities, upsert, PIN for staff, cache invalidation) | Admin.js:351 |
| POST `/api/admin/entity/archive` | `{token, entity, id}` | AdminService.archiveEntity (guards: category-in-use, self-archive) | Admin.js:392 |
| POST `/api/admin/table/rotate-token` | `{token, tableId}` | AdminService.rotateToken (blocked if table in use) | Admin.js:420 |

---

## 7. Core business logic to port (exact)

- **Totals** (`recalculateSessionTotals_` Services.js:357): subtotal = Σ non-cancelled LineTotal; promo discount (PERCENT: subtotal×value/100; FIXED: min(subtotal,value)) if subtotal≥MinSpend; net = max(0, subtotal−discount); serviceCharge = net×ServiceChargePercent/100; vat = (net+serviceCharge)×VatPercent/100; total = net+serviceCharge+vat. Persist to session. **Round each money value to 2 decimals** (`money_`).
- **Order submit** (`createOrder_` Services.js:186): recover by RequestKey (idempotent); reuse open session or create; validate each item ACTIVE, qty 1–20; validate required option groups; compute unitPrice = itemPrice + Σ option price + Σ addon price; LineTotal = unitPrice×qty; set Table OCCUPIED. Max 50 items/submit.
- **Add-on scope** (`addOnAppliesToItem_` Services.js:139): global (no link/ALL) OR LinkedItemID==item OR LinkedCategoryID==item.category.
- **Public catalog join** (`getPublicCatalog_` Services.js:71): active categories sorted; per menu item attach active options + scoped add-ons (dedup); `available = Status==ACTIVE`; exclude ARCHIVED.
- **Close table** (`closeTable` Admin.js:191): lock+idempotency; session must be OPEN/PAYMENT_PENDING; recompute totals; method in CASH/TRANSFER/CARD/OTHER; append Payment; session→PAID; table→AVAILABLE + clear CurrentSessionID; close open calls; build receipt. **Write order matters** (no multi-sheet transaction in Sheets): append Payment FIRST (idempotency anchor — keyed by IdempotencyKey), then Session→PAID, then Table reset, then calls. If a later write fails mid-way, `IdempotencyManager` marks FAILED; a retry with the same key finds the existing Payment (dedup by IdempotencyKey, Admin.js:209) and completes the remaining writes without double-charging. Document this recovery path.
- **Ops dashboard filtering** (Admin.js:86): active = sessions OPEN/PAYMENT_PENDING; items non-cancelled joined with table; view decides which of items/sessions/calls returned; summary counts.
- **Audit** (`audit_` Database.js:428): append every mutating action to AuditLog.
- Helpers to port: `money_` (round 2dp), `uuid_(prefix)`, `sha256_`, `normalizeText_(v,len)`, `number_`, `bool_/truthy`, `nowIso_`, `safeJson_`, `sanitizeHttpsUrl_`, `validateHexColor_`, `validatePercentSetting_`.

---

## 8. Setup / seeding (ports Database.js setupSystem)

Provide an artisan command `php artisan pos:setup` that (idempotently):
- Ensures all 14 sheets exist with header rows (`ensureSheet_`). Creating sheets uses Sheets `batchUpdate` addSheet.
- Seeds Tables (T01–T12, zones, tokens), Categories (5), MenuItems (8), Options, AddOns, Promotions (WELCOME10), Staff (4 roles, PIN `zaq1234`, MustChangePin), Settings defaults. Data verbatim from `Database.js:251-320`.
- Prints initial PINs note (Thai verbatim). Safe to re-run.

---

## 9. Config / .env

```
GOOGLE_SPREADSHEET_ID=
GOOGLE_SA_KEY_PATH=storage/google/service-account.json   # or GOOGLE_SA_KEY_JSON=
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
CACHE_STORE=redis
POS_AUTH_TTL_SECONDS=21600     # 6h
POS_INITIAL_PIN=zaq1234
```
`config/pos.php`: spreadsheetId, saKeyPath, authTtl, initialPin, lock timeouts, cache TTLs, lists (payment methods, roles, statuses).

---

## 10. Testing strategy

- **Unit** (no network): Totals calculator, add-on scope, required-option validation, money rounding, PIN hash, idempotency logic — pure PHP, fully covered.
- **Repository**: mock `SheetsClient` (fake in-memory sheets) so services test without hitting Google. `FakeSheetsClient` seeded like §8.
- **Feature (HTTP)**: hit routes with `FakeSheetsClient` bound in container; assert response envelope, auth gating, role enforcement, idempotency (same key → same result), lock behavior (concurrent submit → one wins/one BUSY or idempotent).
- Never hit real Sheets in CI. A separate manual smoke test (real SA key) validates GoogleTokenProvider + SheetsClient against a throwaway spreadsheet.

---

## 11. Definition of Done

1. All §6 endpoints implemented, response envelope matches frontend `ApiClient` (`front.md` §6).
2. Sheets touched only via `SheetRepository`; `FakeSheetsClient` lets full test suite run offline.
3. Redis lock + idempotency verified by feature tests (concurrent order, double close-table).
4. Auth: login/logout/change-pin + role gating + ADMIN override, tokens in Redis.
5. Totals/promo/service-charge/VAT match cp-pos to the satang (2dp).
6. `pos:setup` seeds a fresh spreadsheet; customer flow works end-to-end with the Flutter app pointed at Laravel.
7. Thai user-facing strings verbatim.

---

## 12. Deferred (next phase)

- Reverb/WebSocket realtime broadcast (order/call events) — replaces polling.
- Drive image upload (`compressImage`/`ensureDriveFolders_`) — admin image assets.
- PDF receipt (`PdfService.js`) — currently JSON receipt only.
- MySQL mirror / read-replica if Sheets quota still tight after caching.

---

## 13. When unsure

- Laravel/PHP API, Http client, Redis cache: look up **https://laravel.com/framework/docs/** (v12) and the package docs. Do not guess signatures.
- Google Sheets API v4 / Service Account JWT: https://developers.google.com/sheets/api and https://developers.google.com/identity/protocols/oauth2/service-account.
