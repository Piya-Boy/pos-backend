# roadmap.md — Phius Order (QR Restaurant POS)

> ภาพรวมทั้งโปรเจค (overview). Claude = PM. AI ตัวอื่น = เขียนโค้ด.
> **สถานะ per-task จริงอยู่ที่ `pos-frontend/PROGRESS.md` + `PROGRESS.md`** (ในแต่ละ repo — commit ได้). ไฟล์นี้อยู่นอก repo ทั้งคู่ → เป็น overview อย่างเดียว, อัปเดตมือเป็นครั้งคราว ไม่ tick ต่อ task.

---

## 1. What we're building

Port ระบบ **สั่งอาหารผ่าน QR โต๊ะ + POS ร้านอาหาร** (เดิมชื่อ "Phius Order") จาก Google Apps Script (`cp-pos/`) → **Laravel API + Flutter (Web + Mobile)**.

Flow: ลูกค้าสแกน QR โต๊ะ → ดูเมนู → สั่ง (options/add-ons/note) → ครัวเห็นออเดอร์ → เสิร์ฟ → แคชเชียร์ปิดบิล. Roles: ADMIN / KITCHEN / STAFF / CASHIER (login PIN).

`cp-pos/` = ระบบเก่า, ใช้เป็น **reference เท่านั้น** (business logic ต้นทาง).

---

## 2. Locked decisions (สรุปทั้งหมด)

| Area | Decision | หมายเหตุ |
|---|---|---|
| Frontend | Flutter (Web + Mobile) | มีโปรเจคเปล่าที่ `pos-frontend/` |
| Backend | Laravel 12 | มี skeleton ที่ `` |
| DB | **Google Sheets** (source of truth) | เจ้าของร้านแก้ข้อมูลในชีทเองได้ |
| Sheets access | REST v4 ผ่าน Laravel `Http` (ไม่ใช้ google/apiclient) | |
| Google auth | Service Account (JSON key) → JWT RS256 → token | |
| Lock + Cache | **Redis** (atomic lock แทน LockService + cache ลด quota) | จำเป็น ไม่ใช่ optional |
| Staff auth | Staff sheet + PINHash, Laravel ออก opaque token ใน Redis | **ไม่ใช้ Sanctum** (เลี่ยง MySQL) |
| Response | `{ok,data,error}` envelope, HTTP 200 always | ตรง frontend `App.html:113` |
| Realtime | **Polling** ก่อน (REST) | WebSocket (Reverb) = phase ถัดไป |
| Offline | Online-only ก่อน | offline-first = ทีหลัง |
| Scope | ครบวงจร (customer + kitchen/staff/cashier + admin) | เหมือน cp-pos |
| State (Flutter) | provider + ChangeNotifier | |
| Font | Prompt (google_fonts) | ต้องเหมือน 100% |
| Git | **2 repo แยก**: `pos-frontend/` + `` (init แล้ว, main) | branch: feature/customer-frontend, feature/laravel-backend. ไม่มี mono repo ที่ root |

---

## 3. Documents (เขียนเสร็จแล้ว)

| ไฟล์ | คือ | สถานะ |
|---|---|---|
| `front.md` | Spec หน้าบ้าน (Flutter, parity 100%, 14 component) | ✅ + reviewed |
| `plan.md` | Implementation plan หน้าบ้าน (TDD, T0–T10) | ✅ + reviewed |
| `back.md` | Spec หลังบ้าน (Laravel + Sheets, ~20 endpoint) | ✅ + reviewed |
| `plan-back.md` | Implementation plan หลังบ้าน (TDD, T0–T11) | ✅ + reviewed |
| `test.md` | กลยุทธ์ทดสอบทั้งโปรเจค (pyramid, coverage matrix, gaps, E2E) | ✅ |
| `security.md` | Security requirements + reviewer checklist | ✅ |
| `AGENTS.md` | **คู่มือ AI รันเอง** — loop เขียน→เทส→รีวิว→commit→ถัดไป, ไม่ต้อง prompt | ✅ |
| `roadmap.md` | ไฟล์นี้ | ✅ |

**การอ่าน**: AI ตัวเขียน → **เริ่มที่ `AGENTS.md`** (คู่มือรันเอง: loop + ลำดับ + rules). AGENTS.md ชี้ต่อไป plan/spec/test/security เอง. สรุปสั้น: plan (`plan.md`/`plan-back.md`) = ขั้นตอน, spec (`front.md`/`back.md`) = รายละเอียด, `cp-pos/` = source of truth, `test.md`+`security.md` = gate ก่อน merge.

---

## 4. Phases

### Phase 1 — Frontend (customer flow) · ⬜ ยังไม่เริ่ม
ทำได้เลย (ใช้ `FakeApiClient` ไม่ต้องรอ backend). แผน = `plan.md`.

| Task | งาน | สถานะ |
|---|---|---|
| T0 | git branch (repo already inited in `pos-frontend/`) | ⬜ |
| T1 | Setup: deps, tokens, theme, Prompt font | ⬜ |
| T2 | Models + ApiClient interface + FakeApiClient (seed) | ⬜ |
| T3 | Shared widgets (brand, buttons, modal, toast, banner) | ⬜ |
| T4 | Router + CustomerController + persistence | ⬜ |
| T5 | CustomerPage shell (header, guide, hero) | ⬜ |
| T6 | Menu (promotions, toolbar, grid, food card) | ⬜ |
| T7 | Item detail modal (options/addons/note/qty/price) | ⬜ |
| T8 | Cart bar + cart modal + submit | ⬜ |
| T9 | Order tracking + bill banner + status meta + polling | ⬜ |
| T10 | Responsive + parity pass | ⬜ |

