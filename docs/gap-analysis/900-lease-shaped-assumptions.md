# Cross-cutting — lease-shaped assumptions after module 37

> **Round 3, 2026-08-18.** Not a module audit: a sweep of ONE bug class, opened because it produced
> three separate defects in a single day's work on modules 31, 32 and 37.

## 1. The class

`invoices.lease_id` was NOT NULL until module 37 (August 2026) introduced the **unit owner** — a
party who bought the shop, trades from it himself, and holds **no lease**. His صيانة is billed
against the ownership; `UnitOwnership implements BillableAgreement` exactly so the money core needs
no idea he exists.

Plan 08 §5.2b already named the lesson:

> *`lease_id` was NOT NULL, so four different pieces of code were entitled to treat the lease as the
> route to the property. Relaxing a NOT NULL is never only a schema change — it is a change to every
> inference that column licensed.*

**Phase 2a (2026-08-15) fixed the four load-bearing sites** — isolation registry, GL journalizer,
document numbering, marketing levy — by denormalising `invoices.asset_id` (and `credit_notes.asset_id`
with it). What it did not do is migrate the **read** layer, which module 37's doc records as
"~25 read sites still scope invoices through `lease.unit`".

Most of those are harmless: an eager-load or a display column renders a blank unit code for an owner
invoice. The dangerous ones are the sites where the lease chain acts as a **filter**, because there
the owner's row does not render blank — it disappears.

## 2. Found and fixed

### 🔴 A — another property's credit note appears in this property's monthly close

[`ReportService::scopedCreditNotes()`](../../app/Services/Reports/ReportService.php) scoped by walking
`lease → unit → asset`, with an explicit `whereNull('lease_id')` branch letting a lease-less note
through **unconditionally**, justified in a comment as *"standalone notes stay portfolio-visible, per
the resource"*.

That justification went stale on 2026-08-15. `CreditNote` is `#[PropertyOwned]` on its own `asset_id`,
so the credit-note **list** correctly hides a Mall A note from an operator scoped to Mall B — while
the **monthly close** showed it. Two rules, one row, different answers.

A note against an owner's assessment has no lease *by construction*, so this was not hypothetical:
Mall A's owner credit inflated Mall B's credited total and understated its net.

**Fixed** — the report now scopes through `TenantScope::applyTo()`, the same helper the resource uses,
so the two agree by construction rather than by agreement.
([`MonthlyCloseCreditNotesStayInTheirPropertyTest`](../../tests/Feature/Regression/MonthlyCloseCreditNotesStayInTheirPropertyTest.php)
— the leak, plus a control proving the note still counts in its OWN property.)

### 🟠 B — a unit owner in arrears is not "delinquent"

The Tenants list's `is_delinquent` filter narrowed a tenant's overdue invoices to the visible
properties via `whereHas('lease.unit', …)`. An owner assessment has no lease, so it was dropped and
the owner fell out of the filter — **an arrear that exists, is overdue, and is invisible to the one
screen built to find arrears.**

**Only a RESTRICTED user was affected**, because the narrowing sits inside
`->when(TenantScope::visibleAssetIds())`, which is null for super_admin. That is why it survived: the
person who could most easily have noticed is the one who could not see it.

