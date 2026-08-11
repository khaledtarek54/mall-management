# Validation sweep — spacing · leasing · receivables · payables

**Status: COMPLETE, 2026-08-11.** All four areas swept — receivables (§8), leasing (§9), spacing
(§10), payables (§11). 34 rules traced to every writer and exploited one at a time.

**The tally, since the honest version is the point:** 12 rules were enforced at layer 3 only or not
at all and are now at layer 1; 4 were already correct but had never been watched refusing anything
and now have witnesses; the remaining 18 needed no change, which §5 predicted and which is the
result rather than a failure to find one. Two findings were recorded and deliberately NOT fixed —
`charge_codes.vat_exempt` (§8 R8) and freeing `charges.type` from its DB enum (§9 L7) — as product
decisions belonging to the accountant conversation. **The first of those shipped 2026-08-11** as
`charge_codes.vat_treatment`: the column was a system decision all along, and only the rulings in it
are the accountant's (see §8 R8). One open question goes to Eltizam (§11, ad-hoc bills outside the
approval ladder).

**Two patterns worth carrying past this document:**

1. **Whenever a record stores a number someone ELSE issued, ask what stops it arriving twice.** The
   PDC cheque number and the vendor invoice reference were the same gap in two modules, missed for
   the same reason: the system's own generated number sat right beside the field and made it look
   handled.
2. **Mutation-test every guard you add** — delete it, watch the test go red, restore it. §10 records
   a finding that was *correct* while the tests proving it were worthless, and only this caught it.

The goal is not "add more validation". It is to know, for every rule that matters in these four
areas, **where it is enforced and whether anything proves it** — and to move the ones that are only
enforced in a form, because a form is the one layer every other writer bypasses.

---

## 1. The four layers, and what belongs at each

A rule enforced at the wrong layer looks correct and is not. This is the ladder, strongest first:

| Layer | Enforces | Use when | Bypassed by |
|---|---|---|---|
| **0 — DB constraint** | unconditional uniqueness, NOT NULL, FK | the rule is absolute and stateless | nothing |
| **1 — Model hook** | invariants true for *every* writer | any write must obey it, from any path | nothing in-process |
| **2 — Service** | rules needing a lock or a transaction | check-then-act, or several rows must move together | a direct model write |
| **3 — Form** | inline errors, filtered pickers | making the rule *visible* before submit | the API, console, imports, any service |

**The rule of thumb this codebase already follows:** a business rule lives at layer 2 and is
*mirrored* at layer 3 for UX. `Unit::isActivelyLeased()` is the exemplar — one predicate, called by
`LeaseCreationService` under `lockForUpdate` (authoritative) and by `LeaseForm` (inline error). The
form is never the only guard.

**The trap to hunt for:** a rule that exists ONLY at layer 3. It passes every manual test, and the
mobile API or an import walks straight through it. Module 03's close-out found exactly this twice —
a portal form scoped its `<Select>` options but never clamped the payload.

---

## 2. What the sweep produces

1. **A rule matrix** per area: rule → layer(s) it is enforced at → the test that proves it.
2. **A test for every rule that has none**, written as an *exploit first*: make the bad write
   succeed, then add the guard and watch it fail. A guard nobody has seen fail is a guard nobody
   knows works.
3. **A promotion list**: rules currently at layer 3 only, moved to layer 1 or 2.
4. **A conformance gate** where a class of rule can be checked mechanically (the way
   `UniqueRuleScopeConformanceTest` already checks that every Filament `unique()` is property-scoped).

---

## 3. Where to look, by area

Not a guess-list — these are the rules the modules already claim, to be verified one at a time.

### Spacing (properties, units, floors, rentable items, areas)
- Unit code unique **per property**, not globally (DB: `['asset_id','code']` — 7 tables use this shape).
- A unit cannot be under two active leases — layer 2 + 3 today, **no DB constraint is possible**
  (it is conditional on status and dates), so the lock is the guard. Verify the lock, not the check.
- Floor level and code unique per property; a unit's floor must belong to the unit's property.
- A rentable item cannot be held by two leases on one day (DB: `['lease_id','rentable_item_id','effective_from']`
  — note this does NOT prevent overlapping *ranges*, only identical starts; verify the service).