**Gate**: `flutter test` เขียว + `flutter analyze` clean + parity checklist (`test.md` §4) + security frontend (`security.md` §3 token storage/no HTML inject).

### Phase 2 — Backend (all endpoints) · ⬜ ยังไม่เริ่ม
ทำขนานกับ Phase 1 ได้ (ใช้ `FakeSheetsClient` เทสได้ offline). แผน = `plan-back.md`.

| Task | งาน | สถานะ |
|---|---|---|
| T0 | git branch (repo already inited in ``) + deps (jwt, predis) + install:api + config | ⬜ |
| T1 | Helpers + AppError + envelope exception handler | ⬜ |
| T2 | SheetsClient interface + FakeSheetsClient | ⬜ |
| T3 | SheetRepository (CRUD) | ⬜ |
| (T8a) | SeedData + seedDefaults *(ทำหลัง T3 ตาม build-order override)* | ⬜ |
| T4 | LockManager + IdempotencyManager | ⬜ |
| T5 | SettingsService + CatalogService | ⬜ |
| T6 | Totals + OrderService (submit/status/call) | ⬜ |
| T7 | Auth + middleware + Ops + Payment services | ⬜ |
| T8 | pos:setup command (ส่วนที่เหลือ) | ⬜ |
| T9 | AdminService (CRUD/settings/archive/rotate) | ⬜ |
| T10 | Routes + controllers + GoogleSheetsClient wiring | ⬜ |
| T11 | Manual smoke test (real Sheets) | ⬜ |

**Gate**: `phpunit` เขียว (unit+feature) + `pint --test` clean + security review (`security.md` §3-4: IDOR/idempotency/role gate/secrets/throttle+CORS).

### Phase 3 — Integration · ⬜
- สลับ Flutter `ApiClient`: `FakeApiClient` → `HttpApiClient` ชี้ Laravel.
- ตั้ง `POS_ORDER_BASE_URL` (Laravel) = URL Flutter customer.
- E2E: สแกน QR → สั่ง → ครัว → ปิดบิล ผ่าน stack จริง.
- Deploy: Laravel (server + Redis), Flutter web (host).

### Phase 4 — Staff/Admin frontend · ⬜
`front.md` §10 deferred: Flutter Login/Ops(kitchen/staff/cashier)/Admin UI (backend endpoint พร้อมแล้วจาก Phase 2). พอร์ตจาก `cp-pos/App.html` render functions + `Pages.html` ops-shell + `AdminUI.html`.

### Phase 5 — Enhancements (deferred) · ⬜
- WebSocket realtime (Laravel Reverb) แทน polling.
- Offline-first (Flutter local cache + sync).
- Drive image upload (admin assets).
- PDF receipt (`PdfService.js`).
- MySQL mirror ถ้า Sheets quota ยังตันหลัง cache.

---

## 5. Dependencies (ลำดับ)

```
Phase 1 (frontend) ─┐
                    ├─→ Phase 3 (integration) ─→ Phase 4 (staff/admin UI) ─→ Phase 5
Phase 2 (backend) ──┘
```
- Phase 1 + 2 ขนานกันได้ (fake ทั้งคู่).
- Phase 3 ต้องเสร็จ 1 + 2 ก่อน.

---

## 6. Risks / watch

| Risk | Mitigation | ref |
|---|---|---|
| Sheets quota (60 read/min) ตัน ตอน busy | Redis cache catalog/settings/session | back.md §4.3 |
| Race (2 คนสั่งพร้อมกัน) — ไม่มี transaction จริง | Redis lock + idempotency | back.md §4.1-4.2 |
| คนแก้ Sheet มือ ระหว่างเขียน → row เลื่อน | match by key column, append-only historical | back.md §3.3 |
| Money precision (PHP float) เพี้ยนจาก JS | `money()` round 2dp ทุก step | back.md §7 |
| Flutter parity ไม่ตรง 100% | pixel token exact + screenshot diff (T10) | front.md §2 |
| Partial multi-sheet write (closeTable พังกลาง) | write order + idempotency recovery | back.md §7 |

---

## 6.5 Coding agent + models (Codex)

AI ตัวเขียน = **Codex**. แยกโมเดล 3 ชั้นตามความยาก (รายละเอียด + ตาราง assignment เต็มใน `AGENTS.md` §4.5):
- **gpt-5.6-luna** — งาน mechanical (setup, tokens, helpers, seed, formatters) **+ git ทั้งหมด** (init/branch/commit/tick roadmap).
- **gpt-5.6-terra** (default) — งาน implement ทั่วไป (widgets, services, CRUD, routes).
- **gpt-5.6-sol** — งาน critical (order/payment/totals, auth, idempotency/lock, JWT/CORS/throttle) + **review pass ทุก task**.
- เกณฑ์: แตะเงิน/auth/idempotency/crypto/security → sol; feature ปกติ → terra; setup/constant → luna.

---

## 7. How to update this file

เมื่อ task เสร็จ (test ผ่าน + commit): เปลี่ยน ⬜ → ✅ ในตาราง Phase. เมื่อ phase เสร็จหมด: เปลี่ยนหัวข้อ phase ⬜ → ✅. เมื่อมี decision ใหม่: เพิ่มใน §2. เมื่อเจอ risk ใหม่: เพิ่ม §6.
