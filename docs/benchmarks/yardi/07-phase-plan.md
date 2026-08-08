# 07 — The recommendation: what to do in this cycle

> **The cycle:** *Leasing & money flow — the Yardi cycle.* Its purpose is to replace the one wrong
> structure in Atriom's lease model, complete the money flow around it, and stop rebuilding the
> parts that are already right.

---

## 0. The headline recommendation

**Do not rebuild the leasing module. Rebuild one thing inside it: the way charges are written.**

Atriom stores the lease's *current state* and mutates it. Yardi stores the lease's *schedule* and
reads it. Seven of the fifteen scenarios in [04](04-scenarios.md) break on that single difference,
and the four biggest items in the gap analysis — escalation history, forward visibility,
straight-line rent, amendments — are all downstream of it.

**The cost of fixing it is far smaller than it looks**, because the storage and the read path
already support it: `charges` has `start_date`/`end_date` and `MonthlyBillingService` already
selects by date range. What is missing is that **nothing writes more than one row**, and three
services assume there is only one. This is an inversion of the write path, not a rewrite of the
module.

**And do not touch the money core.** `Invoice::recomputeTotals()`, the over-allocation guard, credit
durability, the credit-note un-apply, the GL registry with its conformance gate, the closed-period
guard, the deletion policy — several of these are stronger than the benchmark. The defect is
upstream, in lease → charge. It is not downstream, in charge → invoice → cash → GL.

---

## 1. The three questions — RESOLVED to the standard (2026-08-08)

The operator's instruction was *"answer them from Yardi — I want the system to work as standard
systems do."* For two of the three there is a clear industry standard, so they stop being blocking
decisions: **implement the standard as the default, support the alternative, let the lease decide
per tenant.** That is exactly Yardi's model.

### Q1 — Straight-line rent → **BUILD IT, SHIP IT OFF**

**EAS 49** (Egypt's leases standard, issued 2020, aligned with IFRS 16, effective for periods from
January 2021) recognises lessor **operating-lease income on a straight-line basis**. Yardi
implements it as a core function. So the standard answer is yes.

**The caveat that shapes the build:** EAS 49 governs the *financial statements*; Egyptian **tax**
generally follows the invoices issued. Standard systems reconcile this with **multiple books** —
Yardi posts straight-line rent to the accrual book and not the cash/tax book. **Atriom is
single-book** and [deliberately staying that way](06-atriom-gap-analysis.md).

**Decision:** build the straight-line schedule + posting as its own **registered GL source**
(`Deferred Rent Receivable`), clearly labelled and separately reportable so a tax view can exclude
it, **behind a setting that ships OFF**. This *is* the standard behaviour for a system: straight-line
posting is something a Yardi install **enables**, not something that happens to it. Nobody's trial
balance is silently restated, and the capability is there the day the accountant wants it.

**Still needs a human — but not blocking:** the accountant flips the switch, given a before/after.

### Q2 — Fit-out grace → **STANDARD IS RENT-ONLY. Today's behaviour gives revenue away.**

The industry terms are **net abatement** (base rent free; tenant still pays CAM / service charge /
taxes / insurance) and **gross abatement** (everything free). **Net is the norm**, because the
landlord is still incurring those costs while the tenant fits out. Lease-drafting guidance is
explicit: *"if the word CAM or operating expenses does not appear in the abatement clause, those
obligations are almost certainly continuing."*

Atriom implements **gross** abatement — `fit_out_months` suppresses the entire invoice — and cannot
express net at all. On a 36,000/month service charge with 3 fit-out months that is ~**108,000 per
new tenant** the operator is likely entitled to and does not bill.

**Decision:** make abatement **per-charge** (the Yardi model, story LS-05), and default new leases
to **rent-only**. **Existing leases keep the full-grace behaviour they were actually billed under**
— no retroactive rebilling. LS-05 is promoted 🟠 → 🔴.

**Worth a human check, not blocking:** read the fit-out clause in one recently-signed lease. If it
is silent on the service charge, the standard says it is chargeable.

### Q3 — Percentage rent → **STANDARD IS CUMULATIVE YTD + ANNUAL RECONCILIATION**

