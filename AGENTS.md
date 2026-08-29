# AGENTS.md — Autonomous Build Instructions (Phius Order)

> **You are the coding agent. Read ONLY this file to start. It tells you everything: what to build, in what order, and the exact loop to run per task — no human prompting needed.**
> Language: code + comments English. Thai UI/error strings stay verbatim (from cp-pos).

---

## 0. TL;DR loop (do this for every task, forever, until all done)

```
pick next unchecked task in roadmap.md
  └─ read the task in its plan (plan.md or plan-back.md)
       └─ LOOP:
            1. write failing test        (TDD — test first)
            2. run test → confirm it FAILS
            3. write minimal code to pass
            4. run test → PASS?  ── no ──> fix, go to 3
            5. run review (self)  ── issues? ── yes ──> fix, go to 1/3 as needed
            6. run lint + full suite ── fail ──> fix, go to 3
            7. commit (Conventional Commit)
            8. tick the task ⬜→✅ in THIS repo's PROGRESS.md, commit that
       └─ go pick next task
stop only when: every task in this repo's PROGRESS.md is ✅, OR you hit a BLOCKER (see §6).
```

**Never ask the human between tasks. Just proceed to the next.** Only stop for a real BLOCKER (§6).

---

## 1. Documents (read these; do not invent)

| File | Use it for |
|---|---|
| `roadmap.md` | Task list + status. **Source of "what's next".** Tick tasks here. |
| `plan.md` | Frontend implementation plan (Flutter). Per-task TDD steps with real code. |
| `plan-back.md` | Backend implementation plan (Laravel). Per-task TDD steps with real code. |
| `front.md` | Frontend spec. Referenced by `plan.md` via §numbers. |
| `back.md` | Backend spec. Referenced by `plan-back.md` via §numbers. |
| `test.md` | Testing standard + coverage matrix + extra gaps to cover. |
| `security.md` | Security requirements + checklist. Apply during review. |
| `cp-pos/` | **Source of truth** (old Apps Script system). When a rule is unclear, read the cited `file:line`. |

**Order of authority**: the plan step's code > spec (`front.md`/`back.md`) > `cp-pos/` source > this file's general rules.

---

## 2. Which task is next

