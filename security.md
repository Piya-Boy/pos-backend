# security.md — Security Requirements & Checklist (Phius Order)

> Security spec สำหรับ AI ตัวเขียน + reviewer. อ้าง cp-pos (มี control อยู่แล้ว) + จุดเสี่ยงใหม่ตอนย้ายเป็น Laravel + Sheets + Flutter web.
> **หลัก**: ระบบนี้จัดการ **เงิน** (บิล/ชำระ) + **auth staff** + **ข้อมูลลูกค้า(ออเดอร์)**. รับ input จาก public (customer ไม่ login) + staff.

---

## 1. Trust boundaries

```
[Public / customer]  ─ no auth, มีแค่ table Token (QR) ─┐
                                                        ├─→ Laravel API ─→ Google Sheets
[Staff: KITCHEN/STAFF/CASHIER/ADMIN] ─ PIN → token ─────┘         │
                                                          Redis (token, lock, cache)
```
- **Customer**: ไม่ login. สิทธิ์ผูกกับ **table Token** (secret ใน QR) เท่านั้น. ทำได้แค่: ดู catalog, สั่งของโต๊ะตัวเอง, ดูสถานะ session ตัวเอง, เรียกพนักงาน.
- **Staff**: PIN → bearer token. Role gate ต่อ action.
- **Server → Google**: Service Account (ความลับสูงสุด).

---

## 2. Threats + required controls

### 2.1 Authentication (staff)
- PIN hash = `sha256(salt + ':' + pin)` — **salt ต้องลับ + คงที่** (`POS_AUTH_SALT` ใน `.env`, ไม่ commit). ห้าม hardcode.
  - ⚠️ SHA-256 ธรรมดา (ไม่ใช่ bcrypt/argon) = เร็ว, brute-force ง่ายถ้า salt รั่ว. คง parity กับ cp-pos ได้ **แต่แนะนำ upgrade**: PIN สั้น (4-12) + shared salt = อ่อน. ถ้าทำได้ ใช้ `password_hash()` (bcrypt) ต่อ staff (per-user salt). ระบุเป็น improvement, ไม่บังคับ phase 1.
- Token = opaque `Str::random(64)` ใน Redis, TTL 6h, refresh ทุก request. ไม่ใช่ JWT (revoke ได้ทันทีด้วยลบ key).
- Initial PIN `zaq1234` → **บังคับเปลี่ยน** (`MustChangePin`) ก่อนใช้จริง. Login ครั้งแรกต้อง flow change-pin.
- **Rate limit login**: cp-pos ไม่มี. Laravel **ต้องเพิ่ม** — `throttle` middleware ที่ `/api/auth/login` (เช่น 10/นาที/IP) กัน PIN brute-force.

### 2.2 Authorization
- ทุก staff endpoint ผ่าน `StaffAuth` middleware + role list. ADMIN override ทุก role (ตรง cp-pos `requireAuth_`).
- Order status transition role-gated: KITCHEN→PREPARING/READY, STAFF→SERVED (Admin.js:160). ห้ามข้าม.
- **IDOR (customer)**: `getOrderStatus` ต้องเช็ค `session.TableID === table(byToken).TableID` (Services.js:152) — ลูกค้าโต๊ะ A ห้ามดู session โต๊ะ B. **ต้องพอร์ตเงื่อนไขนี้ครบ**.
- Admin CRUD เฉพาะ ADMIN. `archiveEntity` self-archive blocked; category-in-use blocked.

### 2.3 Input validation (public = ไม่เชื่อทุกอย่าง)
- `normalizeText(v, max)` strip `<>` + trim + จำกัดความยาว — ใช้กับ **ทุก** text input (port Code.js:187).
- Qty 1–20/item, ≤50 items/submit (Services.js:165,233). บังคับ server-side.
- Promo code, PIN: regex `^[A-Za-z0-9]{...}$`.
- URL (logo/hero/banner): `sanitizeHttpsUrl` — **https เท่านั้น** (กัน `javascript:`/`http:` mixed content).
- Hex color: `^#[0-9A-F]{6}$`. Percent: 0–100. Polling: 5–60.
- Payment method: whitelist CASH/TRANSFER/CARD/OTHER.
- **Server เป็นผู้คำนวณราคา/ยอดทั้งหมด** — ห้ามเชื่อ price/total จาก client (client ส่งแค่ itemId/qty/optionIds/addOnIds/note). Totals คำนวณจาก Sheet เสมอ (Services.js:255-257).

### 2.4 Money integrity
- ราคาต่อหน่วย + line total + session total **คำนวณฝั่ง server** จากข้อมูล Sheet เท่านั้น.
- `money()` round 2dp ทุก step — กัน float drift.
- **Idempotency บังคับ** ที่ submit + close-table (idempotencyKey) — กันสั่งซ้ำ/จ่ายซ้ำจาก retry/double-tap/network. Payment dedup by IdempotencyKey (Admin.js:209).
- closeTable: session ต้อง OPEN/PAYMENT_PENDING (กันปิดซ้ำ). Amount = totals ที่ recompute (ไม่เชื่อ client).

