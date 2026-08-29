# PROGRESS — pos-backend (Laravel API, Phase 2)

> Task tracker for THIS repo. Tick ⬜→✅ + commit here after each task (this file lives inside the repo, so committing works).
> Plan = `plan-back.md`. Spec = `back.md`. Ops manual = `AGENTS.md`. Overall picture = `roadmap.md` (read-only, not per-task ticked).

Branch: `feature/laravel-backend`. Models per `AGENTS.md §4.5`.
**Build-order override**: do T8 Steps 1–4 (SeedData/seedDefaults) right after T3, before T5.

| Task | งาน | Model | สถานะ |
|---|---|---|---|
| T0 | git branch + deps (jwt, predis) + install:api + config | luna | ⬜ |
| T1 | Helpers + AppError + envelope exception handler | luna | ⬜ |
| T2 | SheetsClient interface + FakeSheetsClient | luna | ⬜ |
| T3 | SheetRepository (CRUD) | terra | ⬜ |
| T8a | SeedData + seedDefaults *(after T3, per override)* | luna | ⬜ |
| T4 | LockManager + IdempotencyManager | **sol** | ⬜ |
| T5 | SettingsService + CatalogService | terra | ⬜ |
| T6 | Totals + OrderService | **sol** | ⬜ |
| T7 | Auth + middleware + Ops + Payment services | **sol** | ⬜ |
| T8 | pos:setup command (remaining) | luna | ⬜ |
| T9 | AdminService (CRUD/settings/archive/rotate) | terra | ⬜ |
| T10 | Routes + controllers + GoogleSheetsClient (JWT/CORS/throttle) | **sol** | ⬜ |
| T11 | Manual smoke test (real Sheets) | terra | ⬜ |

**Gate (Phase 2 done)**: `phpunit` green (unit+feature) + `pint --test` clean + security review (`security.md §3-4`) + smoke test (T11) passes on a throwaway spreadsheet.