**Each repo tracks its own progress in its own `PROGRESS.md`** (committable, because it lives inside the repo). `roadmap.md` at the project root is the big-picture overview only — read it for context, but do NOT try to tick/commit it (it's outside both repos → committing fails).

- Working in `pos-frontend/` → tasks from `plan.md` (T0…T10), tracked in `pos-frontend/PROGRESS.md`.
- Working in `` → tasks from `plan-back.md` (T0…T11), tracked in `PROGRESS.md`. Respect the **build-order override** (T8 Steps 1–4 right after T3).
- A task is "next" if it's the lowest-numbered ⬜ in this repo's `PROGRESS.md`.
- The two repos are independent; pick whichever you were told to build. If both, finish backend before Phase 3 integration.

---

## 3. The per-task loop (detailed)

For the chosen task, the plan already lists numbered TDD steps. Execute them, then wrap with review + commit:

### 3.1 Build (TDD)
- Do each plan step in order. Write the **failing test first**, run it, confirm the failure message matches the plan's "Expected: FAIL …".
- Write the **minimal** code to pass. No extra features (YAGNI). Match surrounding style.
- Re-run the test until it passes.
- Also cover the relevant **extra gaps** in `test.md` §2.3 / §3.3 for this task if cheap.

### 3.2 Review (self, before commit)
Run a self-review on the diff. Use the review skill if available:
- **Invoke `/code-review`** (or `cavecrew-reviewer` / `security-review` skill) on the working diff.
- Also manually check against `security.md` §3 checklist items that touch this task (auth, input validation, money integrity, secrets, IDOR).
- **If review finds a real issue → fix it → go back to 3.1** (add/adjust test, re-implement, re-run). Repeat until review is clean. Do not commit with known real findings.
- Ignore nitpicks that don't change behavior/security.

### 3.3 Verify (must be green before commit)
Run, in the task's repo dir:
- Frontend: `cd pos-frontend && flutter test && flutter analyze`
- Backend: `cd pos-backend && ./vendor/bin/phpunit && ./vendor/bin/pint --test`
- **All green required.** Any failure → fix → 3.1/3.3. Never commit red.

### 3.4 Commit
- **Two separate git repos**: frontend → `pos-frontend/` (branch `feature/customer-frontend`), backend → `` (branch `feature/laravel-backend`). There is **no** repo at the project root. Commit inside the repo you're working; run git from that dir; paths are relative to it (`git add lib test`, not `git add pos-frontend/lib`).
- Conventional Commit (`feat:`/`test:`/`fix:`/`chore:`). Message = what the task delivered.
- **No `Co-Authored-By`.** Author = git user only.
- If plan commit blocks show `cd .POS` + `pos-frontend/`-prefixed paths, translate to the repo dir (they predate the repo split) — the plan's Global Constraints say the same.
- Both repos already `git init`'d on `main`; the plan's Task 0 makes the feature branch. Never commit on main/master.

### 3.5 Advance
- Edit **this repo's `PROGRESS.md`**: change this task's ⬜ → ✅.
- Commit that (in this repo): `chore: mark <task> done`.
- Go to §2 and pick the next task.

---

## 4. Plans already exist — DO NOT re-plan

**The plans are written.** `plan.md` (frontend) and `plan-back.md` (backend) contain every task with real TDD code steps. **Your job is to EXECUTE them, not to plan again.**

- Do **NOT** run brainstorming, design, or plan-writing skills (`superpowers:brainstorming`, `superpowers:writing-plans`). No new spec, no new plan, no "let me first design…". That work is done.
- Just open the next task's steps in the plan and run the loop (§0/§3).
- If a plan step is wrong or missing something, that's a BLOCKER (§6) — surface it; do not silently re-plan around it.

### Skills to use (if available)
- **`superpowers:executing-plans`** or **`superpowers:subagent-driven-development`** — execute a *written* plan task-by-task. Use these; they do NOT re-plan.
- **`superpowers:test-driven-development`** — the test-first discipline for §3.1.
- **`superpowers:systematic-debugging`** — when a test fails and the cause isn't obvious (find root cause, don't guess-patch).
- **`/code-review`** / **`security-review`** / **`cavecrew-reviewer`** — for §3.2 review.
- **`superpowers:verification-before-completion`** — before ticking ✅, confirm tests actually ran + passed. No "should pass" claims.
- **Docs when unsure**: backend → https://laravel.com/framework/docs/ ; frontend → https://docs.flutter.dev/ ; Sheets → https://developers.google.com/sheets/api. Do **not** guess API signatures.

If a skill isn't available, follow this file's loop manually — same steps.

---

## 4.5 Model selection (Codex — pick per task by difficulty)

Coding agent = **Codex**. Three models, pick by task difficulty. **Switch the Codex model to the one listed before starting each task.** When in doubt, go one tier up.

| Model | Use for | Why |
|---|---|---|
| **gpt-5.6-luna** | Mechanical / deterministic tasks: pure setup, constants, seed data, simple mappers, **and all git ops** (init, branch, commit, tick roadmap ✅). | Cheapest; these tasks have one obvious correct output. |
| **gpt-5.6-terra** (default) | Standard implementation: widgets, plain services, CRUD, routes, controllers, models. | Balanced everyday coding. Most tasks. |
| **gpt-5.6-sol** | Hard / critical / security-sensitive logic + every **review pass**. | Frontier reasoning where correctness & security matter most. |

### Assignment

