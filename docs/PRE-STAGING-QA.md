# Pre-staging QA — Spacing · Leasing · Receivables · Payables

> **Run 2026-08-19** against an isolated `mall_management_qa` database seeded from `DemoSeeder`,
> driving the **real services, the real Filament pages and the real MySQL engine** — not the test
> suite. Roughly **620 assertions** across 26 scenario scripts plus four two-process concurrency races.
> The local `mall_management` database was never touched.

---

> **Coverage note (added after the first pass).** The first sweep did **not** exercise lease
> **options** at all — `ExerciseLeaseOptionService`, `LeaseOption`, `leases:scan-option-windows` and
> encumbrance had zero coverage in it — and its month cycle ran on whatever shapes `DemoSeeder`
> happened to produce rather than on a deliberate set. Both gaps are now closed by
> [`41_lease_options.php`](qa/scripts/41_lease_options.php) (54/54) and
> [`42_full_month_all_shapes.php`](qa/scripts/42_full_month_all_shapes.php) (47/47). **No new defects
> were found** — options were already well covered by `LeaseOptionWindowTest`,
> `LeaseOptionExerciseTest` and `LeaseOptionsUiTest`; the sweep had simply never run them.
>
> One thing that pass did correct: the *scheduled* billing run prorates a mid-month commencement
> (`prorate: true`, since 2026-08-08), while `planInvoiceForLease()`'s own default is `false`. Reading
> the signature gives the wrong answer; every real caller passes `true`.

> **Module coverage — ALL of them (2026-08-19).** The first pass covered the four core modules.
> The sweep has since been extended over every remaining module in `docs/modules/` except **16
> (ETA)**, which is out of scope by standing instruction. Roughly **950 assertions** in total. The
> later batches found **no defects**: modules 02, 03, 10, 11, 13, 14, 15, 17, 19, 20, 22, 23, 24,
> 25, 26, 27, 28, 30, 31, 34, 35 and 36 all behaved correctly, including several rules worth
> knowing about that are now pinned in the harness — resolving a tenant request needs EVIDENCE, a
> shopper-facing marketing post needs artwork, and a deep link is DERIVED from the record id rather
> than stored as a URL.
>
> Four things that looked like defects and were not, each checked before reporting: an approved
> payroll cancelling and voiding its GL entry (module 24 names that as the correction path, and a
> payroll has no separate payment document to strand); `/api/v1/me` returning 500 (my harness had a
> web `User` authenticated, so the resource wrapped the wrong model — a clean 401/200 without it);
> a re-sent announcement appearing to deliver twice (it returns the recorded count and sends
> nothing); and a bell row with no `url` key.

> **Status 2026-08-19 — Gates 1–4 and 7 are DONE.** Every finding below marked ✅ has been fixed,
> verified against MySQL with the scripts in [`qa/scripts/`](qa/scripts/), and left a regression test
> behind. What remains is Gates 5 and 6 — the staging box itself (queue worker, scheduler, off-box
> backups) and the pre-import audits, neither of which can be done from a workstation.
>
> **One decision changed on the evidence.** F-08 was first fixed by reducing the CAM denominator so
> the remaining tenants split what a stated share leaves. An existing test — `CamDenominatorTest`,
> with a stated rationale — showed that overturns a deliberate design: a neighbour's lease says *"your
> pro-rata share"*, so re-cutting them to cover a discount a third party negotiated over-bills them
> against their own terms. That change was reverted; only the harmful direction is now guarded. See
> F-08 below.

## 1. Verdict

**The money engine is sound.** The trial balance balanced after every single operation; AR and AP tied
to their source documents at every checkpoint; `billing:reconcile --deep` came back clean; and the
owner statement ties to the income statement to the penny. Every calculation I could check by hand —
VAT, proration on both edges, escalation ladders and collars, percentage-rent breakpoints, CAM
apportionment, withholding tax, GRNI clearing — produced the correct figure.

**Nine issues are worth acting on before staging.** One is a blocker (a whole module cannot be
operated), two are money-correctness issues in edge cases that will occur in production, two are
concurrency defects that only a real MySQL box reveals, and the rest are configuration and role-design
items an operator meets on day one.

