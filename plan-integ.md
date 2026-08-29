# Phase 3 — Integration Plan (wire Flutter ↔ Laravel)

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans / subagent-driven-development. TDD where testable; manual E2E at the end.
> Runs AFTER Phase 1 (frontend, `plan.md`) and Phase 2 (backend, `plan-back.md`) are both ✅.

**Goal:** Replace the frontend `FakeApiClient` with a real `HttpApiClient` hitting the Laravel API, and prove the customer flow end-to-end against a live (throwaway) Google Sheet.

**Architecture:** Frontend already talks only to the `ApiClient` interface (front.md §6). This phase adds a concrete `HttpApiClient` + a base-URL config + a build-time switch. Backend is unchanged except CORS origin config.

**Which repo:** frontend tasks in `pos-frontend/`, backend config in ``. Track in each repo's `PROGRESS.md` under a "Phase 3" section.

## Global Constraints
- Frontend package `pos_frontend`; imports `package:pos_frontend/...`.
- Envelope shape must match backend (`{ok,data,error}`); `HttpApiClient` parses it identically to `FakeApiClient`.
- No secret URLs hardcoded — base URL from `--dart-define` / config.
- Same TDD + review + commit + PROGRESS-tick loop as `AGENTS.md`.

---

### P3-T1 (frontend): HttpApiClient

**Repo:** `pos-frontend/`
**Files:**
- Create: `lib/core/api/http_api_client.dart`
- Create: `lib/core/api/api_config.dart`
- Test: `test/core/api/http_api_client_test.dart`

**Interfaces:**
- Produces `HttpApiClient implements ApiClient` taking a `baseUrl` + optional injected `http.Client`. Every method POSTs JSON to the matching route (`front.md` §6 / `back.md` §6), unwraps `{ok,data,error}`, throws `AppError` on `ok:false`.
- `ApiConfig.baseUrl` = `String.fromEnvironment('POS_API_BASE_URL', defaultValue: '')`.

- [ ] **Step 1: Failing test** — use `http`'s `MockClient` to stub a `{ok:true,data:{...}}` bootstrap response; assert `HttpApiClient.bootstrap()` returns the parsed `data`. Add a second case: `{ok:false,error:{code:'X',message:'y'}}` → throws `AppError` with `code=='X'`.
```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'dart:convert';
import 'package:pos_frontend/core/api/http_api_client.dart';
import 'package:pos_frontend/core/api/app_error.dart';

void main() {
  test('bootstrap unwraps ok envelope', () async {
    final mock = MockClient((req) async =>
        http.Response(jsonEncode({'ok': true, 'data': {'app': {'name': 'X'}}}), 200,
            headers: {'content-type': 'application/json'}));
    final api = HttpApiClient(baseUrl: 'https://api.test', client: mock);
    final data = await api.bootstrap(tableToken: '');
    expect(data['app']['name'], 'X');
  });

  test('error envelope throws AppError', () async {
    final mock = MockClient((req) async =>
        http.Response(jsonEncode({'ok': false, 'error': {'code': 'TABLE_NOT_FOUND', 'message': 'no'}}), 200));
    final api = HttpApiClient(baseUrl: 'https://api.test', client: mock);
    expect(() => api.getCustomerData(tableToken: 'x'),
        throwsA(isA<AppError>().having((e) => e.code, 'code', 'TABLE_NOT_FOUND')));
  });
}
```

- [ ] **Step 2: Run → FAIL** (`cd pos-frontend && flutter test test/core/api/http_api_client_test.dart`).
- [ ] **Step 3: Implement** `http_api_client.dart` + `api_config.dart`. Each method: `POST $baseUrl/api/<path>` with JSON body, decode envelope, `ok:true`→return `data`, else throw `AppError(code,message,details)`. Routes: bootstrap→`/api/bootstrap`, getCustomerData→`/api/customer`, submitOrder→`/api/order/submit`, getOrderStatus→`/api/order/status`, callStaff→`/api/call` (back.md §6.1).
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Review (sol)** — check no hardcoded URL, envelope parse matches Fake, timeout handling.
- [ ] **Step 6: Commit** `feat: HttpApiClient over Laravel API`; tick PROGRESS.

---

### P3-T2 (frontend): switch provider by config

**Repo:** `pos-frontend/`
**Files:**
- Modify: `lib/main.dart` / `lib/app.dart` (pick client), `lib/core/router/app_router.dart` if it injects the client.
- Test: `test/core/api/client_selection_test.dart`

**Interfaces:** a factory `ApiClient buildApiClient()` → `HttpApiClient(ApiConfig.baseUrl)` when `baseUrl` non-empty, else `FakeApiClient()` (keeps offline dev working).

- [ ] **Step 1: Failing test** — assert `buildApiClient()` returns `FakeApiClient` when no base URL, `HttpApiClient` when `--dart-define=POS_API_BASE_URL=...` (simulate by reading a passed value; test the factory with an explicit arg overload `buildApiClient({String? baseUrl})`).
- [ ] **Step 2: FAIL → Step 3: implement → Step 4: PASS.**
- [ ] **Step 5: Review + Step 6: Commit** `feat: select Http vs Fake ApiClient by config`; tick PROGRESS.

---

### P3-T3 (backend): CORS origin + serve config

**Repo:** ``
**Files:** `config/cors.php` (from `install:api`/publish), `.env.example`.

- [ ] **Step 1:** set `config/cors.php` `'paths' => ['api/*']`, `'allowed_origins' => [env('POS_FRONTEND_ORIGIN', '*')]` — but default MUST be the real Flutter origin in prod (security.md §2.7); `*` only acceptable for local dev, note it.
- [ ] **Step 2:** Feature test: an `OPTIONS`/preflight or a normal request from an allowed origin returns the CORS header; a disallowed origin does not. (Laravel `HandleCors` — test via `withHeaders(['Origin' => ...])`.)
- [ ] **Step 3:** Commit `chore: restrict API CORS to frontend origin`; tick PROGRESS.

---

### P3-T4: End-to-end smoke (manual)

**Repos:** both, plus real infra.

- [ ] **Step 1:** Backend up: `cd pos-backend`, real `.env` (spreadsheet id + SA key + Redis), `php artisan pos:setup` (seeds sheet), `php artisan serve` (→ `http://localhost:8000`).
- [ ] **Step 2:** Frontend up pointed at it: `cd pos-frontend && flutter run -d chrome --dart-define=POS_API_BASE_URL=http://localhost:8000`.
- [ ] **Step 3:** Walk the flow (from a Table `Token` in the sheet): open `?page=order&table={Token}` → catalog loads from Laravel → add items → submit → tracking shows the order → `POST /api/auth/login {pin: zaq1234, expectedRole: CASHIER}` → close-table → sheet rows change (Session PAID, Payment row, Table AVAILABLE).
- [ ] **Step 4:** Fix any mismatch (envelope field names, CORS, base URL). Commit fixes in the relevant repo; tick PROGRESS.

**Phase 3 done when:** the customer flow works browser→Laravel→Sheets, and both repos' Phase-3 PROGRESS lines are ✅.