- Area (m²) must be positive; a remeasurement must not overlap the row it closes.
- **Cross-property:** every `asset_id` a form can set must pass `assertAssetInScope`.

### Leasing (leases, charges, options, events)
- Commencement before expiry; rent-commencement not before commencement.
- Charge schedule rows of one type must not overlap (the overlap guard) and should not gap.
- Escalation collar: floor ≤ ceiling (layer 1 today).
- A terminal lease is immutable (layer 1).
- Percentage-rent tiers must not overlap; breakpoint positive.
- Deposit amount non-negative; option notice window ordered.
- **Reachability check:** does each of these refuse an API/import write, or only a form submit?

### Receivables (invoices, payments, credit notes, deposits, PDCs)
- An invoice's items must sum to its subtotal; VAT per line consistent with the charge type.
- No settlement channel may over-settle — **already proven to compose** (`FourSettlementChannelsComposeTest`).
- A payment cannot allocate to another tenant's invoice, or to one in another property.
- A credit note cannot exceed the invoice it corrects.
- Posting date must be in an open period (registry: `PostingDateGuards`).
- A PDC cannot clear against a cancelled invoice; cheque number unique per bank per tenant?  ← verify
- **Money records are never deletable** (registry: `DeletionPolicy`) — already gated.

### Payables (vendor bills, payments, expenses, purchase requests)
- A bill cannot be paid more than its net of withholding tax.
- A bill's property must match its purchase request's property (fixed in module 29 — verify it holds).
- Vendor invoice number unique per vendor  ← verify whether this exists at all.
- Approval bands enforced server-side, not only in the form (registry: `ApprovalRule`).
- Posting date guard on **both** the approve and the pay path (this was the module 12 finding).

---

## 4. Method, per rule

1. **State the rule in one sentence**, as an operator would.
2. **Find every writer** — form, service, API resource, console command, importer, seeder.
3. **Exploit it**: write the test that makes the bad state, using a *reachable* input (a fixture that
   sets a column no form writes proves nothing — see `feedback_tests_must_use_reachable_inputs`).
4. If it succeeds, **fix at the right layer** and watch the test flip.
5. If it fails, **keep the test** — the guard now has a witness.
6. **Pair every refusal with a control** that must succeed, or the test passes when the whole path is
   a no-op.

---

## 5. Sequencing

Receivables and payables first: a wrong number there is money, and money is the thing that cannot be
quietly corrected later. Then leasing (it generates the money), then spacing (it is mostly
uniqueness, and 81 DB constraints already carry a lot of it).

Estimate: one focused session per area. This is not a refactor — most of the work is reading and
writing tests, and the expected outcome is that **most rules are already enforced correctly**, with a
handful sitting at layer 3 that need promoting. Say so honestly when that is what is found, rather
than manufacturing findings to justify the sweep.

---

## 6. Resource vs relation manager — the decision rule

A child record shown in two places is two places to keep right. Where a standalone resource earns
nothing, **delete it and rely on the relation manager**; a screen fewer is a screen that cannot drift.

**Remove the standalone resource when ALL FOUR hold:**

1. every record is reached through exactly one parent;
2. the parent already surfaces it in a relation manager;
3. nobody asks the **cross-parent** question ("show me all of these, across every parent") — that is
   what a register is *for*, and a relation manager cannot answer it;
4. it has no identity an operator searches for (no reference, number or name they would type).

Fail any one and it stays. Deleting a register an operator uses as a worklist is not consolidation,
it is removing the only screen that answers their question.

### The decisions, taken 2026-08-11

Measured rather than guessed: no resource is currently BOTH index-only and already surfaced by a
relation manager. Three are index-only, and each was judged against the rule:

| Resource | Decision | Why |
|---|---|---|
| **Stock movements** | **KEEP** | Fails (3). Both parents now show it, but stock reconciliation is a cross-warehouse question — "everything that moved this month" has no parent to sit under |
| **Disbursements** | **KEEP** | Fails (3). It is the owner-payment worklist: "who are we due to pay" spans statements, and forcing that through statement → run would make paying owners harder, not simpler |
| **Accounting periods** | **KEEP** | Fails (1). Its parent is `FiscalYear`, which has no resource — and closing a period is a first-class operator task, not a detail of something else |

