# Laravel Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reimplement the cp-pos Apps Script backend as a Laravel 12 REST API with Google Sheets as source-of-truth DB, Redis for lock/cache/auth, business logic ported 1:1.

**Architecture:** Thin controllers → services (business logic) → `SheetRepository` (only Sheets-touching code) → `SheetsClient` (HTTP v4) + `GoogleTokenProvider` (Service Account JWT). Redis: atomic lock, idempotency, catalog/settings cache, auth tokens. All tests run offline against `FakeSheetsClient`.

**Tech Stack:** Laravel 12, PHP 8.2, Laravel `Http` client, `phpseclib`/`firebase/php-jwt` for RS256 JWT, Redis (`predis` or phpredis), PHPUnit 11.

**Spec:** `back.md` (repo root). Read it before starting — every task references its section numbers.

## Global Constraints

- PHP `^8.2`, Laravel `^12.0` (existing `composer.json`).
- Working dir for all backend commands: ``.
- **Google Sheets is the only domain DB.** No MySQL for domain data. Sheets accessed only through `SheetRepository`.
- Auth = opaque token in Redis (`auth:{token}`). **Not Sanctum** (needs MySQL). (back.md §0/§5)
- Response envelope `{ok, data?, error?{code,message,details?}}`, **HTTP 200 always** — matches frontend `App.html:113` (back.md §6). Global exception handler enforces it.
- Thai user-facing strings **verbatim** from cp-pos. Code/comments English.
- Money: round to 2dp via `money()` helper = `round(($v + PHP_FLOAT_EPSILON) * 100) / 100` (ports `Code.js:197`).
- All tests use `FakeSheetsClient` bound in the container. **Never hit real Google Sheets in CI.**
- Tests use the `array` cache store (no Redis in CI): add `<env name="CACHE_STORE" value="array"/>` to `phpunit.xml`. The `array` store supports `Cache::lock`/`put`/`remember` in-memory for the request lifetime — enough for lock + auth + idempotency-cache assertions. Real Redis only for `php artisan serve` / smoke test (Task 11).
- Commit style: Conventional Commits. Branch off non-main. No `Co-Authored-By`.
- **This repo lives in `` (separate from frontend).** git AND artisan/composer all run from ``. All paths are relative to ``. Task bodies below sometimes write `app/...` for clarity — when running commands you are inside ``, so `git add app routes` (drop the `` prefix). No parent mono repo.
- When unsure of a Laravel/PHP/Sheets API, look it up — do not guess signatures:
  - Laravel/PHP: **https://laravel.com/framework/docs/** (v12)
  - Google Sheets API v4 / Service Account JWT: https://developers.google.com/sheets/api
  - Packages: packagist docs.
- **Build order override**: `SeedData` + `FakeSheetsClient::seedDefaults()`/`firstTableToken()` (Task 8 Steps 1–4) have NO dependency beyond Task 2 and are needed by every Feature test in Tasks 5–7,9. **Do Task 8 Steps 1–4 immediately after Task 3** (before Task 5). Then Tasks 5–7,9 go green in their own "verify pass" steps. Task 8's remaining `pos:setup` artisan command (Step 3's command part, Step 5–6) can stay in place at Task 8. Tasks 1–4 Unit tests are fully self-contained regardless.

---

### Task 0: Branch + deps + config skeleton

**Files:**
- Modify: `composer.json`
- Create: `config/pos.php`
- Modify: `.env.example`

**Interfaces:** none.

**This is a SEPARATE git repo living in ``** — independent from `pos-frontend/`. It was already `git init`'d (main branch) with a Laravel `.gitignore` (already extended to ignore `/storage/google/` SA keys + `.env`). Do NOT init at a parent; there is no mono repo.

**All paths below are relative to ``.** git AND composer/artisan all run from ``. So `git add app routes` — NOT `app`.

- [ ] **Step 1: Branch**
```bash
cd "E:/Develop/CODING/POS/pos-backend"
git checkout -b feature/laravel-backend
```
Never commit on `main`.

- [ ] **Step 2: Add deps**
```bash
cd "E:/Develop/CODING/POS/pos-backend"
composer require firebase/php-jwt predis/predis
php artisan install:api
```
Expected: `composer require` resolves. `install:api` scaffolds `routes/api.php` + registers `api` routing in `bootstrap/app.php` (Laravel 12 ships without api routes by default). It may prompt about Sanctum migrations — **decline/skip Sanctum** (we use Redis tokens, not Sanctum); if it publishes a Sanctum migration, delete it. Confirm `bootstrap/app.php` now has `->withRouting(... api: __DIR__.'/routes/api.php' ...)`.

- [ ] **Step 3: Create config**

Create `config/pos.php`:
```php
<?php
return [
    'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID', ''),
    'sa_key_path' => env('GOOGLE_SA_KEY_PATH', storage_path('google/service-account.json')),
    'sa_key_json' => env('GOOGLE_SA_KEY_JSON', ''),
    'auth_ttl' => (int) env('POS_AUTH_TTL_SECONDS', 21600),
    'initial_pin' => env('POS_INITIAL_PIN', 'zaq1234'),
    'auth_salt' => env('POS_AUTH_SALT', ''),
    'lock_ms' => ['order' => 15000, 'payment' => 15000, 'call' => 5000, 'settings' => 10000],
    'cache_ttl' => ['catalog' => 120, 'settings' => 120],
    'payment_methods' => ['CASH', 'TRANSFER', 'CARD', 'OTHER'],
    'roles' => ['ADMIN', 'KITCHEN', 'STAFF', 'CASHIER'],
    'order_base_url' => env('POS_ORDER_BASE_URL', ''), // Flutter customer URL, used for table QR orderUrl (Task 9)
    'frontend_origin' => env('POS_FRONTEND_ORIGIN', ''), // CORS allowed origin (Task 10, security.md §2.7)
];
```
Append to `.env.example`:
```
GOOGLE_SPREADSHEET_ID=
GOOGLE_SA_KEY_PATH=storage/google/service-account.json
GOOGLE_SA_KEY_JSON=
POS_AUTH_TTL_SECONDS=21600
POS_INITIAL_PIN=zaq1234
POS_AUTH_SALT=
POS_ORDER_BASE_URL=
POS_FRONTEND_ORIGIN=
CACHE_STORE=redis
REDIS_CLIENT=predis
```

