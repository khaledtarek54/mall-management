# QA — manual testing & release sign-off

The automated suite (Pest + Playwright, PHPStan, the OpenAPI contract guard, the
security/N+1/a11y probes) proves the code *behaves*. This folder is the **human
layer** — the structured manual testing + UAT that automation can't judge
(does the workflow *feel* right end-to-end? does the business accept it?) — and
the **release sign-off gate** that says "ready for production".

## What's here

| File | Purpose |
|---|---|
| [RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md) | The go-live gate. Every box must be ticked before a production release. Ties together the automated gates + manual QA + UAT + the runbook pre-flight. |
| [UAT-SCRIPTS.md](UAT-SCRIPTS.md) | End-to-end business scenarios per persona (operator / owner / tenant-web / tenant-mobile) that the **business** signs off — not "does it work" but "is this the workflow we run". |
| [test-cases/](test-cases/) | The exhaustive manual test-case repository — one file per module, every feature × every role × happy / negative / boundary / permission path. Start from [`_TEMPLATE.md`](test-cases/_TEMPLATE.md). |

## How to use it

1. **Per feature/release**, execute the relevant `test-cases/NN-*.md` files — mark each case ✅ / ❌ / ⏭️ with the date + tester. A ❌ becomes a bug → fix → add a **regression test** (`tests/Feature/Regression/`) → re-run.
2. **Before go-live**, run the full `test-cases/` matrix + the `UAT-SCRIPTS.md` with the business, then work through `RELEASE-CHECKLIST.md`.
3. Keep it **versioned** — these are living documents; update them in the same commit as the feature they cover (docs are part of "done").

## Coverage philosophy

100% line coverage is a *means*, not the goal — covered code can still be wrong,
and a tester clicking through is the only thing that catches "technically works
but is confusing / wrong for the business". The automated suite + this manual
layer + UAT sign-off together are what "confident before prod" means. See
[docs/plans/02-qa-testing-plan.md](../plans/02-qa-testing-plan.md) for the full
program and its live status.