### 2.5 Table Token (customer secret)
- Token = secret ใน QR (`uuidPrefixed('tbl_')`). ใครมี Token = สั่งแทนโต๊ะนั้นได้ (design ยอมรับ — เหมือน cp-pos).
- **rotateToken** (admin) เมื่อสงสัยรั่ว. บล็อกตอนโต๊ะใช้งาน.
- Token ห้ามคาดเดา (UUID-based ✓). ห้าม sequential.

### 2.6 Secrets management
- `POS_AUTH_SALT`, Service Account JSON key, `GOOGLE_SPREADSHEET_ID`, Redis creds — **`.env` เท่านั้น, ไม่ commit**.
- `.gitignore` ต้องคลุม `.env` + `storage/google/service-account.json` (roadmap Task 0 gitignore).
- SA key file permission จำกัด (server-side only). ห้ามส่งไป client/ log.
- ห้าม log token/PIN/SA key. Envelope error ทั่วไป → `SERVER_ERROR` generic (ไม่ leak stack/detail — plan-back T1 handler).

### 2.7 Transport / web (Flutter web + Laravel)
- **HTTPS บังคับ** production (token ใน header, PIN ใน body).
- **CORS**: Laravel จำกัด origin = โดเมน Flutter app เท่านั้น (ไม่ `*`). ตั้งใน `config/cors.php`.
- Flutter web: token/session เก็บใน `shared_preferences` (web = localStorage) — ⚠️ XSS อ่านได้. Flutter ลด XSS surface (ไม่ inject HTML) แต่ระวัง `Uri`/webview. ไม่เก็บ PIN ฝั่ง client (แลก token ทันที).
- Customer cart/session ใน localStorage = ไม่ sensitive (ไม่มี auth) — OK.

### 2.8 Google Sheets specific
- SA scope จำกัด `spreadsheets` เท่านั้น (ไม่ให้ Drive กว้าง เว้นแต่ image upload phase หลัง).
- Share spreadsheet ให้ SA email เท่านั้น (ไม่ public).
- Manual-edit hazard: admin แก้ Sheet มือได้ (by design) — เตือนห้ามแก้ OrderSessions/OrderItems/Payments ระหว่างบริการ (integrity). append-only สำหรับ historical (back.md §3.3).

### 2.9 DoS / abuse
- Public endpoint (bootstrap/customer/submit/status/call) ไม่ login → เสี่ยง flood. เพิ่ม `throttle` ต่อ IP (เช่น submit 30/นาที).
- Sheets quota เอง = natural bottleneck → Redis cache กัน read flood.
- Lock timeout กัน hang (order 15s, payment 15s).

---

## 3. Checklist (reviewer เดินตาม)

### Auth/Authz
- [ ] Salt ลับ + คงที่ + ไม่ commit.
- [ ] Login rate-limited (throttle).
- [ ] MustChangePin flow บังคับหลัง login แรก.
- [ ] ทุก staff endpoint มี role gate; ADMIN override.
- [ ] Customer status/order เช็ค table Token ↔ session ownership (IDOR).
- [ ] Token revoke ได้ (ลบ Redis key ตอน logout/archive).

### Input/Money
- [ ] normalizeText + length cap ทุก text field.
- [ ] Qty/items bound server-side.
- [ ] URL https-only; hex/percent/polling/method whitelist.
- [ ] ราคา/ยอด คำนวณ server จาก Sheet เท่านั้น — ไม่เชื่อ client.
- [ ] Idempotency ที่ submit + close-table; payment dedup by key.
- [ ] money() round ทุก step.

### Secrets/Transport
- [ ] `.env` + SA key ไม่ commit (`.gitignore` คลุม).
- [ ] error handler ไม่ leak stack/secret.
- [ ] HTTPS + CORS origin จำกัด.
- [ ] ไม่ log token/PIN/SA key.
- [ ] SA scope = spreadsheets; sheet ไม่ public.

### Abuse
- [ ] throttle public endpoints.
- [ ] lock timeout ทุก critical flow.

---

## 4. รันตรวจ (เมื่อโค้ดพร้อม)

- Backend: `/security-review` หรือ security-review skill บน `app/Pos/` (โฟกัส Order/Payment/Auth/Admin service + middleware + envelope handler).
- Frontend: ตรวจ token storage, ไม่มี HTML injection, ไม่ hardcode secret.
- Manual: ทดลอง IDOR (โต๊ะ A ขอ session โต๊ะ B), replay submit (key เดิม), role bypass (KITCHEN เรียก admin), price tampering (ส่ง total ปลอม).

---

## 5. Deferred (phase หลัง)

- PIN hash upgrade → bcrypt/argon2 (per-user salt).
- 2FA/PIN lockout หลังผิดหลายครั้ง.
- Audit log review UI (AuditLog sheet มีอยู่แล้ว).
- Drive image upload → validate mime/size + virus scan.
- Reverb WebSocket auth (channel authorization ต่อ role).