- [ ] **Step 4: Commit**
```bash
cd "E:/Develop/CODING/POS/pos-backend"
git add composer.json composer.lock config/pos.php .env.example .gitignore routes bootstrap/app.php
git commit -m "chore: backend deps (jwt, predis), api scaffold, pos config"
```

---

### Task 1: Domain helpers + AppError + envelope

**Files:**
- Create: `app/Pos/Support/Helpers.php` (functions)
- Create: `app/Pos/Support/AppError.php`
- Modify: `bootstrap/app.php` (exception → envelope)
- Test: `tests/Unit/HelpersTest.php`

**Interfaces:**
- Produces:
  - `App\Pos\Support\AppError extends \RuntimeException` with `public string $errCode`, `public mixed $details`; ctor `(string $code, string $message, mixed $details = null)`.
  - Helper functions (namespaced `App\Pos\Support`): `money(float|int|string $v): float`, `uuidPrefixed(string $prefix): string`, `sha256(string $v): string`, `normalizeText(mixed $v, int $max = 0): string`, `numberOr(mixed $v, float $fallback = 0): float`, `boolish(mixed $v): bool`, `nowIso(): string`, `sanitizeHttpsUrl(mixed $v): string`, `apiOk(mixed $data): array`.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/HelpersTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use function App\Pos\Support\{money, sha256, normalizeText, boolish, uuidPrefixed};

class HelpersTest extends TestCase
{
    public function test_money_rounds_2dp(): void
    {
        $this->assertSame(85.0, money(85));
        $this->assertSame(12.5, money(12.5));
        $this->assertSame(10.1, money(10.104));
    }

    public function test_sha256_matches_known(): void
    {
        $this->assertSame(hash('sha256', 'salt:1234'), sha256('salt:1234'));
    }

    public function test_normalize_strips_angle_and_trims_and_limits(): void
    {
        $this->assertSame('abc', normalizeText('  <a>bc  ', 3));
    }

    public function test_boolish(): void
    {
        $this->assertTrue(boolish('TRUE'));
        $this->assertTrue(boolish('1'));
        $this->assertFalse(boolish('no'));
    }

    public function test_uuid_prefixed_has_prefix_and_length(): void
    {
        $id = uuidPrefixed('ord_');
        $this->assertStringStartsWith('ord_', $id);
        $this->assertSame(24, strlen($id)); // prefix 4 + 20 hex
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/HelpersTest.php`
Expected: FAIL — functions not found.

- [ ] **Step 3: Implement helpers + error**

Create `app/Pos/Support/AppError.php`:
```php
<?php
namespace App\Pos\Support;

class AppError extends \RuntimeException
{
    public function __construct(
        public string $errCode,
        string $message,
        public mixed $details = null,
    ) {
        parent::__construct($message);
    }
}
```
Create `app/Pos/Support/Helpers.php` (ports `Code.js`):
```php
<?php
namespace App\Pos\Support;

function money(float|int|string $v): float {
    $n = is_numeric($v) ? (float) $v : 0.0;
    return round(($n + PHP_FLOAT_EPSILON) * 100) / 100;
}

function uuidPrefixed(string $prefix): string {
    return $prefix . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 20);
}

function sha256(string $v): string { return hash('sha256', $v); }

function normalizeText(mixed $v, int $max = 0): string {
    $text = trim(str_replace(['<', '>'], '', (string) ($v ?? '')));
    return $max > 0 ? mb_substr($text, 0, $max) : $text;
}

function numberOr(mixed $v, float $fallback = 0): float {
    return is_numeric($v) ? (float) $v : $fallback;
}

function boolish(mixed $v): bool {
    return $v === true || strtolower((string) $v) === 'true' || (string) $v === '1';
}

function nowIso(): string {
    return \Carbon\Carbon::now('Asia/Bangkok')->format('Y-m-d\TH:i:sP');
}

function sanitizeHttpsUrl(mixed $v): string {
    $url = normalizeText($v, 1000);
    if ($url === '') return '';
    if (!preg_match('#^https://#i', $url)) {
        throw new AppError('INVALID_URL', 'ลิงก์รูปภาพต้องขึ้นต้นด้วย https://');
    }
    return $url;
}

function apiOk(mixed $data): array {
    return ['ok' => true, 'data' => $data ?? null];
}
```
Add to `composer.json` autoload `files`:
```json
"autoload": {
    "psr-4": { "App\\": "app/", "Database\\Factories\\": "database/factories/", "Database\\Seeders\\": "database/seeders/" },
    "files": ["app/Pos/Support/Helpers.php"]
}
```
Run: `cd pos-backend && composer dump-autoload`.

- [ ] **Step 4: Run, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/HelpersTest.php`
Expected: PASS.

- [ ] **Step 5: Global exception → envelope**

Edit `bootstrap/app.php` `->withExceptions(function (Exceptions $exceptions) {...})` (back.md §6):
```php
use App\Pos\Support\AppError;
use Illuminate\Validation\ValidationException;

$exceptions->render(function (\Throwable $e, $request) {
    if (!$request->is('api/*')) return null;
    if ($e instanceof AppError) {
        return response()->json(['ok' => false, 'error' => [
            'code' => $e->errCode, 'message' => $e->getMessage(), 'details' => $e->details,
        ]], 200);
    }
    if ($e instanceof ValidationException) {
        return response()->json(['ok' => false, 'error' => [
            'code' => 'VALIDATION', 'message' => $e->validator->errors()->first(), 'details' => $e->errors(),
        ]], 200);
    }
    report($e);
    return response()->json(['ok' => false, 'error' => [
        'code' => 'SERVER_ERROR', 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่',
    ]], 200);
});
```

- [ ] **Step 6: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos bootstrap/app.php composer.json tests
git commit -m "feat: domain helpers, AppError, api envelope handler"
```

---

### Task 2: SheetsClient interface + FakeSheetsClient

**Files:**
- Create: `app/Pos/Sheets/SheetsClient.php` (interface)
- Create: `app/Pos/Sheets/FakeSheetsClient.php`
- Test: `tests/Unit/FakeSheetsClientTest.php`

**Interfaces:**
- Produces `interface SheetsClient`:
  - `getValues(string $range): array` — returns 2D array (rows of cells), row 0 = headers.
  - `appendValues(string $range, array $rows): void`
  - `updateValues(string $range, array $rows): void` — writes `$rows` starting at `$range` top-left.
  - `batchGet(array $ranges): array` — `['SheetName' => 2D array, ...]`.
- `FakeSheetsClient` — in-memory `array<string, array<int, array>>` keyed by sheet name; `range` like `Tables!A1:Z` or `Tables` → sheet name parsed off `!`. Row writes by A1 offset. Constructor seeds nothing by default; `seedDefaults()` fills §8 data.

- [ ] **Step 1: Write failing test**

Create `tests/Unit/FakeSheetsClientTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Pos\Sheets\FakeSheetsClient;

class FakeSheetsClientTest extends TestCase
{
    public function test_append_then_get(): void
    {
        $c = new FakeSheetsClient();
        $c->updateValues('T!A1:B1', [['H1', 'H2']]);
        $c->appendValues('T!A1', [['a', 'b']]);
        $values = $c->getValues('T!A1:Z');
        $this->assertSame(['H1', 'H2'], $values[0]);
        $this->assertSame(['a', 'b'], $values[1]);
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/FakeSheetsClientTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement interface + fake**

Create `SheetsClient.php` (interface above). Create `FakeSheetsClient.php`: store `$sheets[name]` = list of rows (each a list of cell strings). Parse sheet name = substring before `!`. `getValues` returns full stored rows for the sheet (ignore column bounds, return what exists). `appendValues` pushes rows after last non-empty. `updateValues` overwrites rows starting at row index parsed from the A1 range (e.g. `T!A3:...` → row index 3 → zero-based 2); for header writes `A1` → index 0. `batchGet` maps each range→getValues. Add `seedDefaults()` (implemented in Task 8; leave a `// seeded in Task 8` stub returning `$this` for now).

- [ ] **Step 4: Run, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/FakeSheetsClientTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos/Sheets tests
git commit -m "feat: SheetsClient interface + in-memory FakeSheetsClient"
```

---

### Task 3: SheetRepository (row↔object mapping, CRUD)

**Files:**
- Create: `app/Pos/Sheets/SheetRepository.php`
- Test: `tests/Unit/SheetRepositoryTest.php`

**Interfaces:**
- Consumes: `SheetsClient`.
- Produces `SheetRepository(SheetsClient $client)` (ports Database.js helpers):
  - `all(string $sheet): array` — list of assoc rows, each with `_row` (1-based sheet row). Skips fully-empty rows. Dates already strings. **Always reads live** (`getValues`), never cache (back.md §3.3).
  - `find(string $sheet, string $keyField, string $value): ?array`
  - `append(string $sheet, array $objects): void` — aligns to header order.
  - `update(string $sheet, string $keyField, string $keyValue, array $patch): array` — locate row by key (live read), patch present columns, write that row, return updated row.
  - `upsert(string $sheet, string $keyField, array $object): array`

- [ ] **Step 1: Write failing test**

Create `tests/Unit/SheetRepositoryTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetRepository};

class SheetRepositoryTest extends TestCase
{
    private function repoWithHeaders(): SheetRepository
    {
        $c = new FakeSheetsClient();
        $c->updateValues('Tables!A1', [['TableID', 'Name', 'Status']]);
        return new SheetRepository($c);
    }