**Frontend (`plan.md`)**
| Task | Model |
|---|---|
| T0 git init, T1 setup/tokens/theme | luna |
| T2 models + FakeApiClient, T3 shared widgets | luna |
| T4 router + controller, T5 page shell, T6 menu | terra |
| T7 item modal (live price + required validate) | **sol** |
| T8 cart + submit, T9 tracking + polling | terra |
| T10 responsive + parity pass | terra |

**Backend (`plan-back.md`)**
| Task | Model |
|---|---|
| T0 deps/config, T1 helpers, T2 FakeSheetsClient, T8(seed) SeedData | luna |
| T3 SheetRepository | terra |
| T4 Lock + Idempotency | **sol** |
| T5 Settings + Catalog | terra |
| T6 Totals + OrderService | **sol** |
| T7 Auth + middleware + Ops + Payment | **sol** |
| T9 AdminService CRUD | terra |
| T10 routes + controllers + GoogleSheetsClient (JWT/CORS/throttle) | **sol** |
| T11 manual smoke | terra |

### Rule of thumb (if a task isn't in the table)
- Touches money / auth / idempotency / locks / crypto / security → **sol**.
- Normal feature code → **terra**.
- Copy-paste-level setup / constants → **luna**.
- **All git operations run on `luna`** — commit (§3.4), branch, and updating `roadmap.md` ticks (§3.5). Build the code on the task's assigned model, then switch to luna to commit + tick.
- **Review step (§3.2) always runs on `sol`**, regardless of the build model.

---

## 5. Hard rules (never violate)

1. **TDD**: test before code, always. Confirm the test fails first.
2. **Never commit red**: tests + lint must pass. No skipped/commented-out tests to go green.
3. **Parity**: frontend must match `cp-pos/` — port token values (`front.md` §2) exactly, Thai strings verbatim.
4. **Server owns money**: backend computes all prices/totals from Sheets; never trust client-sent price/total (`security.md` §2.3-2.4).
5. **Idempotency**: keep it on submit + close-table. Don't remove locks.
6. **Secrets**: never commit `.env`, SA key, salt. Never log token/PIN/SA key. `.gitignore` must cover them.
7. **No scope creep**: build only what the current task/plan says. New ideas → note in roadmap §6, don't implement.
8. **No real external calls in tests**: frontend uses `FakeApiClient`, backend uses `FakeSheetsClient` + `array` cache. Real Google/Redis only in manual smoke (backend T11).
9. **Small commits**: one task = one (or few) focused commits. Don't batch multiple tasks into one commit.
10. **Author = git user, no Co-Authored-By.**
11. **Always update this repo's `PROGRESS.md`**: after every task passes + commits, tick its ⬜→✅ and commit that (§3.5). Never leave a done task unticked or tick an unfinished one. (`roadmap.md` at root is overview-only — do not commit it; it's outside the repo.)

---

## 6. When to STOP and surface a BLOCKER

Do NOT keep looping if:
- A plan step references something that does not exist and no `cp-pos/` source clarifies it (genuine ambiguity).
- Tests can't run because of missing infra you can't provision (e.g. real Google credentials needed before T11 — but everything before T11 must run on fakes; if it can't, that's a plan bug → surface it).
- The same test fails 3+ times after distinct fix attempts and `systematic-debugging` doesn't resolve it.
- A security rule (§5) and a plan step conflict.

When blocked: write the exact task, step, what you tried, and the error, then stop and wait. Do not hack around a blocker or weaken a test/security rule to proceed.

---

## 7. Definition of done (whole build)

- **Phase 1 done**: all `plan.md` tasks ✅; `flutter test` + `flutter analyze` green; parity checklist (`test.md` §4).
- **Phase 2 done**: all `plan-back.md` tasks ✅; `phpunit` + `pint --test` green; security checklist (`security.md` §3) reviewed; smoke test (backend T11) passes against a real throwaway spreadsheet.
- Then stop and report: which tasks done, test output summary, anything deferred to Phase 3+ (integration, staff/admin UI, realtime) per `roadmap.md`.