| # | Sev | Area | One line | Status |
|---|---|---|---|---|
| **F-01** | **BLOCKER** | Spacing / unit owners | An ownership can never be given an assessment schedule — the صيانة run skips every one, forever | ✅ fixed |
| **F-02** | HIGH | Spacing / unit owners | A mid-month resale never rebalances the month: seller over-billed, buyer not billed, no credit | ✅ fixed |
| **F-08** | HIGH | Leasing / CAM | A contractually stated CAM share over-recovers the pool (measured 15%) and nothing reports it | ✅ fixed |
| **F-09** | HIGH | Concurrency | The double-booking and over-allocation guards do not fire under real concurrency | ✅ fixed |
| **F-04** | HIGH | Leasing | Nothing ever moves a lease from `active` to `expired` — occupancy overstates, units cannot be re-let | ✅ fixed |
| **F-10** | MED | Concurrency | Two document-number paths skip the numbering lock → duplicate-key 500s | ✅ fixed |
| **F-11** | MED | Receivables | An unpaid deposit invoice makes the weekly reconciliation report a discrepancy that is not one | ✅ fixed |
| **F-05** | MED | Spacing | Stored `units.status` goes stale on a date boundary; nothing re-projects on a schedule | ✅ fixed |
| **F-06** | MED | RBAC | The leasing role cannot open a single leasing report — but the read-only viewer can | ✅ fixed |
| F-07 | LOW | RBAC | Budget was super-admin-only | ✅ fixed (`budget.manage`) |
| **F-03** | LOW | Spacing / unit owners | `assessment_basis` was collected, validated, logged — and read by no calculation | ✅ fixed |
| F-13 | LOW | Owner statements | `finalise()` documented itself as idempotent and raised instead | ✅ fixed |
| C-01 | CONFIG | Payables | Withholding tax needs two switches | ✅ now a health-check row |
| C-02 | CONFIG | Receivables | The returned-cheque fee ships at zero | deliberate — a money default Yardi also ships unset; priced per property, [GO-LIVE §4 C-NSF](GO-LIVE.md) |

Full detail, reproduction and suggested fix for each: [`docs/qa/PRE-STAGING-FINDINGS.md`](qa/PRE-STAGING-FINDINGS.md).

---

## 2. How it was tested

Everything ran against **MySQL**, because the Pest suite runs on SQLite `:memory:` and three of the
findings below are invisible there by construction (SQLite compiles `lockForUpdate()` to nothing, and
a single-connection test never interleaves).

| Layer | Method |
|---|---|
| Business logic | The single-action services called directly with real fixtures, asserting figures computed by hand |
| Refusals | Every guard exercised with a **paired control** that must succeed, so a scope that hid everything could not read as a pass |
| Screens | All 76 module pages + 11 accounting pages rendered through the HTTP kernel as an authenticated user |
| Forms | `Livewire::test()` driving the `->live()` callbacks — the layer where a missing `use ($get)` once 500'd invoice creation for five days |
| Authorization | The full role × screen matrix, requested as each of the seven demo roles |
| Isolation | A manager assigned to one property only, then asked for the other property's URLs, records and queries |
| Concurrency | **Two separate PHP processes** on two MySQL connections, released against a shared wall-clock barrier |
| Lease shapes | One lease of each of 17 shapes, billed together through one month and one close (`42_full_month_all_shapes.php`) |
| Lease options | All 7 types recorded, encumbrance checked, exercised / waived / lapsed, window scan, renewal hand-off (`41_lease_options.php`) |
| Accounting | After every scenario: trial balance, AR tie-out, AP tie-out, GL drift sweep, deposits tie-out, `billing:reconcile --deep` |

Scripts are in the session scratchpad (`qa/*.php`), each re-runnable from a snapshot of the seeded
baseline so results are deterministic.

---

## 3. What was verified correct

Listed so the plan below can say what does **not** need re-testing.

**Billing engine** — rent VAT-exempt / service charge 14% / levy exempt; mid-month commencement with
and without proration; mid-month expiry always prorated; a last-day commencement billing one day
rather than zero; rent-commencement clipped per charge type (rent halves, service charge does not);
quarterly cadence billing only on a cycle start; a final partial quarter capped at expiry instead of
billing a whole quarter past it; gross vs rent-only fit-out abatement; run idempotency; an
overlapping schedule refused at both the write seam and the billing seam.