**So: nothing is removed today, and that is the finding rather than a failure to find one.** What
this section buys is the *rule* — the next child resource gets judged by it before it is written,
and the four already correctly nested (CAM allocations, custody transactions, vendor-bill payments,
marketing spends) stay nested.

**Apply the rule in reverse too:** a child NOT surfaced on its parent should get a relation manager,
whether or not its register stays. That half of the sweep is done — lease → rentable items /
deposits / sales declarations, unit → leases / claims / meters, tenant → invoices, property →
rentable items, run → owner statements, warehouse & item → stock movements.

---

## 7. Stale and unfinished work

Checked 2026-08-11, before planning any removal — the point being to find what is half-built, not to
assume it exists.

| Check | Result |
|---|---|
| `TODO` / `FIXME` / `HACK` / `WIP` markers in `app/` | **none** (one hit is a phone-format example, `XXX-XXX-XXX`) |
| "not implemented" / "placeholder" / "stub" | **none real** — one is the deliberate ETA mock, one a comment about *avoiding* a stub, one noting a stub was closed |
| Services never reached from any UI, HTTP, console or job path | ~~**none** of 135~~ — **WRONG, corrected 2026-08-11 (§10).** `RemeasureUnitService` was unreachable on the day this was written: no action, no controller, no command, only tests. The spacing sweep found it and added the missing action. Treat a census that answers "none" as a claim to hand-check, not a result |
| Dead columns across the four areas | **none** across 25 tables |
| API resource fields that no longer resolve | **none** — now gated by `ApiResourceFieldConformanceTest` |
| Orphan admin pages | **none** — the manifest gate would fail |

**Two things are deliberately incomplete, and are decisions rather than debt:**

- **ETA e-invoicing** ships against a stubbed response. On hold by the operator's instruction; do not
  touch it or list it as a next step.
- **Straight-line rent** is built and ships **OFF**, waiting on the accountant's ruling. Turning it on
  is a settings change, not a build.

Both are recorded where someone will find them. Anything else discovered during the per-area sweeps
should be added here with the same distinction: *unfinished* (fix or delete it) versus *deliberately
off* (say who is waiting on what).


---

## 8. Receivables — the rule matrix (swept 2026-08-11)

Nine rules, one at a time, each traced to every writer and then exploited. **Three were enforced at
layer 3 only or not at all** — those are now at layer 1 with a test that was watched failing first.
The other six were already correct; two of them had no test, and now do.

| # | Rule | Was | Now | Proven by |
|---|---|---|---|---|
| R1 | An invoice's items sum to its header (subtotal / VAT / total) | **3 only** | **1** | `InvoiceHeaderTiesToItemsTest` |
| R2 | No settlement channel may over-settle | 1+2 | unchanged | `FourSettlementChannelsComposeTest` |
| R3a | A payment cannot allocate to another tenant's invoice | 1 (called by every writer) | unchanged | `PaymentAllocationGuardsTest`, `PaymentScenarioTest` |
| R3b | …nor to one in another property | 2+3 | unchanged | `PropertyIsolationEndToEndTest` |
| R4 | A credit note cannot exceed the invoice it corrects | 2 (locked, capped, fail-closed both ways) | unchanged | `CreditNoteServiceTest` |
| R5 | A posting date must be in an open period | 2 + registry gate | unchanged | `PostingDateGuardConformanceTest` |
| R6a | A PDC cannot settle a cancelled invoice | 2 (correct, **untested**) | 2 + witness | `PostDatedChequeTest` (new) |
| R6b | A cheque number is unique per tenant + bank | **nothing, any layer** | **1** | `ChequeNumberIsUniquePerTenantBankTest` |
| R7 | Money records are never deletable | registry gate | unchanged | `DeletionPolicyConformanceTest` |
| R8 | VAT per line agrees with the charge type | 3, and **drifted** | 3, named once | `ExemptChargeTypesAgreeAcrossPathsTest` |