    public function test_append_all_find(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->append('Tables', [['TableID' => 'T01', 'Name' => 'โต๊ะ 01', 'Status' => 'AVAILABLE']]);
        $rows = $repo->all('Tables');
        $this->assertCount(1, $rows);
        $this->assertSame('T01', $rows[0]['TableID']);
        $this->assertSame(2, $rows[0]['_row']);
        $this->assertSame('T01', $repo->find('Tables', 'TableID', 'T01')['TableID']);
    }

    public function test_update_patches_only_present_columns(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->append('Tables', [['TableID' => 'T01', 'Name' => 'โต๊ะ 01', 'Status' => 'AVAILABLE']]);
        $updated = $repo->update('Tables', 'TableID', 'T01', ['Status' => 'OCCUPIED']);
        $this->assertSame('OCCUPIED', $updated['Status']);
        $this->assertSame('โต๊ะ 01', $updated['Name']);
    }

    public function test_upsert_inserts_then_updates(): void
    {
        $repo = $this->repoWithHeaders();
        $repo->upsert('Tables', 'TableID', ['TableID' => 'T02', 'Name' => 'โต๊ะ 02', 'Status' => 'AVAILABLE']);
        $repo->upsert('Tables', 'TableID', ['TableID' => 'T02', 'Name' => 'โต๊ะ 02', 'Status' => 'DISABLED']);
        $this->assertCount(1, $repo->all('Tables'));
        $this->assertSame('DISABLED', $repo->find('Tables', 'TableID', 'T02')['Status']);
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/SheetRepositoryTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement repository**

Create `SheetRepository.php` porting `readSheetObjects_`/`findObject_`/`appendObjects_`/`updateObject_`/`upsertObject_` (Database.js:91-146). Read header row (row 0), map each data row → assoc keyed by header + `_row` = index+1 (1-based, +1 for header). Empty-row filter. `append` maps object→row by header order (missing → ''). `update` re-reads live, finds row by key column, computes 1-based row, writes single-row range `Sheet!A{row}` with full row values (patched). Throw `AppError('NOT_FOUND', 'ไม่พบข้อมูลที่ต้องการ')` when key missing on update.

- [ ] **Step 4: Run, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/SheetRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos/Sheets tests
git commit -m "feat: SheetRepository CRUD over SheetsClient"
```

---

### Task 4: LockManager + IdempotencyManager

**Files:**
- Create: `app/Pos/Support/LockManager.php`
- Create: `app/Pos/Support/IdempotencyManager.php`
- Test: `tests/Unit/IdempotencyManagerTest.php`

**Interfaces:**
- Consumes: `SheetRepository`, Laravel `Cache`.
- Produces:
  - `LockManager::withLock(string $name, int $ms, callable $fn): mixed` — `Cache::lock($name, ceil($ms/1000))->block(...)`; on timeout throw `AppError('BUSY', $busyMessage)` (caller passes message). Release in finally.
  - `IdempotencyManager(SheetRepository $repo)`:
    - `begin(string $type, string $key): ?array` — returns decoded `ResultJSON` if COMPLETED (cache hit), else marks/creates PROCESSING and returns null.
    - `complete(string $key, string $entityId, array $result): void`
    - `fail(string $key): void`
    (ports `Services.js:396-415`.)

- [ ] **Step 1: Write failing test**

Create `tests/Unit/IdempotencyManagerTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetRepository};
use App\Pos\Support\IdempotencyManager;

class IdempotencyManagerTest extends TestCase
{
    private function repo(): SheetRepository
    {
        $c = new FakeSheetsClient();
        $c->updateValues('Transactions!A1', [[
            'TransactionID','Type','IdempotencyKey','EntityID','Status','CreatedAt','UpdatedAt','ResultJSON']]);
        return new SheetRepository($c);
    }

