# Module 32 — Owner Statements & Disbursements · gap analysis

> **Round 3, 2026-08-18.** First audit of this module — it shipped after round 2 closed and was on
> the never-gap-analysed list in [PROJECT-MAP](../PROJECT-MAP.md).
> Method: [000-plan.md §Round-2 methodology](000-plan.md) — an absence claim is a hypothesis, a
> finding needs a concrete failure scenario, and it is proven by exploiting it.
>
> **Benchmark sources.** [competitors/03](competitors/03-deposits-utilities-portal-owner.md)
> (Yardi Investment Manager · Re-Leased · AppFolio owner accounting) and
> [benchmarks/yardi/](../benchmarks/yardi/README.md). **The Atriom column of competitors/03 is
> STALE** — dated 2026-07-18, it ranks "owner statements + disbursements" as gap #1 and "PDC
> register" as gap #2, and both were built afterwards. Only the *competitor* columns are used here
> as the yardstick; the Atriom side is re-derived from code.

## 1. Verdict

**The module is well-built and clears the benchmark on the things that are hard to retrofit** — a
GL-derived accrual statement, recompute-then-freeze with supersede-based revision, a penny-reconciled
tie-out, a lock-safe disbursement cap, posting-date guards in the service, and both sources in the
single `LedgerPoster::JOURNALIZERS` registry. Yardi and AppFolio ship the owner *workflow*; few ship
it over a real double-entry ledger with per-property isolation, which is what Atriom does here.

**One 🔴 money finding**, and it is the module's own stated v1 assumption not being enforced by the
screen that can violate it.

## 2. Findings

### 🔴 F-A — a part-owner is distributed the WHOLE property net

**Benchmark.** Yardi Investment Manager and AppFolio owner accounting distribute an owner their
**ownership share**. An incomplete ownership register leaves the remainder undistributed; it never
inflates a recorded owner's cut.