### R1 — the invoice header was a client-supplied number

`InvoiceForm` recomputes the header in `afterStateUpdated` and renders `subtotal` / `vat_amount` /
`total` as `readOnly()`. `readOnly` is an HTML attribute; the fields are `dehydrated()`, Livewire
accepts whatever arrives for those keys, and **nothing re-derived them server-side**. A tampered
payload persisted a header of `1` against 12,280 of line items, straight through the real Create
page as an ordinary `manager`. A direct item write (API, import, console, a service that forgot)
moved the lines without moving the header at all.

It is money, not cosmetics: `InvoiceJournalizer` debits AR with the **header** total and credits
revenue from the **item** amounts split by charge type, so a divergence computes the two sides of
one journal entry from two different numbers — and `recomputeTotals()` derives `balance` from the
same header, so it is also what the tenant is chased for.

Now `Invoice::syncTotalsFromItems()`, fired from `InvoiceItem::saved`/`deleted` and from
`Invoice::saving` when an existing invoice's header is written. An invoice with **no** items is
left alone — a legacy / opening-balance row has nothing to derive from and zeroing it would erase
real AR. `CamReconciliationService` carried a private `rebuildInvoiceHeader()` doing exactly this
for one path; it is deleted, which is the point.

**It also surfaced a real defect in `DemoSeeder`.** Two sites hand-wrote `paid_amount` / `balance` /
`status` on an invoice — the thing `CLAUDE.md` forbids — and got away with it only because nothing
recomputed before the payment was attached. Once the item hook ran a legitimate `recomputeTotals()`
in between, those invoices read as unpaid, and a later seeder paid them a **second** time: eight
invoices allocated up to 2× their total. Both sites now attach and then recompute. The demo data
had been wrong-but-invisible in exactly the way the sweep exists to find.

### R6b — the same cheque could be lodged twice

`cheque_number` had **no uniqueness at any layer**: no DB constraint, no model guard, not even a
form rule. Two rows for one piece of paper are each independently clearable, and each clear records
a captured Payment — the second settles AR that no money backs, or mints an on-account credit the
tenant never funded. `lodgeSeries()` makes it easy to hit by accident: re-run over the same cheque
book it regenerates the identical sequential numbers *by design*.

The key is (tenant, bank, number), excluding **cancelled** cheques so a mis-key can be cancelled and
re-lodged — that carve-out is why it is a model guard and not a unique index. The create/edit pages
mirror it as a toast over a still-filled form, calling the same predicate; deliberately **not** a
Filament `unique()` rule, which keyed on the client-supplied `tenant_id` would be the existence
oracle `UniqueRuleScopeConformanceTest` exists to stop.

**Deviation from Yardi, stated:** Yardi *warns* on a duplicate cheque number and lets the operator
proceed. We refuse. A PDC register that double-counts is a cash forecast wrong in the operator's
favour, and cancel-then-re-lodge costs one click.

### R8 — the exempt set had drifted, and is now named once

`App\Support\Vat`'s docblock named five out-of-scope supplies and every service that raises one
originates it at 0 — but the invoice form's type-switch carried its own list of **two**. So a Late
Fee, Marketing Levy, Violation Fine or Returned-Cheque Fee added *by hand* defaulted to 14% VAT that
no service would ever have charged: the same charge taxed differently depending on whether a person
or a service raised it, over-charging the tenant and over-stating VAT payable on the return. (The
first repeater row was self-contradictory on sight — type `Base Rent`, VAT `14%`.)

The set is now `Vat::EXEMPT_TYPES` with `Vat::rateForType()`, read by both sides.

**SHIPPED 2026-08-11 — taxability moved from PHP into the catalogue.** The recommendation this
section left open (`vat_exempt` on `charge_codes`) is built, in the shape a VAT jurisdiction needs:
`charge_codes.vat_treatment` — `standard` / `exempt` / `zero_rated` — plus an optional
`vat_rate_override` for a supply on a schedule rate of its own. `Vat::rateForType()` resolves the
catalogue first, and **every origination point now goes through it**: the invoice form and all
twelve services that raise a charge or an invoice line. A half-wired catalogue would have been worse
than none — the accountant flips `marketing` to standard-rated, the screen agrees, and the monthly
levy keeps billing 0.

