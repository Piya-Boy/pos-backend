# test.md — Testing Strategy (Phius Order)

> กลยุทธ์ทดสอบทั้งโปรเจค. อ้าง `plan.md` (frontend) + `plan-back.md` (backend). ทุก task มี test ของตัวเองอยู่แล้ว — ไฟล์นี้คือภาพรวม + มาตรฐาน + gap ที่แต่ละ task test ไม่ครอบ.
> หลัก: **TDD** (test ก่อน code), **offline** (ไม่แตะ Google/Redis จริงใน CI), **parity** (frontend ตรง cp-pos).

---

## 1. Test pyramid

```
        ▲  E2E (manual, Phase 3)         — สแกน QR → สั่ง → ครัว → ปิดบิล ผ่าน stack จริง
       ─┼─ Feature/HTTP (backend)        — routes + envelope + auth gate + idempotency
      ──┼── Widget (frontend)            — component render + interaction
    ────┴──── Unit (both)                — logic ล้วน: totals, validation, helpers, models
```
ฐานกว้าง = unit (เร็ว, เยอะ). ยอด = manual E2E (น้อย, แพง).

---

## 2. Backend testing (Laravel · PHPUnit 11)

### 2.1 Rules
- **FakeSheetsClient** bound ใน container ทุก test — ไม่แตะ Google Sheets จริง (`plan-back.md` Global Constraints).
- **`CACHE_STORE=array`** ใน `phpunit.xml` — lock/auth/idempotency-cache ทำงาน in-memory, ไม่ต้อง Redis จริง.
- Unit = `Tests\Unit` (ไม่พึ่ง app container). Feature = `Tests\Feature` (`$this->app`, `postJson`).
- ห้าม test แตะ network/filesystem จริง (ยกเว้น T11 manual smoke).

### 2.2 Coverage matrix (map task → สิ่งที่ต้องพิสูจน์)

| Layer | Test | ต้องพิสูจน์ | Task |
|---|---|---|---|
| Helpers | Unit | money round 2dp, sha256, normalizeText strip `<>`, boolish, uuid | T1 |
| FakeSheetsClient | Unit | append/get/update offset ถูก | T2 |
| SheetRepository | Unit | row↔object map, `_row` index, patch เฉพาะ column ที่ส่ง, upsert | T3 |
| SeedData | Unit | 8 menu / 5 cat / 12 table / 4 staff / WELCOME10 | T8 |
| Idempotency | Unit | begin คืน null ครั้งแรก, คืน cached result ครั้งสอง | T4 |
| Totals | Unit | promo PERCENT/FIXED, service charge, VAT, round | T6 |
| CatalogService | Feature | catalog join options+addons scope, available flag | T5 |
| OrderService | Feature | submit idempotent (key เดิม→session เดิม), required option validate, qty bound | T6 |
| AuthService | Feature | login PIN+role, resolve reject wrong role, ADMIN override | T7 |
| PaymentService | Feature | closeTable idempotent, session→PAID, table reset | T7 |
| AdminService | Feature | saveEntity upsert, archive category-in-use blocked | T9 |
| API routes | Feature | envelope `{ok}`, HTTP 200, auth gate (bad token→AUTH_EXPIRED) | T10 |

### 2.3 Gaps ที่ task test ยังไม่ครอบ (เพิ่มถ้ามีเวลา)
- [ ] **Concurrency**: 2 submit พร้อมกัน key เดียว → 1 session (จำลองด้วย lock; array store จำกัด แต่ทดสอบ idempotency path ได้).
- [ ] **Totals edge**: discount > subtotal (FIXED เกิน), net floor ที่ 0, service+VAT ซ้อน.
- [ ] **Required option missing** → `REQUIRED_OPTION` error + ข้อความไทยตรง.
- [ ] **Invalid item/option/addon** ในตะกร้า → `ITEM_UNAVAILABLE`/`INVALID_OPTION`/`INVALID_ADDON`.
- [ ] **callStaff BILL** ไม่มี session → `NO_ACTIVE_ORDER`; duplicate → `duplicate:true`.
- [ ] **Role gate ทุก endpoint**: KITCHEN เรียก admin → PERMISSION_DENIED; STAFF เปลี่ยน status เป็น PREPARING (ไม่ได้) → INVALID_STATUS.
- [ ] **changePin**: reuse initial PIN → `PIN_REUSE`; PIN สั้น → `INVALID_PIN`.
- [ ] **rotateToken** ขณะโต๊ะใช้งาน → `TABLE_IN_USE`.
- [ ] **Settings validate**: hex ผิด → INVALID_COLOR; percent >100 → INVALID_PERCENT; polling นอก 5–60 → INVALID_POLLING.
- [ ] **Envelope handler**: ValidationException → `{ok:false, code:VALIDATION}`; Throwable ทั่วไป → `SERVER_ERROR` (ไม่ leak stack).
- [ ] **Rate limit**: login เกิน 10/นาที → 429/throttle error (plan-back T10, security.md §2.1).
- [ ] **IDOR**: getOrderStatus โต๊ะ A ขอ sessionId โต๊ะ B → `SESSION_NOT_FOUND` (security.md §2.2).