The normal retail structure: tenant **reports sales monthly** · landlord **bills monthly or
quarterly on account** · **reconcile cumulatively year-to-date** at lease-year end, with a balance
due or a credit back. And specifically for seasonality: *"in highly seasonal businesses it may be
more appropriate to structure breakpoints on a quarterly or annual basis"*; Q4-heavy retailers
negotiate higher artificial breakpoints. Ramadan/Eid concentration makes Egypt more seasonal than
most markets.

Atriom is period-only with no settle-up, so it sits on the **over-billing** side of
[S12](04-scenarios.md#s12--percentage-rent-for-a-seasonal-tenant) (160,000 vs 0 on the same sales).

**Decision:** build cumulative YTD + the annual reconciliation (PR-01), **default new leases to
cumulative**, keep `period` as a per-lease option because some leases do say monthly. This is the
same estimate → reconcile → true-up shape CAM already implements, so the pattern exists.

**Flag:** if an existing lease's clause says cumulative, that tenant has been over-billed. Worth
reviewing the percentage-rent clauses before the next reconciliation — a restitution question, not
an engineering one.

### The principle these three share

**The system implements the standard as the default and supports the alternative; the lease
decides.** A single hard-coded behaviour — gross abatement, period-only percentage rent, billed-basis
revenue — is what made each of these a blocking question in the first place. Making each one a
recorded lease term is what makes it stop being one.

### Sources

- [EAS 49 / IFRS 16 in Egypt — Andersen](https://eg.andersen.com/financial-leasing-in-egypt/) · [Mondaq](https://www.mondaq.com/accounting-standards/1593126/financial-leasing-in-egypt-ifrs-16-and-accounting-standards) — EAS 49 issued 2020, aligned with IFRS 16, effective from Jan 2021; lessor operating-lease income recognised straight-line
- [Rent abatement clauses & CAM impact — CAMAudit](https://www.camaudit.io/resources/lease-language/rent-abatement-clause) · [Austin Tenant Advisors](https://www.austintenantadvisors.com/blog/what-is-rent-abatement-in-commercial-leases-how-to-get-it/) — net vs gross abatement; CAM continues unless explicitly abated
- [Percentage rent guide — Nakisa](https://nakisa.com/resources/guide-to-percentage-rent-leases-in-retail/) · [REA](https://rea.co/percentage-rent-calculations-commercial-landlords-step-breakdown/) — monthly/quarterly on account, cumulative YTD annual reconciliation; seasonal breakpoint structuring
- [Yardi Commercial Suite brochure](https://resources.yardi.com/documents/commercial-suite-brochure/) — straight-line rent, IFRS

---

## 2. Fix these two now, outside the phases

Both are live defects found while writing this benchmark. Neither depends on anything else.

| | What | Why now |
|---|---|---|
| **MF-09** 🔴 | `CamReconciliationService` allocates on the **master unit's** area only, on both the numerator and the denominator. Every multi-unit lease is under-charged CAM and every single-unit tenant absorbs the shortfall. | It is mis-billing real tenants today. The pool still ties out, which is why no test caught it — **assert the share, not the total** |
| **MF-01** 🔴 | The bulk billing run **never prorates**. `prorate` defaults to `false`; only the manual single-lease action passes `true`. A mid-month commencement is billed a full month unless a human intervenes. | It over-bills exactly the leases nobody is watching |

Each is a `/safe-change`: read the module doc → change the service → regression test in
`tests/Feature/Regression/` → update the module doc in the same commit.

---

## 3. The phases

Sequenced so that each one is shippable on its own and each unlocks the next. Effort is
**relative**, not calendar — sizing is Khaled's call.

---

### Phase 1 — The charge schedule ♻️ *(the foundation — everything else waits on this)*

**Stories:** LS-01, LS-02, LS-03, LS-06 · then LS-04, LS-05 if Q2 says so.

**What changes**

- `charges` gains a schedule discipline: **many rows per type**, contiguous and non-overlapping,
  each with `from`/`to`, plus provenance (`origin`: manual / escalation / amendment / migration).
- `LeaseCreationService` writes the **full term** from the escalation terms, not one row.
- `LeaseRentChangeService` **closes the current row and opens the next** at an effective date.
  It stops writing `Lease.base_rent_monthly` as the truth; that column becomes a derived
  *display* of "the rent effective today".
- `RentEscalationService` keeps its sweep, its lock and its idempotency — but **appends** the next
  row instead of overwriting. (Longer term, escalation rows can be generated at lease creation and
  the sweep retires; do that as a follow-up, not in the same change.)
- **Migration:** every existing active charge becomes one open-ended row. Behaviour on deploy night
  is byte-identical — that is the acceptance test.

**What must not break**
- `MonthlyBillingService`'s idempotency (period-overlap guard), the run lock, the cycle anchoring
  for quarterly/annual leases, the commencement proration arithmetic.
- `Invoice::recomputeTotals()` and everything under it. This phase does not touch AR.
- The GL registry, property isolation, the deletion policy, the search blobs.

**Definition of done**
- A before/after test that bills the same fixture month pre- and post-migration and asserts
  identical invoices.
- A test that bills two adjacent months either side of a step and gets two amounts, with nothing
  mutated in between.
- A test that a lease with an overlapping or gapped schedule is **refused** at write time.
- `docs/modules/04-leases.md` and `docs/money/01-billing-monthly.md` updated **in the same commit**.

**Risk:** the highest-risk change in this cycle, because it is under live billing. Mitigate with
the byte-identical migration test and by shipping the *read* tolerance (many rows) before the
*write* change (produce many rows).

---

### Phase 2 — Lease events & amendments ♻️

**Stories:** LE-01, LE-02, LE-03, LE-04.

Append-only `lease_events`: type · effective date · reason · actor · document reference · the
schedule rows opened and closed. Every commercial change routes through it — a rent change that
does not produce an event should be impossible, not merely discouraged.

`lease_unit` gains effective dates so an expansion or contraction has a date. Holdover becomes a
billable conversion rather than an alert.

**Renewal stays as it is** — a new lease chained by `previous_lease_id`. The gap was never renewal.

**Definition of done:** the lease view shows a timeline; a terminated lease's history is
reconstructible at any past date; [S5](04-scenarios.md#s5--mid-term-expansion) and
[S6](04-scenarios.md#s6--negotiated-mid-term-relief) both pass as scenario tests.

---

### Phase 3 — Options & critical dates ➕ *(cheapest high value — consider shipping before phase 2)*

**Stories:** OP-01, OP-02, OP-03, OP-04 · plus tenant COI expiry (reuse the `VendorDocument` pattern).

`lease_options` + a daily scan on the same idempotent lock-and-stamp pattern as
`leases:remind-expiring`, alerting before the **earliest** notice date. A dashboard card for
"options requiring action in 90 days". Encumbrance warnings on the unit picker.

This is a `/new-module`-shaped piece of work that reuses machinery that already exists — the
notification spine, the scan pattern, the property-scoped resource pattern. **It has the best
value-to-risk ratio in the whole cycle**, and it is the only phase that could reasonably jump the
queue if the leasing team is feeling the pain now
([S7](04-scenarios.md#s7--renewal-option-with-a-notice-window)).

---

### Phase 4 — Money-flow completion ➕

**Stories:** MF-02 (trailing proration), MF-03 (move-out final account), MF-04 (bad-debt write-off),
MF-08 (per-lease late-fee terms). MF-01 and MF-09 shipped in §2.

**MF-04 is the one with teeth.** A write-off is a new GL source, so it takes the full registry
route: one `LedgerPoster::JOURNALIZERS` line, its `LedgerRealtimeSync::SOURCE_DATE_COLUMNS` entry,
a `PostingDateGuards` classification, and — per the GL invariant — **at least one test that drives
the real service and the sweep and asserts the tie-out**, not a test that calls `LedgerPoster::post()`
and proves only arithmetic.

**MF-05 (post month) is deliberately parked here as optional.** It is the right answer to a real
problem ([S14](04-scenarios.md#s14--an-invoice-keyed-after-the-period-closed)), but it touches every
journalizer and every GL report. Do it **only** if a real close has actually been blocked by it. If
it happens twice, do it; if it has never happened, the closed-period guard plus a controlled reopen
is a reasonable interim.

---

### Phase 5 — Revenue recognition ➕ *(build it; ships switched off)*

**Stories:** RA-02.

A per-lease straight-line schedule derived from the phase-1 charge schedule (steps + abatements),
posting the monthly difference to deferred rent as a **registered GL source**. Amendments recalculate
**forward only** — never a retrospective restatement of a closed period. Prove that invoices, VAT
and ETA output are bit-identical before and after.

**Ships behind a setting, defaulted OFF** (Q1). Enabling it is the accountant's call, made against
a before/after they can read; record the ruling in `docs/BUSINESS-RULES.md` either way so it is not
re-litigated every audit.

---

### Phase 6 — Recoveries & percentage-rent depth ➕

**Recoveries:** RC-01 (pool from the GL) → RC-05 (re-estimate) → RC-06 (tenant statement) →
RC-02/RC-03 (multi-pool, denominator) → RC-04/RC-07 (gross-up, controllable caps) as needed.

RC-01 and RC-05 together close the loop that is currently open: the estimate *billed* and the
estimate *reconciled* stop being two numbers a human keeps equal. RC-05 lands naturally once phase 1
exists, because a re-estimate is just a new schedule row effective next January.

**Percentage rent:** PR-01 (cumulative basis + annual settle-up) is the money item and should ship
first — it is the deferred annual reconciliation module 09 already flagged. Then PR-02 (tiers),
PR-04 (estimated sales), PR-03 (deductions).

**Do not re-open** the CAM true-up settlement asymmetry (bill immediately / credit auto-applied
FIFO), the cap ceiling resolution, the admin-fee-on-capped-cost rule, or the frozen-share re-run
guard. All four are hard-won and correct.

---

### Phase 8 — UI/UX ➕ *(runs alongside, not after)*

**Stories:** UX-01…UX-13, in [08-yardi-ui-ux.md](08-yardi-ui-ux.md).

The operator asked for "the same Yardi UI/UX". The honest answer is **half yes**: copy Yardi's
*information architecture* — the record hub, drill-down on every number, dashboard-centric landing,
batch-review-before-post — and **not** its interface, which 77% of its own usability reviews call
unintuitive and which Voyager 8 is itself replacing. Atriom's Filament 4 shell, with EN/AR + RTL, a
tenant portal and a mobile API, is already the better surface.

**UX-13 is a standing standard, not a one-off:** a resource form covering more than ~3 concerns is
built as tabs via `App\Support\FormTab` (operator directive 2026-08-08). The helper is mandatory
because Filament v4 ships no validation-error indicator on `Tabs`, and a tabbed form where the error
is on a panel you cannot see is worse than the scroll it replaced. `LeaseForm` is the reference
implementation; seven more forms are queued in UX-13's table.

**Three ship now, before phase 1, because the data already exists:** **UX-05** billing-run preview
(the control that stops a bad escalation reaching 400 tenants — the service already returns every
skip reason it needs), **UX-03** AR/collections dashboard (an upgrade to the existing `ArAging`
page), **UX-04** month-end close dashboard (assembly over `AccountingPeriod`, the close PDF and the
books tie-out). The rest attach to the phase that produces their data — UX-01/02 to phase 1, UX-09
to phase 3, UX-06 to phase 7, UX-08 to phase 6.

---

### Phase 7 — The reports that make it visible ➕

**Stories:** RR-01 (rent roll), RR-02 (expiration schedule), RR-04 (occupancy cost %), then RR-03,
RR-05.

Once the schedule exists, the rent roll is close to a view over it. **RR-04 (occupancy cost %) needs
no new data at all** — invoices and `TenantSalesDeclaration` already hold every input — and it is the
number that tells a mall GM which tenant is about to fail. It is the best value-per-line-of-code
item in this entire benchmark and could be shipped at any point.

Reuse `ReportCsv`/`Exporter` from module 17; EN + AR keys in the same change; native Filament, no
Blade view pages.

---

## 4. Suggested order

```
NOW      MF-09  CAM area bug              ← mis-billing today
         MF-01  bulk-run proration        ← over-billing today
         (Q1–Q3 resolved to the standard — see §1; no longer blocking)

CYCLE    UX-05/03/04  billing preview + AR dashboard + month-end close   ← data already exists
         Phase 1   charge schedule        ← the foundation; everything waits here
         Phase 3   options & critical dates   (may jump ahead of 2 — lowest risk, high value)
         Phase 2   lease events & amendments
         Phase 4   money-flow completion  (write-off, move-out, trailing proration)
         Phase 7a  rent roll + occupancy cost %
         Phase 8   the UI stories that attach to each phase above

NEXT     Phase 5   straight-line rent     (build; ships off, accountant enables)
         Phase 6   recoveries + % rent depth
         Phase 7b  the rest of the reports
```

**If only one phase ships, ship phase 1.** **If only one week is available, ship §2 plus RR-04.**

---

## 5. How each phase gets done

Every phase follows [`/safe-change`](../../../.claude/skills/safe-change/SKILL.md):

1. Read the module doc first — `docs/modules/04-leases.md`, `05-billing-invoices.md`,
   `08-cam.md`, `09-tenant-sales-percentage-rent.md`, `21-general-ledger.md`.
2. Business logic in a **single-action service**; controllers and Filament pages stay thin.
3. Honour the invariants in `CLAUDE.md` — money-record deletion, property isolation
   (`assertAssetInScope` on create *and* edit), the GL registry, posting-date guards, VAT from
   `Vat::standardRate()`, `search_text` blobs, `->authorize()` on every write action.
4. A regression test per bug in `tests/Feature/Regression/`; scenario tests in
   `tests/Feature/Scenarios/`. `vendor/bin/pest --parallel` green **before every push** — CI
   auto-runs are off, so a red push is silent.
5. Update the module doc **in the same commit**. Never hand-type a registry or a count — run
   `atriom:dump-registries` / `atriom:dump-system-census`.
6. New searchable model → `SearchPolicy` + its own migration + `atriom:rebuild-search`.

**Two testing traps this cycle will walk into:**
- A **tie-out assertion cannot see a distribution error** — that is exactly why MF-09 survived. When
  testing an allocation, assert the *share*, not the total.
- A **GL test that calls `LedgerPoster::post()` directly proves only arithmetic.** Every new money
  source needs one test that drives the real service *and* the sweep.

---

## 6. What this cycle explicitly does not do

Recorded so it stops being re-proposed — the reasoning is in
[05 §Deliberately not doing](05-user-stories.md#deliberately-not-doing--recorded-so-they-stop-being-re-proposed).

- ❌ Charge-level AR (ETA makes the invoice the legal document; MF-06/07 buy back the benefit)
- ❌ Multiple accounting books
- ❌ Multi-currency
- ❌ Deal Manager / leasing pipeline — revisit when mall #2 is leased from scratch
- ❌ AI lease abstraction — nothing to abstract *into* until LS-01 and OP-01 exist
- ❌ Bank deposit batches
- ❌ TI allowance & commission amortisation
- ❌ **Any rebuild of the invoice / payment / credit-note / GL core**

---

## 7. What "done" looks like

Every **delta** in [04-scenarios.md](04-scenarios.md) reads *none*, and:

- A leasing manager can see 2031's rent on a lease signed today, and knows in April 2030 that a
  renewal option opens.
- A mid-term change is a dated event with a reason, and a six-month discount is distinguishable
  from a permanent rent cut.
- A tenant who moves out on the 18th is billed to the 18th and receives one final account.
- An uncollectible debt becomes bad-debt expense, not reversed revenue.
- A CAM charge drills through to the vendor invoices behind it, and the tenant gets a statement
  showing the working.
- A seasonal tenant is billed the percentage rent their lease actually says.
- The owner opens a rent roll.
- And **`Invoice::recomputeTotals()` is exactly the function it is today.**