**Lease lifecycle** — the escalation ladder projected at signing and compounding correctly; the sweep
dated to the **anniversary** rather than the night it runs, and idempotent; a collar ceiling clamping a
mistyped 70% to 10%; a floor lifting a below-floor rate; amount steps unclamped by a percent collar;
CPI never invented; extension refusing to pull expiry backwards and re-projecting steps; renewal
carrying the whole term set including the collar; double-booking refused; holdover at a percentage of
passing rent.

**Termination & move-out** — unearned billing credited to the exact day-share (13/31 of a month);
earned invoices left standing; future-period invoices cancelled; a part-paid invoice never silently
cancelled; the deposit netted against arrears as a `DepositApplication`; forfeit and refund recorded;
deductions capped at the deposit held.

**Percentage rent** — artificial breakpoint, natural breakpoint, a tiered ladder charging each band
only within it, and a short first year correctly pro-rating the annual breakpoint (the case that
otherwise silently under-bills a tenant's first period).

**Relief** — a bounded window that ends by itself, resumes at the **post-step** amount, and does not
swallow a contracted escalation.

**Premises** — expansion and give-back **close** the `lease_unit` row instead of deleting it, so CAM
still sees the months the tenant genuinely held the space.

**Receivables** — all four settlement channels settling one invoice to exactly its total, and a fifth
pound refused; over-allocation refused on the form path and clamped on the gateway path; void invoice
/ void payment / partial and full write-off with reversal; late fees as their own dated invoice, with
grace, dispute handling and idempotency; post-dated cheques through clear, bounce and NSF fee; a
closed period refusing every operator-typed posting date.

**Payables** — a draft is not a payable; approval posting Dr expense + Dr VAT-recoverable / Cr AP;
payment capped at the balance; withholding tax computed on the **net** (3,000, not the 3,420 a gross
rate would give); void restoring the payable; cancel refused once money has moved; SLA penalties
deducting from the bill *and* posting their own entry; GRNI cleared by the vendor bill rather than
double-charging the expense.

**Property isolation** — foreign-property URLs 404; `/admin/ALL` 404; scoped queries return only the
selected mall; write guards refuse a foreign **or blank** property; a credit note and on-account credit
cannot cross a property; one payment cannot span two tenants.

**UI** — all 76 module pages render; every Create form mounts; the invoice form's live prefill runs,
derives the debtor from the lease, and overrides a crafted debtor in the payload; the property field
is pinned to the selected mall.

**Accounting** — trial balance balanced after every operation; AR and AP tied at every checkpoint; no
GL drift; owner statements tie to the income statement exactly and post Dr Owner Distributions / Cr
Due to Owner; revise supersedes and the sweep voids the superseded entry.

---

## 4. The pre-staging plan

Ordered. Each gate is a thing that can be checked, not a thing that can be believed.

### Gate 1 — Fix the blocker and the money-correctness issues
Nothing else matters until module 37 is operable and the two recovery paths are right.

- [x] **F-01** Mount `ChargeScheduleRelationManager` on `UnitOwnershipResource` (its `$relationship =
      'charges'` already matches `UnitOwnership::charges()`). Make `billing:run-assessments` report a
      handed-over ownership with no schedule as a **warning**, not a silent `skipped`.
- [x] **F-02** On transfer, credit the seller's unearned days and open the buyer's schedule (or bill the
      buyer's part of the month), inside the transaction that splits the tenure.
- [x] **F-08** Decide the CAM stated-share rule explicitly — either exclude stated participants from the
      derived denominator, or refuse/warn when Σ shares ≠ 100%. Add a check comparing Σ allocated to
      `total_actual_expense` **independently of** the stored residual, and surface a negative
      `landlord_unrecovered_amount` as over-recovery.
- [x] Re-run `qa/scripts/02_spacing_owners.php`, `qa/03_resale_proration.php`, `qa/60_cam.php`,
      `qa/F08_cam_stated_share.php` — all green.

### Gate 2 — Concurrency
These cannot be caught by the test suite; they need the two-process race scripts.

- [x] **F-09** Make the guard queries **locking reads**: `->lockForUpdate()` inside
      `Unit::isActivelyLeased()` and on the pivot sum in `Payment::assertInvoicesNotOverAllocated()`.
      Proven fix — at the same instant the locking read returned the correct answer and the plain read
      did not.
- [x] **F-10** Add `AllocatesDocumentNumber` to `Payment`; stop `LeaseCreationService` pre-computing the
      reference so the model's locked `creating` hook applies.
- [x] Re-run `qa/scripts/race.sh lease`, `payment`, `billing`, `pdc` — the loser must get the **business refusal**,
      never a duplicate-key error.

### Gate 3 — Data hygiene sweeps
- [x] **F-04** Add a daily `leases:expire` sweep (mirror `ExpireVendorContractsCommand`) and exclude
      ended leases from `RentEscalationService`'s query — two separate guards, both needed.
- [x] **F-05** Re-project `units.status` on the same schedule (or fold it into the sweep above).
- [x] **F-11** Include billed-but-unsettled deposits in the `deposits_tie_out` expectation so the weekly
      job stops reporting a discrepancy that is not one.

### Gate 4 — Roles and configuration
- [x] **F-06** Grant `reports.view` to `leasing`; grant `units.view` to `operations`. Confirm whether
      `marketing` should see sales analytics.
- [x] **F-07** Confirm Budget is intended to be super-admin-only.
- [x] **C-01** Set `TaxSettings::wht_default_tax_code` (or per-vendor codes) at the same time as
      `wht_enabled` — the switch alone withholds nothing.
- [ ] **C-02** Price `BillingSettings::nsf_fee_amount` per property — to recover the bank's own returned-cheque charge plus an administrative component. It ships at 0 on purpose ([GO-LIVE §4 C-NSF](GO-LIVE.md)).
- [ ] Walk [`docs/GO-LIVE.md`](GO-LIVE.md) and [`docs/STAGING.md`](STAGING.md) for the remaining
      credential and configuration rows.

### Gate 5 — Staging box readiness
`php artisan atriom:health` on the staging box must be green except the rows STAGING.md says are
expected to be red. On this workstation two rows failed and **both are real staging blockers**:

- [ ] **Queue worker running.** 701 jobs had piled up on the `database` queue with no worker. Notifications,
      the billing job and the late-fee job all ride it.
- [ ] **Scheduler installed.** `never ran (no heartbeat)`. Everything in §*Scheduled* — monthly billing,
      assessments, escalations, the ledger sweep, `billing:reconcile` — is inert without cron.
- [ ] **Backups on a non-local disk.** `backup_capability` warns that every `BACKUP_DISKS` destination is
      local; on staging that means the copy dies with the machine. Run `atriom:backup-verify` once.

### Gate 6 — Pre-import / pre-deploy audits (both currently clean)

**All four are now one command: `php artisan atriom:preflight`.** It runs them in order — health
first, because if the queue is dead the audits are measuring a box that would not have processed
anything anyway — and exits non-zero naming the steps that failed. Read-only by default:
`accounting:sync-ledger --all` is behind `--sync` because it **writes**, and a check that silently
repairs what it is checking cannot tell you the box was broken (the F-08 shape).

- [ ] `php artisan atriom:preflight --sync` after a restore — backfills the ledger
- [ ] `php artisan atriom:preflight` again, read-only — **this** is the gate

Verified against the clean seeded baseline: read-only reported `OwnerStatementRun #1` out of sync,
`--sync` fixed it, read-only then passed everything except `atriom:health` — which is correct on a
workstation with no queue worker and no cron.

The ordered end-to-end sequence, across this document, `STAGING.md` and `GO-LIVE.md`, is
[`docs/STAGING-CUTOVER.md`](STAGING-CUTOVER.md).

### Gate 7 — Regression cover for what was found ✅
Six new files in `tests/Feature/Regression/`, all green:

| Test | Covers |
|---|---|
| `UnitOwnerAssessmentIsReachableTest` | F-01 — the screen exists, the run reports `unconfigured`, ownership overlap is refused |
| `ResaleRebalancesTheMonthTest` | F-02 — seller credited, buyer's schedule carried, one month total |
| `CamStatedShareDoesNotOverRecoverTest` | F-08 — under-recovery preserved, over-commitment refused, independent check |
| `LeaseExpirySweepTest` | F-04 / F-05 — expiry, holdover exclusion, escalation guard, re-projection |
| `DepositInFlightTiesOutTest` | F-11 — in-flight deposits clean, a real one-road gap still caught |
| `ConcurrencyGuardsReadUnderLockTest` | F-09 / F-10 — **structure only**, see below |
| `LeasingAndOperationsRolesReachTheirScreensTest` | F-06 / F-07 |

**F-09 and F-10 cannot be proven by the suite**, and the test says so in its own docblock:
`SQLiteGrammar::compileLock()` returns `''` and one connection never interleaves. `LockSpy` pins
*which tables the guards lock*, which fails if a lock is removed and is not the same thing as proving
two transactions serialise. The real proof is `docs/qa/scripts/race.sh`, run against MySQL — after
the fix all three races end in the intended business refusal rather than a duplicate-key 500.

---

## 5. Recommendations beyond the brief

1. **The tie-out that cannot fail.** `Σ allocated + landlord_unrecovered == total_actual_expense`
   holds by construction because the generator writes the residual. It does catch later tampering, but
   it cannot see an error the generator itself made — which is the case that matters. Worth a sweep of
   the other registries for the same shape: a check whose expected value is derived from the thing it
   is checking is a check that only measures storage.

2. **Two truths about the same money, again.** F-11 is structurally the deposit-register bug the project
   already fixed once: a figure computed one way in the GL and another way in a register, with a check
   comparing them directly. The lesson from that fix — *derive, never copy* — applies to the check as
   well as to the data.

3. **Guards that read stale.** F-09 is not one bug; it is a pattern. Every `lockForUpdate()` followed by
   a plain-read guard has it. `ConcurrencyPolicy` registers **where** locks are taken but not **what the
   guard reads afterwards**. Consider extending it: a registered lock whose guard query is not itself a
   locking read is worth flagging, because on SQLite the difference is invisible.

4. **A green suite is a statement about SQLite.** Three findings here were unreachable from the test
   suite by construction. A small MySQL-backed suite — even a dozen cases covering locks, the enum
   CHECK behaviour, and the `select *, x, *` shape — would close a category that currently only a
   browser or a production incident can find.

5. **Reachability, not just classification.** F-01 is the third instance of the pattern the project
   already named: a fully built, fully tested service that no screen can reach.
   `ServiceReachability` proves a *service* is startable; it does not prove the *data it needs* can be
   created. An ownership that is billable but has no schedule, and no screen that can give it one, is
   invisible to every existing gate. A "can this record ever be billed?" check would have caught it.

6. **Make the assessment run louder.** `{"considered":8,"created":6,"skipped":2,"failed":0}` reads like
   success. Two owners went un-billed. Skips that mean *nothing to bill* and skips that mean *this
   agreement is misconfigured* should not share a counter.

7. **Do a staging dry-run of a whole month, on staging data.** This is the one recommendation that
   is still outstanding, and it cannot be done from a workstation — it needs the staging box and the
   operator's own data. Restore, run `billing:run-monthly` + `billing:run-assessments`, collect a
   realistic mix of payments, run `cam:reconcile`, close the period, produce the owner statement,
   and reconcile — then compare against the operator's own expectation for that month.

   **What HAS been done, on synthetic data:**
   [`42_full_month_all_shapes.php`](qa/scripts/42_full_month_all_shapes.php) builds one property with
   **one lease of every shape the engine supports** — plain monthly · quarterly on a cycle start ·
   quarterly mid-cycle · annual · mid-month commencement · mid-month expiry · gross fit-out ·
   net fit-out · mid-month rent commencement · holdover · escalating on the 1st · under a relief
   window · multi-unit · with a marketing levy · percentage rent · expired · terminated — plus a
   unit-owner assessment and **an open option of every one of the seven types**, one of them
   exercised mid-month. It then bills the month, checks each shape against a hand-computed figure,
   collects a mixed set of payments, books a vendor bill and an expense, produces the trial balance,
   income statement and balance sheet, ties the AR aging, closes the period, proves a back-dated
   receipt is refused, and runs `billing:reconcile --deep`. **47/47.**

   That is not a substitute for staging — it is synthetic data on one property — but it does mean
   the shapes themselves, and their interaction inside one month and one close, have been driven
   rather than assumed. What staging adds is the operator's own contracts, their own chart, and
   volume.

---

*Method note: this run happened while another session was editing the working tree. One transient
`LogicException` from a duplicate translation key appeared mid-edit and cleared on its own; it was not
a defect in committed code. Worth knowing separately, though: `lang/{en,ar}/admin.php` merges its
partials at **runtime** and throws on a duplicate key — so a bad merge is a total outage on every page
rather than a build failure. With CI paused, nothing catches it before deploy.*

---

## 6. What I would do next, in order

Written after the sweep covered every module, so these are ranked by what the evidence actually
showed rather than by what is conventionally on such a list. **Everything below except 6.7 is now
built** — 6.7 is an ops-monitoring choice rather than code. 6.5 was written up as a pure operator
decision and turned out to be half code and half decision; the code half is done. Status is marked
per item.

| # | Item | Status |
|---|---|---|
| 6.1 | Four new conformance gates for the gaps the findings came through | ✅ built |
| 6.2 | A MySQL-backed test tier | ✅ built |
| 6.3 | A `translations` health row | ✅ built |
| 6.4 | Import readiness for module 37 | ✅ built |
| 6.5 | Commission the inactive half of the tax catalogue | ✅ built — what remains is the accountant's ruling |
| 6.6 | A runnable harness (`composer qa`) | ✅ built |
| 6.7 | Watch the queue worker and the scheduler heartbeat | ⛔ ops, already reported by `atriom:health` |

### 6.1 Close the four gate gaps the findings came through ✅

Every finding sat in a gap *between* existing gates. This project's method is registry + gate, and
that method works — `ConcurrencyPolicy` caught my new locks and made me register them, and
`CamDenominatorTest` stopped me overturning a deliberate design. The gaps were specific, and each
now has a gate. **Every one was mutation-tested**: the registry was broken on purpose and the gate
watched to go red, because a gate that cannot fail is exactly the shape F-08 was.

| Gap | Finding | Gate now closing it |
|---|---|---|
| A billable agreement that can never be billed | **F-01** | `BillableAgreementIsConfigurableConformanceTest` — for every `BillableAgreement`, assert a charges relation manager exists **and** an importer resolves it. `ServiceReachability` proves a service is startable; this proves the data it needs can be created, by both roads |
| A guard query behind a lock that is not itself a locking read | **F-09** | `ConcurrencyPolicy::AUTHORITATIVE_GUARDS` + two cases in `ConcurrencyPolicyConformanceTest` that read each named guard's **own method body** and require a locking read in it. The registry already said *where* locks are taken; this says which decisions are allowed to rest on one. On SQLite the difference is invisible, which is why it needs a gate rather than a review |
| A check whose expected value is derived from its subject | **F-08** | `ReconciliationChecksCanFailConformanceTest` — perturbs each of the four tenant-facing checks and requires it to go red, plus a control that requires all four green on clean data. Reading a check tells you what it compares; only mutation tells you what it notices |
| A stored value that goes stale on a date boundary with no sweep | **F-04 / F-05** | `App\Support\ProjectedState` + `ProjectedStateConformanceTest`. Four teeth, because the registry alone would have passed the original state: the projector must exist, the sweep must exist, the sweep must be **scheduled** (`recomputeStatus()` existed all along — nothing called it on a timer), and a second consecutive run must find no work. `NOT_PROJECTED` records the four columns that look like projections and deliberately are not |

`Lease::hasExpiredTerm()` came out of this as the single definition of "the term has run out",
shared by `leases:expire` and `RentEscalationService`, so the sweep and the guard against acting on
an un-swept lease cannot drift apart.

### 6.2 A MySQL-backed test tier ✅

Three findings (**F-08**, **F-09**, **F-10**) were structurally invisible to the suite:
`SQLiteGrammar::compileLock()` returns `''` and one connection never interleaves.

`tests/Mysql/` is now a separate `phpunit.xml` testsuite that **skips unless the connection is
actually MySQL**, so the ordinary `pest --parallel` run is unchanged. It covers the three properties
SQLite cannot tell the truth about: a row lock compiling to real `for update` / `lock in share mode`
SQL; no DB-level enum column left, read from `information_schema` rather than inferred; every
`ValueSets` value fitting the width of the column that must accept it; and **every
globally-searchable resource query executing** rather than merely compiling — the exact shape that
500'd the fixed-asset list, the register CSV and the whole search bar in production with 5,180 tests
green. That last case proves its own premise first: if MySQL ever stopped rejecting
`select tbl.*, x, *`, the test says so rather than passing vacuously.

Run it with `composer test:mysql` (after `composer qa:baseline`).

### 6.3 Guard the translation merge at deploy time ✅

`lang/{en,ar}/admin.php` merges its partials at **runtime** and throws `LogicException` on a
duplicate top-level key. Verified by injecting one: the merge throws, and **`atriom:health` did not
see it** — it reported all 17 rows normally. Because `__('admin.*')` is on every page, a bad merge is
a total outage rather than a broken screen, and the merge file's own comment says this runtime guard
is the only cross-partial check there is.

`atriom:health` now carries a `translations` row that loads both locales and reports the failure by
file and key. Mutation-verified — with a duplicate key injected it reads:

```
| translations | FAIL | en: Duplicate admin translation key(s) in system.php: fields |
```

### 6.4 Import readiness for module 37 ✅

F-01 was fixed at the screen, but the same shape stayed open through the import door: there was **no
way to load a sold unit's assessment schedule in bulk**, so a migrating operator loading a portfolio
of sold units would have ownerships no assessment run could bill — silently, exactly as before.

Rather than a second importer, `ChargeScheduleService` was generalised from `Lease` to the
`BillableAgreement` contract it should always have taken (it keys off `invoiceLinkAttributes()`
now, so a third agreement type needs no change here), and `ChargeImporter` gained an
`ownership_reference` column beside `lease_reference` — refusing a row that names both and a row
that names neither, and resolving the ownership within the property scope. The importer is mounted
on the unit-ownerships list as **Import assessments**. `BillableAgreementIsConfigurableConformanceTest`
is what stops the next agreement type shipping with one road open and the other closed.

### 6.5 Decide the inactive half of the tax catalogue ✅ *commissioned 2026-08-19*

**16 of 30 tax codes shipped inactive** — every stamp and schedule code, in both directions — and
this was written up as a decision the operator had to make, because the stated blocker was "their GL
accounts are not wired".

**That was true, and it was the smaller half.** All three journalizers threw the document's own
`tax_code` away: `InvoiceJournalizer` summed every line's tax into one accumulator and credited
`vat_payable`, and `VendorBillJournalizer` / `ExpenseJournalizer` hard-coded `vat_recoverable`. So
activating stamp tax would have put 20% of a supply onto the **VAT return**, under the **VAT
liability**, with the entry balancing and the tie-out green. `invoice_items.tax_code` had recorded
the right answer since the catalogue shipped; the posting simply never read it.

Tax now groups by its own posting role — the same shape revenue already used a few lines above in
the same method — with VAT as the floor for a document naming no code. Four new accounts, and the
asymmetry is the real content: output stamp/schedule are **liabilities**, input stamp/schedule are
**expenses**, because neither has a credit mechanism the way input VAT does. Booking them as
recoverable would have grown a receivable nobody could ever collect.

What remains is genuinely the accountant's, and is now stated as such in
[GO-LIVE §4, C-TAX](GO-LIVE.md): **which supplies carry these taxes.** Activation grants nothing on
its own — a tax code taxes a supply only when a charge code points at it, which is a row, not a
deploy. Pinned by `TaxPostsToItsOwnAccountTest`, mutation-tested.

### 6.6 Make the harness runnable by someone who is not me ✅

`docs/qa/scripts/` is ~40 scripts that only help if they are run. There are now two entry points:

- `composer qa:baseline` — creates the QA database, migrates and seeds it, and dumps `baseline.sql`
  (gitignored). One-time, and repeated whenever the schema or the seeder moves.
- `composer qa` — restores that baseline **before each suite** and runs the scripts in order,
  reporting a pass/fail total. Takes an optional filter argument to run one suite.

Per-suite reset is the point: the first version of this harness produced two false findings from
state one script left behind for the next.

### 6.7 Watch the two things everything else depends on ⛔ *ops*

`atriom:health` correctly reported both as FAIL here: **701 jobs queued with no worker**, and the
scheduler **never ran (no heartbeat)**. Roughly thirty behaviours — billing, assessments,
escalations, the ledger sweep, `billing:reconcile`, the expiry sweep added in this work — are inert
without cron, and none of them fail loudly when they simply never run. The check already exists and
already goes red; what is missing is something outside the app watching it. Whatever monitors the
staging box should watch that heartbeat, not just that the site returns 200.