    public function test_begin_returns_null_first_then_cached_result(): void
    {
        $repo = $this->repo();
        $mgr = new IdempotencyManager($repo);
        $this->assertNull($mgr->begin('ORDER', 'k1'));
        $mgr->complete('k1', 'ses_1', ['SessionID' => 'ses_1']);
        $this->assertSame(['SessionID' => 'ses_1'], $mgr->begin('ORDER', 'k1'));
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/IdempotencyManagerTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement managers**

Create `IdempotencyManager.php` porting `beginTransaction_`/`completeTransaction_`/`failTransaction_`. `begin`: `find('Transactions','IdempotencyKey',$key)`; if COMPLETED → `json_decode(ResultJSON, true)`; if exists → update PROCESSING, return null; else append new PROCESSING row (`TransactionID = uuidPrefixed('txn_')`), return null. `complete`: update EntityID/Status=COMPLETED/ResultJSON. `fail`: update Status=FAILED.
Create `LockManager.php` using `Illuminate\Support\Facades\Cache::lock`. Wrap `block(seconds)` in try; catch `LockTimeoutException` → throw `AppError('BUSY', $message)`.

- [ ] **Step 4: Run, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/IdempotencyManagerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos/Support tests
git commit -m "feat: Redis LockManager + Transactions IdempotencyManager"
```

---

### Task 5: SettingsService + CatalogService

**Files:**
- Create: `app/Pos/Services/SettingsService.php`, `CatalogService.php`
- Test: `tests/Feature/CatalogServiceTest.php` (Feature — uses app container)

**Interfaces:**
- Consumes: `SheetRepository`, `Cache`.
- Produces:
  - `SettingsService`: `map(): array` (Settings sheet → key=>value, cache 120s), `bootstrap(?string $tableToken): array` (ports `getPublicBootstrap` — brand/app block + optional customer data), `save(array $settings): array` (Task 9 extends; base map read/write here).
  - `CatalogService`: `publicCatalog(): array` (ports `getPublicCatalog_` — categories+menu(options+scoped addons)+promotions, cache `catalog` 120s), `customerData(string $tableToken): array` (ports `getCustomerData_`), `clearCatalogCache(): void`, `addOnAppliesToItem(array $addOn, array $item): bool`.

- [ ] **Step 1: Write failing test**

Create `tests/Feature/CatalogServiceTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetRepository, SheetsClient};
use App\Pos\Services\CatalogService;

class CatalogServiceTest extends TestCase
{
    public function test_public_catalog_joins_options_and_addons(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $svc = $this->app->make(CatalogService::class);
        $catalog = $svc->publicCatalog();
        $this->assertCount(5, $catalog['categories']);
        $this->assertCount(8, $catalog['menu']);
        $m001 = collect($catalog['menu'])->firstWhere('ItemID', 'M001');
        $this->assertTrue($m001['available']);
        $this->assertNotEmpty($m001['options']); // ระดับความเผ็ด
        $this->assertTrue(collect($m001['addOns'])->contains(fn ($a) => $a['AddOnID'] === 'ADD001')); // CAT_RICE scope
    }
}
```
(Note: this is a Feature test — uses the app container. `seedDefaults` lands in Task 8; run this test's assertion after Task 8, but write the service now. If executing strictly in order, mark this test `@depends`/skip until Task 8, then unskip. Simpler: implement `seedDefaults` minimally here for the 8 items — but canonical seed is Task 8. Choose: implement service now, land seed in Task 8, unskip test in Task 8 Step X.)

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/CatalogServiceTest.php`
Expected: FAIL — service/seed not found.

- [ ] **Step 3: Implement services**

Create `SettingsService.php`: `map()` reads Settings via repo → assoc, `Cache::remember('pos:settings-map', 120, ...)`. `bootstrap()` builds the app/brand block exactly per `Services.js:9-32` (AppName, RestaurantName, tagline, logo, colors, hero*, currency, pollSeconds), plus `customer` = `CatalogService.customerData($tableToken)` when token present; `setupRequired` false (Sheets always ready in this stack — return false).
Create `CatalogService.php` porting `getPublicCatalog_` (Services.js:71-133): active categories sorted by SortOrder; options grouped by ItemID; add-ons split global/by-item/by-category; each menu item (non-ARCHIVED, sorted) → `available = Status==ACTIVE`, `options` = active for item, `addOns` = global+item+category deduped. `customerData` porting `getCustomerData_` (Services.js:55-69): find Table by Token (reject DISABLED/missing → `AppError('TABLE_NOT_FOUND','QR โต๊ะนี้ไม่พร้อมใช้งาน')`), return `{table, categories, menu, promotions, session}` where session = bundle if `CurrentSessionID`. `clearCatalogCache` forgets `pos:public-catalog`. Cache `publicCatalog` under `pos:public-catalog` 120s.
Register a service provider (`AppServiceProvider`) binding `SheetsClient` → real client in prod (Task 10), `SheetRepository`, services as singletons.

- [ ] **Step 4: Defer assertion to Task 8**

If `seedDefaults` not yet implemented, temporarily seed inline in the test OR skip until Task 8. Proceed to commit the services.

- [ ] **Step 5: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos/Services tests
git commit -m "feat: SettingsService + CatalogService (public catalog join)"
```

---

### Task 6: OrderService (submit, status, callStaff, totals)

**Files:**
- Create: `app/Pos/Services/OrderService.php`
- Create: `app/Pos/Support/Totals.php`
- Test: `tests/Unit/TotalsTest.php`, `tests/Feature/OrderServiceTest.php`

**Interfaces:**
- Consumes: `SheetRepository`, `SettingsService`, `CatalogService`, `LockManager`, `IdempotencyManager`.
- Produces:
  - `Totals::calculate(SheetRepository $repo, SettingsService $settings, string $sessionId, string $promoCode): array` — returns `{subtotal,discount,serviceCharge,vat,total,promo}` AND persists to session (ports `recalculateSessionTotals_` Services.js:357).
  - `OrderService`: `submit(array $input): array` (lock `lock:order` 15s + idempotency, ports `submitOrder`/`createOrder_`), `status(string $tableToken, string $sessionId): array` (ports `getOrderStatus`), `callStaff(array $input): array` (lock `lock:call` 5s, ports `callStaff`), `sessionBundle(string $sessionId): ?array` (ports `getSessionBundle_`).

- [ ] **Step 1: Write failing Totals test**

Create `tests/Unit/TotalsTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetRepository};
use App\Pos\Services\SettingsService;
use App\Pos\Support\Totals;

class TotalsTest extends TestCase
{
    public function test_percent_promo_and_zero_charges(): void
    {
        $c = new FakeSheetsClient();
        $c->updateValues('Settings!A1', [['Key','Value','UpdatedAt']]);
        $c->appendValues('Settings!A1', [['ServiceChargePercent','0',''],['VatPercent','0','']]);
        $c->updateValues('OrderSessions!A1', [[
            'SessionID','TableID','OpenTime','CloseTime','Status','Subtotal','Discount','ServiceCharge','Vat','Total','PromoCode','PaymentMethod','CreatedBy','IdempotencyKey','UpdatedAt']]);
        $c->appendValues('OrderSessions!A1', [['ses_1','T01','','','OPEN',0,0,0,0,0,'','','CUSTOMER','k',' ']]);
        $c->updateValues('OrderItems!A1', [[
            'OrderItemID','SessionID','RequestKey','ItemID','ItemName','Qty','UnitPrice','OptionsJSON','AddOnsJSON','Note','LineTotal','Status','KitchenNote','CreatedAt','UpdatedAt']]);
        $c->appendValues('OrderItems!A1', [['oi1','ses_1','k','M001','กะเพรา',2,85,'[]','[]','',170,'NEW','','','']]);
        $c->updateValues('Promotions!A1', [[
            'PromoID','Code','Name','Description','DiscountType','DiscountValue','MinSpend','StartDate','EndDate','BannerImage','Status']]);
        $c->appendValues('Promotions!A1', [['p1','WELCOME10','w','','PERCENT',10,100,'2020-01-01','2035-01-01','','ACTIVE']]);
        $repo = new SheetRepository($c);
        $settings = new SettingsService($repo);
        $t = Totals::calculate($repo, $settings, 'ses_1', 'WELCOME10');
        $this->assertSame(170.0, $t['subtotal']);
        $this->assertSame(17.0, $t['discount']);   // 10% of 170
        $this->assertSame(153.0, $t['total']);
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/TotalsTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement Totals**

Create `Totals.php` porting `recalculateSessionTotals_` (Services.js:357-379): subtotal = money(Σ non-cancelled LineTotal); find active promo by code with subtotal≥MinSpend; discount PERCENT `money(subtotal*value/100)` else `min(subtotal, money(value))`; net = max(0, subtotal-discount); serviceCharge = money(net*ServiceChargePercent/100); vat = money((net+serviceCharge)*VatPercent/100); total = money(net+serviceCharge+vat); `update('OrderSessions',...)` persist; return array + promo (public row or null). Include `findPromotion`/`getActivePromotions` (date-window filter, Services.js:381-394).

- [ ] **Step 4: Run Totals test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/TotalsTest.php`
Expected: PASS.

- [ ] **Step 5: Write failing OrderService feature test**

Create `tests/Feature/OrderServiceTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetsClient};
use App\Pos\Services\OrderService;

class OrderServiceTest extends TestCase
{
    public function test_submit_is_idempotent(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $svc = $this->app->make(OrderService::class);
        $input = [
            'tableToken' => $fake->firstTableToken(),
            'idempotencyKey' => 'k1', 'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ];
        $a = $svc->submit($input);
        $b = $svc->submit($input);
        $this->assertSame($a['SessionID'], $b['SessionID']);
    }
}
```
(`FakeSheetsClient::firstTableToken()` — helper returning a seeded table Token; add in Task 8 seed.)

- [ ] **Step 6: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/OrderServiceTest.php`
Expected: FAIL — service/seed not found.

- [ ] **Step 7: Implement OrderService**

Create `OrderService.php` porting `submitOrder`/`createOrder_`/`callStaff`/`getOrderStatus`/`getSessionBundle_` (Services.js:147-355). `submit`: validate idempotencyKey, items 1–50; `LockManager::withLock('lock:order', 15000, ...)` with BUSY message "ระบบกำลังรับออเดอร์อื่น กรุณาลองอีกครั้ง"; `IdempotencyManager::begin('ORDER', key)` (return cached); build order — recover by RequestKey; reuse open session or create (`uuidPrefixed('ses_')`); per item validate ACTIVE + qty 1–20 + required options + option/addon validity + scope; unitPrice/lineTotal; append OrderItems; set Table OCCUPIED; `Totals::calculate`; audit; `complete`. Use CatalogService maps to validate. `status`/`callStaff`/`sessionBundle` per source. Use Cache invalidation as needed.

- [ ] **Step 8: Run OrderService test, verify pass (after Task 8 seed)**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/OrderServiceTest.php`
Expected: PASS (requires Task 8 `seedDefaults`; if running strictly in order, land seed first or seed inline).

- [ ] **Step 9: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos tests
git commit -m "feat: Totals + OrderService (submit/status/call, idempotent+locked)"
```

---

### Task 7: AuthService + StaffAuth middleware + OpsService + PaymentService

**Files:**
- Create: `app/Pos/Services/AuthService.php`, `OpsService.php`, `PaymentService.php`
- Create: `app/Http/Middleware/StaffAuth.php`
- Test: `tests/Feature/AuthServiceTest.php`, `tests/Feature/PaymentServiceTest.php`

**Interfaces:**
- Produces:
  - `AuthService`: `login(string $pin, string $expectedRole = ''): array` (`{token,user}`), `logout(string $token): array`, `changePin(array $session, string $newPin): array`, `authSalt(): string`, `resolve(string $token, array $roles = []): array` (session or throw; role gate; ADMIN override — ports `requireAuth_`).
  - `StaffAuth` middleware: reads `token` from request, calls `AuthService::resolve` with route-declared roles, sets `$request->attributes->set('authUser', $session)`.
  - `OpsService`: `dashboard(array $session, string $view): array` (ports `getOpsDashboard`), `updateOrderItem(array $session, array $input): array` (role targets, Admin.js:160), `updateCall(array $session, array $input): array`.
  - `PaymentService`: `closeTable(array $session, array $input): array` (lock `lock:payment` + idempotency, receipt — ports `closeTable`/`buildReceipt_`).

- [ ] **Step 1: Write failing auth test**

Create `tests/Feature/AuthServiceTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetsClient};
use App\Pos\Services\AuthService;

class AuthServiceTest extends TestCase
{
    public function test_login_with_seeded_pin_and_role(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $auth = $this->app->make(AuthService::class);
        $res = $auth->login(config('pos.initial_pin'), 'KITCHEN');
        $this->assertNotEmpty($res['token']);
        $this->assertSame('KITCHEN', $res['user']['role']);
        $back = $auth->resolve($res['token'], ['KITCHEN']);
        $this->assertSame('KITCHEN', $back['role']);
    }

    public function test_resolve_rejects_wrong_role_unless_admin(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $auth = $this->app->make(AuthService::class);
        $res = $auth->login(config('pos.initial_pin'), 'KITCHEN');
        $this->expectExceptionMessage('คุณไม่มีสิทธิ์ทำรายการนี้');
        $auth->resolve($res['token'], ['CASHIER']);
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/AuthServiceTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement AuthService + middleware**

Create `AuthService.php` porting `loginStaff`/`logoutStaff`/`changeMyPin`/`requireAuth_`/`getAuthSalt_` (Admin.js:18-84). Salt from `config('pos.auth_salt')` or generate+persist to Redis `auth:salt`. Token = `Str::random(64)`; store session JSON in `Cache::put("auth:$token", ..., config('pos.auth_ttl'))`. `resolve`: get from cache (`AUTH_EXPIRED` if missing), reload Staff (`PERMISSION_DENIED` if not ACTIVE), enforce roles unless ADMIN, refresh TTL. Create `StaffAuth` middleware calling `resolve` with roles from middleware params (e.g. `staff.auth:KITCHEN,STAFF`); register alias in `bootstrap/app.php`.

- [ ] **Step 4: Run auth test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/AuthServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Write failing payment test**

Create `tests/Feature/PaymentServiceTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetsClient};
use App\Pos\Services\{OrderService, PaymentService, AuthService};

class PaymentServiceTest extends TestCase
{
    public function test_close_table_is_idempotent_and_sets_paid(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $order = $this->app->make(OrderService::class);
        $auth = $this->app->make(AuthService::class);
        $pay = $this->app->make(PaymentService::class);

        $submit = $order->submit([
            'tableToken' => $fake->firstTableToken(), 'idempotencyKey' => 'o1', 'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);
        $cashier = $auth->login(config('pos.initial_pin'), 'CASHIER');
        $session = $auth->resolve($cashier['token'], ['CASHIER']);
        $input = ['sessionId' => $submit['SessionID'], 'method' => 'CASH', 'reference' => '', 'idempotencyKey' => 'p1'];
        $a = $pay->closeTable($session, $input);
        $b = $pay->closeTable($session, $input);
        $this->assertSame($a['payment']['PaymentID'], $b['payment']['PaymentID']);
    }
}
```

- [ ] **Step 6: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/PaymentServiceTest.php`
Expected: FAIL — not found.

- [ ] **Step 7: Implement OpsService + PaymentService**

Create `OpsService.php` porting `getOpsDashboard`/`updateOrderItemStatus`/`updateCallStatus` (Admin.js:86-189): dashboard filters active sessions/items/calls by view; role-target map for order status; call ASSIGNED/DONE. Create `PaymentService.php` porting `closeTable`/`buildReceipt_` (Admin.js:191-260): `LockManager::withLock('lock:payment', 15000, ...)` BUSY "ระบบกำลังปิดบิลอื่น กรุณาลองอีกครั้ง"; idempotency begin/complete; **write order** Payment(append, dedup by IdempotencyKey) → Session PAID → Table AVAILABLE+clear → close calls → receipt (back.md §7). Method validation CASH/TRANSFER/CARD/OTHER.

- [ ] **Step 8: Run payment test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/PaymentServiceTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos app/Http/Middleware bootstrap/app.php tests
git commit -m "feat: auth + middleware + ops + payment services"
```

---

### Task 8: pos:setup seeder + FakeSheetsClient::seedDefaults

**Files:**
- Create: `app/Console/Commands/PosSetup.php`
- Modify: `app/Pos/Sheets/FakeSheetsClient.php` (implement `seedDefaults`, `firstTableToken`)
- Create: `app/Pos/Sheets/SeedData.php` (shared seed arrays)
- Test: `tests/Unit/SeedDataTest.php`

**Interfaces:**
- Produces: `SeedData::sheets(): array` — `['SheetName' => ['headers'=>[], 'rows'=>[[...]]]]` for all 14 sheets, verbatim from `Database.js:251-320` (+ Staff PIN hashed with a fixed test salt for fakes). `FakeSheetsClient::seedDefaults(): static`, `firstTableToken(): string`. `php artisan pos:setup` command.

- [ ] **Step 1: Write failing seed test**

Create `tests/Unit/SeedDataTest.php`:
```php
<?php
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Pos\Sheets\SeedData;

class SeedDataTest extends TestCase
{
    public function test_seed_has_8_menu_5_categories_promo(): void
    {
        $sheets = SeedData::sheets();
        $this->assertCount(8, $sheets['MenuItems']['rows']);
        $this->assertCount(5, $sheets['Categories']['rows']);
        $this->assertSame('WELCOME10', $sheets['Promotions']['rows'][0][1]); // Code column
        $this->assertCount(12, $sheets['Tables']['rows']);
        $this->assertCount(4, $sheets['Staff']['rows']);
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/SeedDataTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement SeedData + fake seeding + command**

Create `SeedData.php` with all 14 sheets' headers (from back.md §2) and seed rows verbatim from `Database.js`: Tables T01–T12 (zones "โซนด้านใน"/"โซนระเบียง", Token = `uuidPrefixed('tbl_')`), Categories 5, MenuItems 8 (exact Thai names/prices/ImageURL), Options OPT001-004, AddOns ADD001-004, Promotions WELCOME10 (PERCENT/10/500), Staff 4 (PINHash = `sha256(salt.':'.initialPin)`, MustChangePin true), Settings defaults (`Database.js:149-170`). Implement `FakeSheetsClient::seedDefaults()` to load these into memory, `firstTableToken()` returns Tables row Token. Create `PosSetup` command porting `setupSystem` (Database.js:23): ensure 14 sheets exist (Sheets `batchUpdate` addSheet via real client), write headers, append seed rows only when a sheet is empty. Prints Thai PIN note verbatim.

- [ ] **Step 4: Run seed test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Unit/SeedDataTest.php`
Expected: PASS.

- [ ] **Step 5: Unskip Catalog/Order/Auth/Payment tests**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature`
Expected: PASS (they now have `seedDefaults`).

- [ ] **Step 6: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app tests
git commit -m "feat: SeedData + pos:setup command + fake seeding"
```

---

### Task 9: AdminService (CRUD, settings save, archive, rotate)

**Files:**
- Create: `app/Pos/Services/AdminService.php`
- Modify: `app/Pos/Services/SettingsService.php` (implement `save` validation)
- Test: `tests/Feature/AdminServiceTest.php`

**Interfaces:**
- Produces:
  - `AdminService`: `getData(array $session): array` (all sheets + summary + orderUrl per table), `saveEntity(array $session, string $entity, array $data): array`, `archiveEntity(array $session, string $entity, string $id): array`, `rotateToken(array $session, string $tableId): array`.
  - `SettingsService::save(array $session, array $settings): array` — brand keys, hex/percent/url validation, polling 5–60 (ports `adminSaveSettings`).
  - Entity config map (ports `ADMIN_ENTITIES` Admin.js:1-10).

- [ ] **Step 1: Write failing admin test**

Create `tests/Feature/AdminServiceTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetsClient};
use App\Pos\Services\{AuthService, AdminService};

class AdminServiceTest extends TestCase
{
    public function test_save_menu_entity_upserts_and_invalidates_catalog(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $auth = $this->app->make(AuthService::class);
        $admin = $this->app->make(AdminService::class);
        $session = $auth->resolve($auth->login(config('pos.initial_pin'), 'ADMIN')['token'], ['ADMIN']);
        $saved = $admin->saveEntity($session, 'menu', [
            'ItemID' => 'M001', 'CategoryID' => 'CAT_RICE', 'Name' => 'กะเพราหมูสับพิเศษ',
            'Price' => 95, 'Status' => 'ACTIVE',
        ]);
        $this->assertSame('กะเพราหมูสับพิเศษ', $saved['Name']);
    }

    public function test_archive_category_in_use_is_blocked(): void
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        $auth = $this->app->make(AuthService::class);
        $admin = $this->app->make(AdminService::class);
        $session = $auth->resolve($auth->login(config('pos.initial_pin'), 'ADMIN')['token'], ['ADMIN']);
        $this->expectExceptionMessage('ย้ายหรือลบเมนูในหมวดนี้ก่อน แล้วจึงลบหมวดหมู่');
        $admin->archiveEntity($session, 'category', 'CAT_RICE');
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/AdminServiceTest.php`
Expected: FAIL — not found.

- [ ] **Step 3: Implement AdminService + settings save**

Create `AdminService.php` porting `adminGetData`/`adminSaveEntity`/`adminArchiveEntity`/`rotateTableToken`/`validateAdminEntity_` (Admin.js:262-441) with the `ADMIN_ENTITIES` map. `saveEntity`: pick allowed fields, gen key (`uuidPrefixed(prefix)`) if new, table-in-use guard, per-entity validation, staff PIN handling, `upsert`, invalidate catalog cache for catalog entities, audit. `archiveEntity`: guards (invalid entity, self-archive, category-in-use, category-addon-in-use), set Status ARCHIVED, invalidate. `rotateToken`: block if CurrentSessionID. `getData`: all sheets via repo + summary (tables/menuItems/activeSessions/todaySales) + `orderUrl` per table (built from a configurable frontend base URL `config('pos.order_base_url')`, e.g. `.?page=order&table={Token}`). Implement `SettingsService::save` porting `adminSaveSettings` (brand keys, `normalizeText` limits, required AppName/RestaurantName/HeroTitle, `sanitizeHttpsUrl`, `validateHexColor`, `validatePercentSetting`, polling 5–60 via `lock:settings`).

- [ ] **Step 4: Run admin test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/AdminServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app/Pos tests
git commit -m "feat: AdminService CRUD + settings save + archive + rotate"
```

---

### Task 10: Routes + Controllers + real SheetsClient + wiring

**Files:**
- Create: `routes/api.php`
- Modify: `bootstrap/app.php` (enable api routes + middleware alias)
- Create: controllers under `app/Http/Controllers/Api/` — `CustomerController`, `AuthController`, `OpsController`, `AdminController`
- Create: `app/Pos/Sheets/GoogleSheetsClient.php`, `GoogleTokenProvider.php`
- Modify: `app/Providers/AppServiceProvider.php` (bindings)
- Test: `tests/Feature/ApiRoutesTest.php`

**Interfaces:**
- Consumes: all services.
- Produces: REST endpoints (back.md §6), real `GoogleSheetsClient implements SheetsClient`, container bindings (`SheetsClient` → GoogleSheetsClient in non-testing env).

- [ ] **Step 1: Write failing route test**

Create `tests/Feature/ApiRoutesTest.php`:
```php
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Pos\Sheets\{FakeSheetsClient, SheetsClient};

class ApiRoutesTest extends TestCase
{
    private function seedFake(): FakeSheetsClient
    {
        $fake = (new FakeSheetsClient())->seedDefaults();
        $this->app->instance(SheetsClient::class, $fake);
        return $fake;
    }

    public function test_bootstrap_returns_envelope(): void
    {
        $this->seedFake();
        $res = $this->postJson('/api/bootstrap', []);
        $res->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertArrayHasKey('app', $res->json('data'));
    }

    public function test_customer_flow_submit(): void
    {
        $fake = $this->seedFake();
        $res = $this->postJson('/api/order/submit', [
            'tableToken' => $fake->firstTableToken(), 'idempotencyKey' => 'k1', 'promoCode' => '',
            'items' => [['itemId' => 'M006', 'qty' => 1, 'optionIds' => [], 'addOnIds' => [], 'note' => '']],
        ]);
        $res->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertNotEmpty($res->json('data.SessionID'));
    }

    public function test_ops_requires_auth(): void
    {
        $this->seedFake();
        $res = $this->postJson('/api/ops/dashboard', ['token' => 'bad', 'view' => 'KITCHEN']);
        $res->assertStatus(200)->assertJson(['ok' => false]);
        $this->assertSame('AUTH_EXPIRED', $res->json('error.code'));
    }
}
```

- [ ] **Step 2: Run, verify fail**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/ApiRoutesTest.php`
Expected: FAIL — routes not defined.

- [ ] **Step 3: Implement controllers + routes**

Create controllers (thin: validate → call service → `return response()->json(apiOk($data))`):
- `CustomerController`: bootstrap, customer, submit, status, call.
- `AuthController`: login, logout, changePin.
- `OpsController`: dashboard, orderStatus, callStatus, closeTable (reads `authUser` attribute from `StaffAuth`).
- `AdminController`: data, settings, entity, entityArchive, rotateToken.
Populate `routes/api.php` (created by `install:api` in Task 0) mapping back.md §6. Ops group middleware `staff.auth:KITCHEN,STAFF` etc.; admin group `staff.auth:ADMIN`. For endpoints taking `token` in body (customer polling style), StaffAuth reads body token. Register `staff.auth` middleware alias in `bootstrap/app.php` `->withMiddleware(fn ($m) => $m->alias(['staff.auth' => \App\Http\Middleware\StaffAuth::class]))`. (API routing itself already enabled by `install:api`.)

**Security middleware (security.md §2.1/2.7/2.9):**
- **Rate limit login**: `/api/auth/login` → `throttle:10,1` (10/min). Public order/call endpoints → `throttle:30,1` (per IP). Apply via route `->middleware('throttle:...')`.
- **CORS**: `php artisan config:publish cors` (or edit `config/cors.php`), set `'paths' => ['api/*']`, `'allowed_origins' => [env('POS_FRONTEND_ORIGIN')]` (NOT `*`). Add `POS_FRONTEND_ORIGIN=` to `.env.example` + `config/pos.php` if referenced.
- Add a Feature test asserting the 11th rapid login returns 429 (or envelope throttle error).

- [ ] **Step 4: Implement real Google client**

Create `GoogleTokenProvider.php` (back.md §3.1): load SA JSON, build+sign RS256 JWT (`Firebase\JWT\JWT::encode` with `openssl` key), POST token endpoint, cache in Redis `google:sa:token` TTL expiry−60s. Create `GoogleSheetsClient.php` (back.md §3.2) implementing `SheetsClient` via Laravel `Http::withToken(...)` against Sheets v4 (getValues/append/update/batchGet + 429/5xx retry). Bind in `AppServiceProvider`: `SheetsClient` → `GoogleSheetsClient` (skip when `app()->environment('testing')` so tests inject fake).

- [ ] **Step 5: Run route test, verify pass**

Run: `cd pos-backend && ./vendor/bin/phpunit tests/Feature/ApiRoutesTest.php`
Expected: PASS.

- [ ] **Step 6: Full suite + lint**

Run: `cd pos-backend && ./vendor/bin/phpunit && ./vendor/bin/pint --test`
Expected: all green.

- [ ] **Step 7: Commit**
```bash
cd "E:/Develop/CODING/POS"
git add app routes bootstrap/app.php tests
git commit -m "feat: api routes, controllers, GoogleSheetsClient wiring"
```

---

### Task 11: Manual smoke test (real Sheets)

**Files:** none (manual).

- [ ] **Step 1: Configure**

Create a throwaway Google Spreadsheet, share (Editor) with the Service Account email. Put its ID in `.env GOOGLE_SPREADSHEET_ID`, place SA key at `storage/google/service-account.json`, set Redis up (`CACHE_STORE=redis`).

- [ ] **Step 2: Seed**

Run: `cd pos-backend && php artisan pos:setup`
Expected: 14 sheets created + seeded; prints Thai PIN note (`zaq1234`).

- [ ] **Step 3: Smoke the flow**

Run: `cd pos-backend && php artisan serve`. Then:
- `POST /api/bootstrap` → app block.
- `POST /api/customer {tableToken}` (token from Tables sheet) → catalog.
- `POST /api/order/submit` → SessionID.
- `POST /api/auth/login {pin: zaq1234, expectedRole: CASHIER}` → token.
- `POST /api/ops/close-table` → receipt.
Verify Sheets rows change accordingly. Point the Flutter app's `ApiClient` base URL here and run the customer flow end-to-end.

- [ ] **Step 4: Commit any fixes**
```bash
cd "E:/Develop/CODING/POS"
git add backend
git commit -m "fix: smoke-test corrections against live Sheets"
```

---

## Self-Review notes

- **Spec coverage**: back.md §3 Sheets → Tasks 2,3,10; §4 lock/idempotency/cache → Tasks 4,5,6; §5 auth → Task 7; §6 endpoints → Task 10; §7 business logic → Tasks 5,6,7,9; §8 setup → Task 8; §10 testing → every task (FakeSheetsClient); §6 envelope handler → Task 1.
- **Ordering (resolved)**: Feature tests in Tasks 5–7,9 depend on `seedDefaults`. Fix = per the Global Constraints "Build order override": run **Task 8 Steps 1–4 right after Task 3**, so `SeedData`/`seedDefaults`/`firstTableToken` exist before any Feature test. Then every task greens its own verify-pass step in place. The `pos:setup` artisan command portion of Task 8 stays at Task 8. Unit tests (Helpers, Totals, Repository, Idempotency, SeedData) are self-contained and pass immediately.
- **Deferred** (back.md §12): Reverb realtime, Drive image upload, PDF receipt, MySQL mirror.
- **Types**: `SheetsClient`, `SheetRepository`, `AppError`, `IdempotencyManager`, `LockManager`, service classes used consistently across tasks as defined in their Interfaces.
```