**Atriom.** [`GenerateOwnerStatementRunService`](../../app/Services/OwnerAccounting/GenerateOwnerStatementRunService.php#L142-L154)
weights each owner `ownership_percentage / Σ ownership_percentage`, so shares always sum to the full
net. That is correct when every owner is recorded — and module 32's documented v1 assumption is
exactly that ("one owner, 100%"). **Nothing enforces the assumption.**
[`AssetOwnersRelationManager`](../../app/Filament/Admin/RelationManagers/AssetOwnersRelationManager.php#L57-L64)
validates each row `0.01..100` independently; there is no cross-row sum-to-100 rule, no guard on the
`AssetOwner` pivot, and none in the generate or finalise services (searched repo-wide).

**Failure scenario — proven, not argued.**
[`OwnerSharesMustSumToTheOwnershipRecordedTest`](../../tests/Feature/Regression/OwnerSharesMustSumToTheOwnershipRecordedTest.php):

| Recorded ownership | Property net | Allocated before the fix | Owned |
|---|---|---|---|
| one owner @ **100%** | 6,000 | 6,000 ✅ *(control — passes)* | 6,000 |
| one owner @ **50%** | 6,000 | **6,000** ❌ | 3,000 |
| two owners @ **30% + 30%** | 6,000 | **3,000 each** ❌ | 1,800 each |

**Why it is money.** `net_distributable` is what finalise posts as Dr `owner_distributions` /
Cr `due_to_owner`, and `owner_share` is the cap `DisbursementService` pays against — re-checked under
lock, but against the inflated figure. So a 50% owner is accrued, and payable, **twice** what they
are owed. Jawad holding half a mall with the other half outside Atriom is the realistic case.

**FIXED 2026-08-18 — option 2, the operator's call.** The arithmetic is untouched (no GL amount
moves); what changed is that finalise refuses a run whose recorded ownership does not total 100%,
naming the property and the total. Guarded on the money path rather than the owners form, because a
50/50 register cannot be built in one save — the relation manager now shows the running total, and
the draft stays generatable because that is how an operator discovers the shortfall. Genuine
co-owners summing to 100 finalise normally (covered by a second control). The gate is proven by
mutation: disabling it turns both refusal specs red.

**The options considered were:**
1. **Weight absolutely** (`pct / 100`), leaving the remainder in retained earnings — the benchmark
   behaviour. The signature tie-out (`net_distributable == net_profit` for a sole 100% owner) still
   holds, because 100/100 = 1.
2. **Enforce sum-to-100** on the owners relation manager, keeping the normalisation safe by making
   the violating state unreachable.

Option 1 remains available if Eltizam ever takes on co-investors whose partners are outside Atriom:
it is the Yardi/AppFolio arithmetic, and it would let an incomplete register distribute correctly
rather than be refused. It was not taken because it changes a GL-posting amount and goes beyond the
v1 scope the operator set.

### 🟡 F-B — `User::currentOwnershipShares()` is tested dead code with a docblock that overstates it

No production caller ([grep](../../app/Models/User.php#L243-L250)); the only callers are in
`OwnershipWeightingTest`. Its docblock says the set is what "the owner statements + the portfolio
widget weight by `ownership_percentage`" — but the portfolio widget was removed with the `/owner`
panel, and the statements weight independently inside the generate service, not through this method.
This is the [F-100 shape](000-plan.md): a green test over code the product never runs. It is also a
caution for this audit — that docblock is what first appeared to falsify F-A, and it was stale.

**Fix:** delete the method and its test, or wire it into the fix for F-A (it is the natural home for
an absolute weight).

### 🟠 F-C — the management fee is configurable, described as charged, and never charged *(module 37, found en route)*

`unit_ownerships.management_fee_pct` + `fee_basis` are written by the form, cast, `ValueSets`-gated
and activity-logged — and **read by no service**. Nothing computes, bills, posts or reports the fee.
The deferral is real and correctly blocked (module 37 §8: the GL account for management-fee income is
an open accountant question — it is Eltizam's revenue, not the property's, so a guess puts the
operator's income in the owner's P&L). The defect was the **screen**: the helper read *"Our cut of
what we collect for this owner"* in the present tense.

**Fixed here** — the helper now says the fee is recorded but not billed, in both languages, and
[`UnitOwnerManagementFeeIsNotChargedYetTest`](../../tests/Feature/Regression/UnitOwnerManagementFeeIsNotChargedYetTest.php)
characterises the behaviour so it cannot drift; it fails the day the fee is built, which is the point.

## 3. Verified clean (hypotheses that did NOT hold)

Recorded so nobody re-audits them — every one started as a plausible gap:

| Hypothesis | Result |
|---|---|
| Statements ignore `ownership_percentage` (competitors/03's stated gap) | **False** — the generate service weights every share and stamps `ownership_percentage`, `weight`, `tenure_from/to` on each statement |
| A statement can be finalised with no owner | **False** — refused in `FinaliseOwnerStatementRunService` since 2026-08-11, with the remedy named; the draft is deliberately still allowed, because generating it is how the operator finds out |
| `paid_to_date` can drift from the disbursements | **False** — derived via `recomputePaidToDate()`, mirroring `Invoice::recomputeTotals()` |
| A payout can exceed the owner's share | **False** — capped at `owner_share − paid_to_date`, re-checked **under lock** at both schedule and payment |
| A finalise or payout can land in a closed period | **False** — both go through `App\Support\PostingDate` in the service, not the model |
| `basis = cash` silently reports accrual figures | **False** — the constant was removed 2026-08-11 rather than left as a label with nothing behind it |

## 4. Not assessed

- **Cash-basis owner reporting** — deferred by design with its trigger recorded; a receipts-and-payments
  P&L is a real build, not a flag.
- **Waterfall / preferred-return distributions** (Yardi Investment Manager's deeper tier). Out of
  scope for a single-owner-per-mall operator; note it only if Eltizam takes on co-investors.
