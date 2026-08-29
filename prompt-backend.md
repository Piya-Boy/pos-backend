# Codex prompt — build the backend API (copy-paste this)

---

Read `AGENTS.md` at the repo root first, then follow it exactly.

Your job: build the **Laravel backend API** (Phase 2, then Phase 3 backend side) — a REST port of the old `cp-pos/` Apps Script system, with **Google Sheets as the DB** and **Redis** for lock/cache/auth. All tests run offline against `FakeSheetsClient` + the `array` cache store — no real Google/Redis needed until the T11 smoke test.

Work in the `` repo (this repo). Execute `plan-back.md` task-by-task using the loop in `AGENTS.md §0/§3`:

1. Pick the lowest-numbered ⬜ task in `PROGRESS.md`.
2. **Build order override**: do T8 Steps 1–4 (SeedData/seedDefaults) right after T3, before T5 — Feature tests need it.
3. Switch the Codex model per `AGENTS.md §4.5` (backend: T0-T2 luna, T3/T5/T9/T11 terra, T4/T6/T7/T10 sol; review always sol).
4. For each plan step: write the failing test → confirm it fails → write minimal code → run test → pass. Fix-loop until green.
5. Self-review the diff (`AGENTS.md §3.2`) + check `security.md §3` items that apply (auth, IDOR, money integrity, idempotency, secrets). Fix real findings.
6. `./vendor/bin/phpunit && ./vendor/bin/pint --test` must be green. Never commit red.
7. Commit (Conventional Commit, no Co-Authored-By, from ``).
8. Tick the task ⬜→✅ in `PROGRESS.md`, commit that. Do NOT touch `roadmap.md`.
9. Go to the next task.

**DO NOT stop to report progress mid-way.** A task finishing, tests passing, "should I continue?", a status summary — none are stopping points. After a task commits + PROGRESS is ticked, immediately start the next task silently. Hand control back ONLY when (1) all Phase 2 + Phase 3 backend tasks are ✅, or (2) a real BLOCKER (`AGENTS.md §6`). Unsure if it's a blocker? It isn't — keep going. Don't ask permission, don't wait.

Hard rules (`AGENTS.md §5`): TDD always; **server owns all money math** (never trust client price/total); keep idempotency + Redis locks on submit/close-table; Sheets touched ONLY via `SheetRepository`; secrets never committed/logged; the plans are already written — **do NOT re-plan or redesign**, just execute; Thai user-facing strings verbatim.

After Phase 2 is fully ✅, continue with the backend tasks in `plan-integ.md` (P3-T3 CORS, P3-T4 smoke). Then stop (Phase 5 is opt-in — don't start it).

Start now with Phase 2 Task 0.