It was NOT blocked on the accountant after all, and treating it as blocked was the error: the
*column* is a system decision, only the *rulings in it* are theirs. Everything is seeded to what the
system billed the day before, so their answer is now a row edit rather than a release.

`Vat::EXEMPT_TYPES` survives as the floor for an unseeded catalogue (an empty `charge_codes` must
not make base rent taxable), with `ChargeCodeVatTreatmentConformanceTest` asserting floor and
catalogue resolve every code identically — the arrangement `InvoiceJournalizer::REVENUE_ROLE`
already had for posting roles. `TaxSettings::parking_vat_applicable`, added the day before as a
one-supply toggle, is retired into the same column.

**Yardi comparison, since this was the reference:** Voyager holds taxability as a `Tax` flag on the
charge code (*"Yes means 'this charge is taxable.' It does not mean 'this charge is a tax.'"*) with
the rate configured as data at property level, and posts the tax to a liability account. We match
the placement, add the exempt/zero-rated split a VAT return needs, and stay stricter in one respect:
Yardi's property `Tax Rate` is a current-rate field, while every Atriom document freezes the rate it
was billed at.

### What was already right

R2–R5 and R7 needed no change, and saying so is the finding. `CreditNoteService` in particular is
the shape the rest should copy: locked on both rows, re-read under the lock, capped at
`min(available, owed)`, and fail-closed on cross-tenant *and* cross-property with the picker filter
treated as UX rather than the gate. R3a is a model method — but it earns layer 1 only because
**every** writer calls it or cannot reach the state: the two gateway paths derive `tenant_id` from
the invoice itself, so a cross-tenant allocation is unrepresentable there rather than merely guarded.

---

## 9. Leasing — the rule matrix (swept 2026-08-11)

Ten rules. **Four were at layer 3 only**; two more were at layer 1 and correct but had never been
watched refusing anything. The leasing core came out better than receivables — the overlap guards,
the escalation collar and terminal immutability were already model hooks with the reasoning written
down beside them.

| # | Rule | Was | Now | Proven by |
|---|---|---|---|---|
| L1a | A lease cannot end before it starts | **3 only**, and the terminate action had **nothing** | **1** | `LeaseExpiryNeverPrecedesCommencementTest` |
| L1b | Rent-commencement not before commencement | 3 + a layer-1 clamp on the *consequence* | unchanged | `firstBillableMonth()`, existing billing tests |
| L2 | Charge-schedule rows of one type must not overlap | 1, **untested** | 1 + witness | `LeasingGuardWitnessesTest` |
| L3 | Escalation collar: floor ≤ ceiling | 1 | unchanged | `EscalationCollarTest` |
| L4 | A terminal lease is immutable | 1, **untested** | 1 + witness | `LeasingGuardWitnessesTest` |
| L5a | Percentage-rent tiers must not overlap | 1 | unchanged | `PercentageRentTiersAndDeductionsTest` |
| L5b | Breakpoint ≥ 0, rate within 0–100% | **3 only** | **1** | `PercentageRentTiersAndDeductionsTest` |
| L6a | Security deposit non-negative | **3 only** | **1** | `LeaseDepositNonNegativeTest` |
| L6b | Option notice window ordered | **3 only** | **1** | `LeaseOptionWindowTest` |
| L7 | Charge type is validated at the DB, not the app | 0 only | unchanged — **see below** | — |

### L1a — the terminate action could end a lease before it began

`expiry_date` has three writers: the lease form, `LeaseTerminationService` (which stamps the
termination date straight onto it) and `LeaseRenewalService`. The rule lived on exactly one of them,
as an `->after()` on the form's DatePicker. **The terminate action's DatePicker carried no
constraint at all** — no `minDate`, no rule, nothing — so a mis-keyed year produced:

- a lease that reads expired while its status is active, and that `activeInPeriod()` can never
  match, so it **bills nothing, ever again**;
- recurring charges stamped `end_date` before their own `start_date` — the shape
  `atriom:audit-charge-schedules` exists to catch on import, minted in-app;
