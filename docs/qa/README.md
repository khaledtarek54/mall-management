# QA — manual testing & release sign-off

The automated suite (Pest + Playwright, PHPStan, the OpenAPI contract guard, the
security/N+1/a11y probes) proves the code *behaves*. This folder is the **human
layer** — the structured manual testing + UAT that automation can't judge
(does the workflow *feel* right end-to-end? does the business accept it?) — and
the **release sign-off gate** that says "ready for production".


## `tools/screen-watch.mjs` — walk screens in a real browser and report what breaks

**Why it exists.** Every defect found while working through the modules in late August 2026 surfaced
from the SCREEN, never from the suite: a 500 on a lease page after a component swap, a picker that
opened empty, a forecast row built from two different truths, a year input that saved the year
before the edit. Pest drives services and mounts components — a fatal in a render, a missing method
on a chained call, or an Alpine race lives past all of it.

The full Playwright suite covers this and takes ~25 minutes, which is why nobody runs it mid-task.
This is the same idea aimed at whatever is being worked on right now.

```bash
node tools/screen-watch.mjs                              # the default sweep
node tools/screen-watch.mjs /admin/AW/leases/1/edit      # named screens
node tools/screen-watch.mjs --actions /admin/AW/leases/1/edit
```

It reports four things, each named against the screen it came from: **HTTP ≥ 400**, **console
errors**, **uncaught JS**, and a **rendered exception** — because a page can answer 200 and still be
an error page, which is how `BadMethodCallException` reached a browser.

**`--actions` is the half that earns it.** An action builds its schema when it MOUNTS, so a record
page renders perfectly and blows up the moment somebody clicks — exactly what happened when
`MonthPicker` lost `minDate()`. The sweep opens each header action and checks the modal.

**It deliberately does not drive pickers.** `20-functional-create.spec.js` established the honest
pattern years earlier: fill what can be filled and let the error listener be the assertion.
Precisely automating a Filament combobox is brittle enough to cost more than it finds — measured
here, three failed attempts at selectors before the approach was abandoned.

Measured on the whole panel: **100 screens, clean**, in about a minute.


## What's here

| File | Purpose |
|---|---|
| [RELEASE-CHECKLIST.md](RELEASE-CHECKLIST.md) | The go-live gate. Every box must be ticked before a production release. Ties together the automated gates + manual QA + UAT + the runbook pre-flight. |
| [UAT-SCRIPTS.md](UAT-SCRIPTS.md) | End-to-end business scenarios per persona (operator / owner / tenant-web / tenant-mobile) that the **business** signs off — not "does it work" but "is this the workflow we run". |
| [PRE-STAGING-QA.md](PRE-STAGING-QA.md) | The harness report — ~620 assertions across 26 scenario scripts plus four two-process concurrency races, driving the **real services against real MySQL**. Findings and their fixes in [PRE-STAGING-FINDINGS.md](PRE-STAGING-FINDINGS.md). |
| [STAGING-FINAL-VERIFICATION.md](STAGING-FINAL-VERIFICATION.md) | The **final pre-staging verification** (2026-08-24) — eight lenses (Yardi · market/large systems · AR posting · AP/GL posting · recent-commit bugs · runtime ops · UI/UX · architecture), 82 findings, every one adversarially verified before being recorded. Read §0 for the market-position verdict. |
| [POST-STAGING-BACKLOG.md](POST-STAGING-BACKLOG.md) | What that verification found and deliberately did **not** fix, with the reason each can wait — plus §0, the nine MVP-blocking money fixes that shipped, so they are not re-opened from the report. |
| [scripts/](scripts/README.md) | The runnable harness behind `composer qa` (and `composer qa:baseline`), including `race.sh` — the two-process proof that a lock actually serialises, which sqlite can never give you. |

## How to use it

1. **Per release**, run `composer qa` — the harness restores the MySQL baseline before each suite, so a failure is the code and not the leftovers of the last run. A failure becomes a bug → fix → add a **regression test** (`tests/Feature/Regression/`) → re-run.
2. **Before go-live**, walk `UAT-SCRIPTS.md` with the business, then work through `RELEASE-CHECKLIST.md`.

> **The per-module manual test-case matrix was removed on 2026-08-19.** It had been written for two
> of thirty-seven modules and last touched in June; a matrix that covers 5% of the system reads as
> coverage and is not. What replaced it is real: the scenario suite (`tests/Feature/Scenarios/`),
> the conformance gates in `tests/Feature/Scenarios/`, and the MySQL harness in `scripts/`. UAT stays manual because the question
> it asks — *is this the workflow we actually run?* — is not one a test can answer.
3. Keep it **versioned** — these are living documents; update them in the same commit as the feature they cover (docs are part of "done").

## Coverage philosophy

100% line coverage is a *means*, not the goal — covered code can still be wrong,
and a tester clicking through is the only thing that catches "technically works
but is confusing / wrong for the business". The automated suite + this manual
layer + UAT sign-off together are what "confident before prod" means. See
the release checklist for the gate itself.