### 2.4 Commands
```bash
cd pos-backend
./vendor/bin/phpunit                              # ทั้งหมด
./vendor/bin/phpunit --testsuite=Unit             # unit เร็ว
./vendor/bin/phpunit tests/Feature/OrderServiceTest.php
./vendor/bin/pint --test                          # lint (ไม่แก้)
```

---

## 3. Frontend testing (Flutter · flutter_test)

### 3.1 Rules
- **FakeApiClient** — ไม่ต่อ backend จริง (`front.md` §6). seed ตรง cp-pos.
- `SharedPreferences.setMockInitialValues({})` ใน `setUp` (persistence test).
- Widget test: `pumpWidget` + `pumpAndSettle` + `find.text`/`find.byType`.

### 3.2 Coverage matrix

| Layer | Test | ต้องพิสูจน์ | Task |
|---|---|---|---|
| tokens | Unit | สี/radius ตรง cp-pos `:root` | T1 |
| models | Unit | fromJson map header, nested options/addons | T2 |
| FakeApiClient | Unit | seed 8 menu, submitOrder idempotent | T2 |
| formatters | Unit | formatMoney `฿85`/`฿1,234`/`฿12.50`, placeholderImage | T3 |
| BrandMark | Widget | logo text/image fallback | T3 |
| CustomerController | Unit | filteredMenu (cat+search), cart add/qty/subtotal | T4 |
| CustomerPage | Widget | render ชื่อร้าน + guide steps | T5 |
| Menu | Widget | count "8 เมนู", food cards, menuColumns breakpoint | T6 |
| ItemModal | Widget | options render, live total, required validate | T7 |
| Cart | Widget | cart bar appears, open cart, submit | T8 |
| Tracking | Widget/Unit | statusMeta ports, empty state, service actions | T9 |
| Responsive | Widget | 4 cols wide screen | T10 |

### 3.3 Gaps ที่ควรเพิ่ม
- [ ] **Cart persistence**: add → reload (SharedPreferences) → cart คงอยู่, กรอง item ที่ไม่ available ออก.
- [ ] **Required validate ใน modal**: ไม่เลือก required group → toast error, ไม่ add.
- [ ] **Promo estimate**: ใส่ WELCOME10 + subtotal≥500 → เห็นส่วนลดโดยประมาณ.
- [ ] **Cart bar 3 states**: มีของ / session เปิด / ว่าง.
- [ ] **Bill pending**: callStaff BILL → banner โผล่, ปุ่ม label เปลี่ยน "เรียกเก็บเงินแล้ว".
- [ ] **Payment complete**: session PAID → cart เคลียร์ + "ชำระเงินเรียบร้อยแล้ว".

### 3.4 Commands
```bash
cd pos-frontend
flutter test                                      # ทั้งหมด
flutter test test/features/customer/menu_test.dart
flutter analyze                                   # lint
```

---

## 4. Parity testing (frontend ↔ cp-pos) · Phase 1 T10

- เปิด Flutter: `flutter run -d chrome --web-port 5599` → `http://localhost:5599/?page=order&table=demo`.
- เทียบ side-by-side กับ cp-pos ต้น (deployment เก่า หรือ `App.html` render).
- Checklist: สี · spacing · hero gradient · menu grid (2/3/4) · food card · item modal · cart · tracking · wording ไทยตรง.
- แก้ pixel diff ใน widget ที่เกี่ยว.

---

## 5. Integration / E2E (Phase 3, manual)

Pre: Laravel + Redis + Sheets จริง (`pos:setup` seed), Flutter ชี้ Laravel.

1. `POST /api/bootstrap` → app block.
2. สแกน/เปิด `?page=order&table={Token}` → catalog โหลด.
3. เลือกเมนู + options → ตะกร้า → submit → เห็น tracking.
4. Login KITCHEN → เห็นออเดอร์ → PREPARING → READY.
5. Login CASHIER → close-table → receipt → โต๊ะ reset.
6. เช็ค Sheets rows เปลี่ยนตาม (OrderSessions PAID, Payments row, Table AVAILABLE).

Regression: หลังแก้อะไร ต้องรัน `flutter test` + `phpunit` ผ่านก่อน commit.

---

## 6. Definition of tested (ต่อ phase)

- **Phase 1 done**: `flutter test` เขียว, `flutter analyze` clean, parity checklist §4 ผ่าน.
- **Phase 2 done**: `phpunit` เขียว (unit + feature), `pint --test` clean, smoke test §5 ข้อ 1-6 ผ่านกับ Sheets จริง.
- **Phase 3 done**: E2E §5 ครบ ผ่าน stack จริง.

---

## 7. CI (แนะนำ ตั้งทีหลัง)

- Backend job: `composer install` → `phpunit` → `pint --test` (CACHE_STORE=array, ไม่มี secret).
- Frontend job: `flutter pub get` → `flutter test` → `flutter analyze`.
- ห้ามใส่ SA key / spreadsheet ID ใน CI — ทุก test ใช้ fake.