- a move-out statement and final account frozen around a date the lease never reached.

Guarded at the model on **both** columns: fixing only the expiry side leaves the identical broken
state reachable by moving commencement forward instead. **Equal is allowed** — a deal that collapses
at handover terminates on its commencement date — so layer 1 carries the invariant every writer must
obey while the form keeps its stricter `->after()` for new leases, where a zero-day term is nonsense.

### L5b — a negative percentage-rent rate is a credit wearing a charge's clothes

The ladder's overlap and inversion rules were already at the model; its **bounds** were not. The
relation manager carried `minValue(0)` on the breakpoints and `minValue(0)->maxValue(100)` on the
rate, with nothing behind it. The rate is the one that matters: a negative rate raises a
percentage-rent charge that is really a credit, through the same immediate-invoice path as a real
overage, so the tenant is credited by a document that says *charge*.

*A note on how this was found, because it nearly wasn't:* the first cut of these tests passed on the
overlap guard rather than the bound — appending a band above 900,000 collided with the ladder's
unbounded top tier, so every create threw and every refusal "passed". The control (the same append
differing only in the bound under test) is what exposed it. This is the §4 rule earning its place.

### L6b — an option whose window closes before it opens can never be exercised

`LeaseOption` had no `booted()` at all. `windowIsOpen()` wants today ≥ earliest and
`windowHasClosed()` wants today > latest, so an inverted pair is simultaneously never-open and
already-closed: `leases:scan-option-windows` would announce the option **lapsed** having never once
announced it open. The alerting built to protect the right would be the thing that buried it.

### L7 — `charges.type` is still a DB enum, and that quietly caps the charge catalogue

**A finding, deliberately not fixed here.** `invoice_items.type` was converted to `string(32)` in
June 2026, which is what lets `charge_codes` — the catalogue an accountant maintains without a
deploy — drive invoice lines. **`charges.type` was never converted**: it is still
`enum('base_rent','service_charge','utility','parking','percentage_rent','other','marketing')`.

So a code the accountant adds can be billed as a one-off invoice line but **cannot be set up as a
recurring lease charge** — the DB rejects it. The catalogue's "no deploy needed" promise stops at
recurring billing, which is most of the money. It also stands against the project's own standing
rule (*no DB-level enums; string + app-layer validation, so the set can change without a migration*).

Not fixed in the sweep because it is not a validation-layer question: freeing the column is one
migration mirroring the June one, but delivering the capability also means wiring the catalogue into
the charge form and adding the app-layer validation the column currently provides. That is a feature
with a product decision inside it, and it belongs in the same conversation as R8's `vat_exempt`
column — both are the accountant's call about what the catalogue owns.

### A gate, not just a fix

`TestHelperUniquenessConformanceTest` — no two test files may declare the same file-scope function.
PHP has one function table, so a collision is a **fatal** on any single-process run, and
`pest --parallel` hides it by giving each worker only its own files. When it fires the run produces
**no output at all** and exits 255: no test name, no file, no message, and the obvious next move
("run just that directory") is itself a full load that fails identically.

It has now cost this project twice — `makeViolation()` during the inventory pass, `optionOn()`
during this sweep. The gate names both files and the helper. It was verified by planting a real
duplicate and watching it go red, then removing it.

---

## 10. Spacing — the rule matrix (swept 2026-08-11)

The area expected to have the least to find — "mostly uniqueness, and 81 DB constraints already
carry a lot of it" (§5). The uniqueness half was exactly that. The other half held the sweep's
single worst finding, and a correction that matters more than the finding.