**Fixed** — scoped off `invoices.asset_id`
([`DelinquencyFilterSeesOwnerArrearsTest`](../../tests/Feature/Regression/DelinquencyFilterSeesOwnerArrearsTest.php),
with both controls: a lessee is still flagged, and another property's arrears are still excluded).

### 🟡 C — an invoice could not say which unit it was about *(fixed)*

Both invoice tables read `lease.unit.code` directly, so every owner assessment rendered a **blank
unit** — to the operator in admin, and to the owner himself in the portal, on his own bill. The unit
**filter** had the same shape and was worse than blank: filtering a mall by an owner-occupied unit
returned nothing, which reads as "no invoices" rather than "this filter cannot see him".

**Fixed** — the rule now lives once on the model, `Invoice::unitCode()` and `Invoice::scopeForUnit()`,
because two surfaces ask it and a rule stated twice is a rule that drifts. Null stays possible and
stays correct: a multi-unit lease has no single unit.
([`InvoiceKnowsItsUnitThroughEitherAgreementTest`](../../tests/Feature/Regression/InvoiceKnowsItsUnitThroughEitherAgreementTest.php)
— lease control, owner case, and a third unit finding nothing so the scope narrows rather than
merely widening.)

### 🟡 D — a docblock that claimed callers it did not have *(fixed)*

`User::currentOwnedAssets()` was documented as the set "the owner statements + the portfolio widget
weight by `ownership_percentage`". The widget was deleted with the `/owner` panel; the statements
weight off the pivot in `GenerateOwnerStatementRunService` and never read this method. **That comment
produced a wrong first answer during the module-32 audit** — it is what made the F-A weighting gap
look already-closed.

Its `currentOwnershipShares()` wrapper genuinely has no production caller, but is **retained
deliberately**, not deleted: it is the natural home for absolute weighting if F-A option 1 is ever
taken. Both docblocks now say which is production and which is retained, and
`OwnershipWeightingTest` additionally asserts the tenure rule against `currentOwnedAssets()` — the
relation `AssignedAssets` and the owner pack actually call — so the rule that matters is no longer
covered only incidentally, through a test of code nothing runs.

### 🔴 E — the credit-note cross-property guard was SKIPPED for owner documents *(fixed)*

The sharpest instance, and the one that moved money. `CreditNoteService::applyToInvoice()` refuses to
settle one property's invoice with another property's note — its comment states the stake plainly:
the note's single GL entry (Dr Sales Returns) is attributed to ONE property, and letting it cross
means *"its owner is paid a share on revenue that was credited back"*.

It resolved both sides through the lease:

```php
$invoiceAssetId = $invoice->lease?->unit?->asset_id;
$noteAssetId    = $note->lease?->unit?->asset_id;
if ($noteAssetId !== null && $invoiceAssetId !== null && …) { refuse }
```

A unit owner's assessment has no lease, so **both sides came back null, the `!== null` preconditions
were false, and the check was skipped entirely**. The null-guard that existed to let a genuinely
unscoped note bind on first apply became the hole — for owner documents it is never not-null. A
fail-closed guard that failed open for exactly the documents it could not see.

Proven: one party owning a unit in two malls (ordinary, not contrived) — mall A's note settled mall
B's assessment. **Fixed** by reading the denormalised columns, which is what the binding twenty lines
below had always read; the guard and the binding were reading different sources.
([`CreditNoteCrossPropertyGuardHoldsForOwnersTest`](../../tests/Feature/Regression/CreditNoteCrossPropertyGuardHoldsForOwnersTest.php),
with a same-property control so the fix cannot be "refuse everything".)

### 🟠 F — an owner's credit note vanished from the register, and their invoices from their own tab *(fixed)*

`CreditNoteResource::getEloquentQuery()` scoped through `lease.unit`, with a standalone-note fallback
keyed on `tenant.leases.unit`. An owner's note matched **neither** branch and disappeared from the
list entirely — not a leak, a disappearance, which nobody reports because there is nothing on screen
to report. `TenantInvoicesRelationManager` had the same hop, so a party's assessments were missing
from the tab that lists what they owe.

**Fixed** — both scope on the row's own `asset_id`. The credit-note null-asset fallback stays, because
it is real, and now recognises a party affiliated by **ownership** as well as by lease.

## 5. The gate

[`NoLeaseShapedPropertyScopeConformanceTest`](../../tests/Feature/Scenarios/NoLeaseShapedPropertyScopeConformanceTest.php)
fails the build when a model carrying its own `asset_id` has its PROPERTY inferred through a lease
hop. Deliberately narrow: it flags a `whereHas('lease…')` that constrains `asset_id`, and leaves eager
loads, display columns and genuine lease-domain filters alone — those are not claims about which
property a row belongs to. Mutation-proven: restoring any one of the fixes above turns it red and
names the file and line.

It also asserts its own premise — that `invoices` and `credit_notes` still carry `asset_id` — so it
fails loudly rather than passing vacuously if that ever stops being true.

## 3. Checked and harmless

The remaining `lease.unit` reads are eager-loads and display fields on surfaces where the lease is
the subject: invoice and statement PDFs, the CSV exporter, sales-declaration and CAM-allocation
infolists. An owner invoice renders a blank unit there rather than vanishing, and those documents
are lease documents. Left as presentation, not correctness.

## 4. The general lesson

**A denormalisation is only half-done until the read sites move.** Phase 2a gave every invoice its own
property and correctly called itself a prerequisite — but a column nothing reads changes nothing, and
for three months the writes were right while two reads still inferred. Both defects here are the same
sentence in different files: *the lease is the route to the property.*

The cheapest future guard is a conformance test asserting no query filters `Invoice` or `CreditNote`
through `lease.unit` when the model carries `asset_id`. Not written — see
[the reachability-gate note](../../CLAUDE.md); it belongs with that gate rather than alone.
