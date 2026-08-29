# Phase 5 — Enhancements Plan (deferred)

> **For agentic workers:** these are independent epics, each done AFTER Phases 1-4 ship. Same TDD + review + commit + PROGRESS loop (`AGENTS.md`). Do NOT start any of these unless explicitly told — Phase 5 is opt-in per epic. Each epic touches both repos where noted.

Overview: `roadmap.md §5`. Each epic below is self-contained; pick one, plan its detail inline as you go, ship it, tick PROGRESS.

---

### E1: Realtime (Laravel Reverb) — replace polling

**Repos:** `` (broadcast) + `pos-frontend/` (subscribe).
**Backend:**
- `composer require laravel/reverb`; configure Reverb + broadcasting.
- Broadcast events on order/call/session change: `OrderItemUpdated`, `CallUpdated`, `SessionClosed` — fired from OrderService/OpsService/PaymentService after each mutating write. Channels per role/table; authorize channel by staff token / table token (security.md §5 deferred).
- Keep REST endpoints; realtime is additive.
**Frontend:**
- Add a WebSocket client (`web_socket_channel` or pusher-compatible). On staff/customer screens, subscribe; on event → refresh the relevant slice. Fall back to polling if socket drops.
- Remove/relax the `max(5,pollSeconds)` timer when a live socket is connected.
**Done when:** kitchen sees a new order without waiting for the poll interval; socket-drop falls back to polling; tests cover the fallback.

---

### E2: Offline-first (customer)

**Repo:** `pos-frontend/`.
- Local store (IndexedDB via `drift`/`sembast`, or `hive`) for catalog + cart + queued orders.
- Queue submit while offline; sync when back online (idempotencyKey already dedupes server-side).
- Conflict policy: server totals win; local cart is advisory. Show offline banner (already exists) + "will send when online".
- **This is a real architecture change** — brainstorm/spec it first (it was explicitly deferred from the online-only MVP). Write a short spec before coding.
**Done when:** losing network mid-order keeps the menu usable and the cart intact; reconnect submits exactly once.

---

### E3: Drive image upload (admin assets)

**Repos:** `` (upload endpoint) + `pos-frontend/` (picker).
- Backend: extend Google auth scope to Drive (or use a public bucket); endpoint accepts image (validate mime + size ≤ 7MB, port `compressImage` limit App.html:1242), stores, returns URL. Reuse `sanitizeHttpsUrl` for the resulting link.
- Frontend: image picker in admin menu/promotion/brand forms → upload → set ImageURL.
- Security: validate content-type + size server-side; never trust client mime (security.md §5).
**Done when:** admin uploads a menu image and it renders on the customer card.

---

### E4: PDF receipt

**Repo:** `` (generate) or `pos-frontend/` (client-side).
- Port `PdfService.js` behavior: receipt PDF from a paid session (restaurant, table, items, totals, payment meta).
- Option A (backend): `barryvdh/laravel-dompdf` → endpoint `POST /api/receipt/pdf {token, sessionId}` returns PDF.
- Option B (frontend): `pdf`/`printing` package renders from the receipt JSON already returned by close-table.
- Prefer B (no extra backend load) unless a server-stored archive is needed.
**Done when:** cashier taps "สร้าง PDF" on the receipt modal and gets a downloadable/printable receipt.

---

### E5: MySQL mirror (only if Sheets quota still tight)

**Repo:** ``.
- Only pursue if, after Redis caching, real usage still hits Sheets quota.
- Add a read-through mirror in MySQL/Redis for hot reads (catalog/sessions); Sheets stays source of truth; writes go to Sheets then invalidate mirror.
- Measure first (log quota 429s); don't build speculatively.
**Done when:** read load no longer trips Sheets quota under target concurrency, Sheets still authoritative.

---

## Notes
- E1/E3/E4 are additive and low-risk. E2/E5 are architectural — spec before building.
- Each epic: add a "Phase 5 / Ex" section to the relevant repo's `PROGRESS.md`, tick as you go.
- Security review (sol) is mandatory for E1 (channel auth), E3 (upload validation), E2 (sync integrity).