| # | Rule | Was | Now | Proven by |
|---|---|---|---|---|
| S1 | Unit code unique per property | **0** (`unique(['asset_id','code')`) | unchanged | the constraint |
| S2 | A unit cannot be under two active leases | 2 (lock) + 3 | unchanged | `LeaseDoubleBookingTest` — which asserts the SOURCE contains `lockForUpdate`, because sqlite makes the lock a no-op |
| S3a | Floor code and level unique per property | **0** | unchanged | the constraint |
| S3b | A unit's floor (and zone) belong to its property | **3 only** for non-form writers | **1** | `UnitFloorStaysInItsPropertyTest` |
| S4 | A rentable item cannot be held by two leases at once | 2 (locked, and the DB key is only a backstop) | unchanged | `RentableItemAssignmentTest` |
| S5a | Area must be positive | 3 + service | **1** | `UnitAreaHasOneTruthTest` |
| S5b | A remeasurement must not overlap the row it closes | 2 (locked) | unchanged | `UnitRemeasurementTest` |
| S5c | `units.area_sqm` is derived from the dated rows | **nothing, and no UI to do it properly** | **1 + the missing action** | `UnitAreaHasOneTruthTest` |
| S6 | Every form-settable `asset_id` passes `assertAssetInScope` | 3 + registry gate | unchanged | `PropertyIsolationConformanceTest` |

### S5c — the feature had no way to be used, and the only usable path was the broken one

Area versioning shipped so that remeasuring a shop stops rewriting what was already billed:
`Unit::areaOn()` answers from the row in force on a date, and `RemeasureUnitService` closes one row
and opens the next under a lock. Its docblock states the invariant plainly — *"this service is the
only thing that may move it"*.

Two things were true and neither was visible:

1. **Nothing enforced it.** `UnitForm` kept a plain editable `area_sqm` on Edit, and the opening
   `unit_areas` row is written by a `created` hook that does not fire on update. So an ordinary edit
   moved the column and wrote **no dated row at all**.
2. **`RemeasureUnitService` had no caller anywhere in `app/`** — no action, no controller, no
   command, no job. Only tests. The register existed and nothing could add to it.

Together: the correct path was unreachable and the reachable path was wrong. And the resulting
divergence splits along the worst possible line — CAM apportions on `areaOn()` and keeps the OLD
area, while the unit register, the lease's area, the `/api/v1` lease payload and the reports all read
the column and show the NEW one. The operator sees the change take effect everywhere they look while
the money quietly ignores it.

The fix is necessarily both halves: the column is read-only on Edit with a model guard behind it
(mirroring `leases.base_rent_monthly` ← `LeaseRentChangeService`, whose rent fields are read-only for
exactly this reason), **and** a Remeasure action so operators can record a re-survey at all. Locking
the column alone would have shipped a worse system than the bug.

**The guard needs no re-entrancy flag.** The service writes the dated row *before* it touches the
column, so by the time it saves, the rows already agree — asking whether they agree is both the guard
and the definition. A unit with no dated rows is left alone, which is what keeps pre-versioning data
behaving exactly as it did.

**This also corrects §7 of this document.** It recorded "Services never reached from any UI, HTTP,
console or job path: **none** of 135". That was wrong: `RemeasureUnitService` was one, on the day the
check was written. A census that answers "none" deserves one hand-check before it is believed.

### S3b — the finding was right, the reasoning was wrong, and the tests proved nothing

First pass: `UnitResource` guards `area_id` server-side on create AND edit, with a docblock spelling
out why a scoped picker is not a guarantee — while `floor_id`, from the newer floors register, had
nothing. An asymmetry between two sibling FKs on one form is a real signal.

The conclusion drawn from it was wrong. Filament's relationship-Select validation already refuses an
out-of-scope pick **from the form**, so a page-level guard changes nothing there — and the tests,
driven through the Create/Edit pages, **passed with the new guard deleted**. They proved the guard,
not the rule.

The hole is one layer down, and appeared the moment the raw call was written instead:

```php
Unit::create(['asset_id' => $a->id, 'floor_id' => $floorOfB->id, ...]);   // went straight through
```

Any import, console command, factory or future screen is that path — the sweep's whole thesis,
arriving in the area that looked like it had the least to find. The rule is now `Unit::saving` and
covers **both** columns, since `area_id` was reachable the same way: a unit tagged with another
mall's zone routes its tenant requests to that zone's supervisors (module 30 → 11), which is a
cross-property data leak rather than a cosmetic one. The page guards stay as the fast 403.

**Every guard added in this area was then mutation-tested** — deleted, watched go red, restored.
That is the only reason the S3b mistake was caught rather than shipped as a green finding.

---

## 11. Payables — the rule matrix (swept 2026-08-11)

Five rules. **One gap, and it is the canonical AP control.** The other four were already enforced —
which is the finding for them, and worth stating plainly given payables is where a wrong number
leaves the building.

| # | Rule | Was | Now | Proven by |
|---|---|---|---|---|
| P1 | A bill cannot be paid beyond its balance | 2 (locked + capped), and **structurally** so | unchanged | `VendorBillPayment::create` has exactly ONE caller |
| P2 | A bill's property must match its purchase request's property | 1 | unchanged | `VendorBill::saving` (module 29 fix — verified it holds) |
| P3 | A vendor invoice number is unique per vendor | **nothing, any layer** | **1** | `VendorInvoiceReferenceIsUniquePerVendorTest` |
| P4 | Approval bands enforced server-side | 2 + closed registry | unchanged — **but see the question below** | `ApprovalPolicy`, one enforcer per module |
| P5 | Posting-date guard on **both** approve and pay | 2 on both | unchanged | `PostingDateGuards` registry + gate |

### P3 — the same supplier invoice could be entered twice

`vendor_bills.reference` holds *"the vendor's own invoice reference"* — the migration says so — and
carried **no uniqueness of any kind**: no DB constraint, no model guard, not even a form rule. Two
people keying the same paper, a re-import, a scan processed after someone already typed it: two
payables for one debt, both approvable, both payable, and the mall pays the supplier twice.

This is the control an auditor asks about first, and it is **the same shape as the PDC cheque-number
gap** found in receivables. Both are *the counterparty's own document number*; both were missed for
the same reason — the SYSTEM's number (`number`) is generated and unique, so the field beside it
looked handled. Worth carrying forward as a pattern: **whenever a record stores a number that
someone else issued, ask what stops it arriving twice.**

Key: (vendor, reference) among non-**cancelled** bills, matched case-insensitively and trimmed —
because two people keying the same paper do not produce byte-identical strings, and an exact-match
guard would miss the realistic duplicate while looking like coverage. A **blank** reference is
exempt and is the deliberate escape hatch (leave it empty rather than typing a placeholder). The
create/edit pages mirror it as an inline error on the field, matching how `bill_date` already
reports its posting-date refusal there.

**Deviation from Yardi, stated:** Yardi warns and lets the operator through. We refuse — consistent
with the cheque-number decision, and for the stronger reason that this failure pays money *out of
the door* rather than mis-forecasting money coming in.

### P1 — worth explaining why "unchanged" is the right answer

`VendorBillService::recordPayment()` locks the bill and caps at the balance, so it cannot over-pay.
That is layer 2, and layer 2 is bypassable by a direct model write — except that
`VendorBillPayment::create` has **exactly one caller in the whole codebase**, and it is that service.
The Filament action calls it; nothing else creates one. So the guard is authoritative for the same
reason `Payment::assertInvoicesShareTenant` is: every writer goes through it, and the states it
would refuse are unreachable rather than merely guarded.

(The plan phrased this as "cannot be paid more than its net of withholding tax". That reading is
wrong about the design and worth correcting: withholding sits *inside* the gross payment — `amount`
discharges the vendor's claim in full, part in cash and part as tax paid to the ETA on their behalf,
and only the cash leg shrinks. The balance arithmetic never sees it.)

### An open question for the operator, not a finding

`ApprovalRule::MODULES` is closed and fully enforced — inventory draw, purchase request,
disbursement, one enforcer each, no inert module. **Vendor bill approval is deliberately outside the
ladder**, on the reasoning that the commitment is approved at the purchase request.

That reasoning has a hole worth putting to Eltizam rather than fixing on a guess:
`vendor_bills.purchase_request_id` is **nullable**, so an ad-hoc bill — one that never had a
purchase request — is approved with no amount band at any point in its life. Whether that is
acceptable depends on how they actually buy: if every material spend starts as a request, the ladder
already covers it; if ad-hoc bills are normal, a 500,000 EGP payable can be approved by whoever holds
`vendor_bills.approve`, regardless of amount. **Ask before building** — adding a fourth module to the
ladder is one row and one call site, but only if the answer is that it is missing.
