# 29 · Procurement (مشتريات)

> The request-to-purchase flow for spare parts, consumables, and services (FR-PROC-01..05), and the
> module that finally gives a goods receipt a **source** (FR-WH-02).

**Read the FRD's verbs before changing this.** Verbatim:
> **FR-PROC-01** — "allow authorized users to create a purchase/procurement request specifying
> **item(s), quantity, and justification**."
> **FR-PROC-02** — "route procurement requests through an approval workflow **before order
> placement**."
> **FR-PROC-03** — "support bilingual (Arabic/English) labels for procurement forms and item
> descriptions, consistent with the rest of Atriom."
> **FR-PROC-04** — "link procurement requests to the Inventory module so that approved purchases
> **update stock upon receipt**."
> **FR-PROC-05** — "maintain a **status history** for each procurement request (e.g., Requested →
> Approved → Ordered → Received)."
> **FR-WH-02** — "log stock movements (in/out) with timestamp, user, and **linked work order or
> procurement reference**."

---


> **⚠️ Billing twice what was ordered looked like an ordinary bill (2026-08-19).** Found by driving
> procurement on real data, not by a failing test: a purchase worth 5,000, received into stock, then
> a supplier bill for **10,000** linked to it — accepted without a murmur.
>
> **The GL was never wrong.** `VendorBillJournalizer` clears GRNI up to the RECEIVED value and
> expenses the remainder, which is correct for a bill that also covers labour or delivery. That is
> precisely why this must NOT be a refusal: the legitimate case and the duplicate-billing case post
> identically, and the only difference is whether a human meant it. Blocking would break the case
> the journalizer was built for.
>
> So the bill form now shows the **three-way match** where an AP clerk can act on it — ordered,
> received into stock, and billed *including this one* — and says in red how far past the purchase
> it runs. `PurchaseRequest::billingVariance()` sums **every postable bill**, because the case that
> hides is a split delivery: two bills of 3,000 against a 5,000 purchase each look fine alone. It
> uses the same `postable()` scope the journalizer uses to decide which bills consume received
> value, so the screen and the ledger cannot disagree, and it excludes the bill being edited so
> re-saving one does not double-count itself.
>
> `PurchaseBillingVarianceIsVisibleTest`.



## The `draft` status, and the reorder loop it exists for (2026-08-19)

`PurchaseRequest` gained a **`draft`** state. It can only be **submitted** or **cancelled** — it
can never be approved — and that single restriction is what makes it safe for something other than
a person to create one.

**Why it was needed.** `inventory:scan-low-stock` had alerted per property since it was built, and
the alert was the whole mechanism: somebody then re-typed the same shortages into a purchase
request by hand. Closing that loop is a policy question rather than a missing query — *who
approves a purchase the system raised by itself?* The operator's answer (2026-08-19): **the scan
drafts, a human submits.**

That answer had nowhere to live. `requested` is already IN the approval ladder, so a system-raised
request would have had its **approval tier chosen by a value nobody entered** — the module whose
whole job is to fail closed, deciding on its own input. Hence a state before the ladder.

- **`DraftReorderPurchaseService`** builds ONE draft per property from the open `LowStockAlert`
  rows, and **refreshes** it on the next run rather than creating a second — otherwise a weekly
  scan leaves a drift of stale drafts and the operator learns to ignore all of them. Refreshing
  also means a shortage that has resolved itself **drops off** the draft instead of being ordered
  anyway, which is the failure mode of every helpfully pre-filled document.
- **A system-raised draft has `requested_by_user_id === null`** — nobody asked for it. That is a
  fact about the row rather than a flag to maintain.
- **`PurchaseRequestService::submit()`** is the human act. It refuses a non-draft, refuses an empty
  one (F-104's rule, on a path that did not exist when F-104 was written — a drafted request can
  legitimately end up empty when every shortage it was raised for resolved), assigns the submitter
  as requester, and freezes the approval tier from the total as submitted.
- **`inventory_items.reorder_quantity`** (nullable) says *how much we buy at a time*;
  `reorder_level` only ever said *when*. **Null is a real answer** meaning "we have not said", and
  the drafted line then carries the shortfall — a number that lands the item exactly on its own
  threshold and is therefore to be corrected, not accepted. Inventing a multiple of the reorder
  level would be inventing a purchasing policy, and a plausible wrong number in a draft gets
  approved whereas a blank gets filled in.
- **`PurchaseRequest::LINES_EDITABLE_IN`** replaces an inline `status === STATUS_REQUESTED` in the
  line-freeze guard. That comparison silently meant "a draft is settled" the moment `draft`
  existed: writing a line to a request nobody had even asked for was refused with a message about
  an approval that had not happened. One constant, two readers, no drift.
- `--no-draft` switches the drafting off without losing the alert; `--dry-run` writes nothing at
  all, because F-96 in this module was exactly a preview that wrote rows.

## 1. Domain model

### `purchase_requests` — "we need this, here's why"
| Column | Meaning |
|--------|---------|
| `asset_id` · `reference` | property-owned (`PR-{asset}-{YYYYMM}-{n}`) — a mall's storeroom needs it, its budget pays |
| `status` | `requested` → `approved` → `ordered` → `received`; `rejected`/`cancelled` are terminal ends |
| `justification` | **NOT NULL** (FR-PROC-01) — a purchase nobody can justify is what the approval exists to catch |
| `warehouse_id` | where the goods land (FR-PROC-04). Nullable — a service has nowhere to land |
| `vendor_id` · `order_reference` | who we ordered from, and their PO no. Set at ordering: you approve a *need*, then choose a supplier |
| `total_value` | derived from the lines; **stored** because it decides the approval tier and must not drift |
| `required_permission` | the tier demanded **at request time** — frozen |
| `requested_by` · `decided_by`/`decided_at`/`decision_notes` · `ordered_by`/`ordered_at` · `received_by`/`received_at` | provenance for every step |

### `purchase_request_lines` — the items (FR-PROC-01)
| Column | Meaning |
|--------|---------|
| `inventory_item_id` **XOR** `description` | a catalog item (becomes stock on receipt) **or** free text for a service/non-catalog thing. Never both — two would disagree about what was bought |
| `quantity` · `unit_cost` · `line_value` | `line_value` derived (`qty × cost`) on every write path |
| `stock_movement_id` | the receipt this line produced — the audit link, and the backstop against stocking a line twice |

`inventory_item_id` is nullable because the FRD's own preamble scopes this module to "spare parts,
consumables, **and services**" — and a service is not stock.

---

## 2. Business rules

**The approved LINES are frozen too (2026-08-11, module 29 close-out).** `PurchaseRequest::updating`
already froze the header — asset, warehouse, justification — because they are what the approval
signed off on. The lines are that same thing, and were not frozen: `PurchaseRequestLinesRelation
Manager::editable()` (`status === requested`) gated the add / edit / delete actions on that screen and
nothing else. This is an **approval-ladder hole**, not a balance one:

> raise 5,000 → tier_1 → a supervisor approves it, correctly · add a 500,000 line to the approved
> request · `recomputeTotal()` re-derives `total_value` to 505,000 while `required_permission` stays
> frozen at tier_1 — deliberately, because the record must keep saying who was *supposed* to sign it
> off (the F-104 fix). The mall is committed two tiers above what anyone with the authority approved,
> and the record asserts a supervisor approved it.

`PurchaseRequestTierFrozenTest` carried the belief that made this look safe — *"approve() judges the
CURRENT total anyway"*. It does, **once, at approval**; a line added afterwards never re-enters it.

`PurchaseRequestLine::saving`/`deleting` now refuse when the request has left `requested` — but only
for the **commercial** fields (`inventory_item_id`, `description`, `quantity`, `unit_cost`,
`line_value`) plus any create or delete. `stock_movement_id` is deliberately excluded: **receiving
goods stamps the line it fulfilled**, on a request that is past `requested` by definition, so
freezing the whole row makes the module unreceivable. The first cut of the guard did exactly that and
broke 18 tests across receipt and GRNI clearing — the difference between *"the approval is settled"*
and *"the row is"*. Tests: `PurchaseRequestLinesFreezeOnApprovalTest`.


Everything goes through `PurchaseRequestService`; the Filament actions are thin callers.

- **FR-PROC-02 is the absence of an edge, not a comment.** `PurchaseRequest::TRANSITIONS` has no
  `requested → ordered`, so ordering an unapproved request is *unrepresentable*. That is the whole
  requirement — "route through an approval workflow **before order placement**" — expressed as data.
- **Which approver depends on the value**, resolved by [`ApprovalPolicy`](28-approvals.md) against
  the `purchase_request` bands, and **frozen** onto the row at request time so a later edit to the
  bands cannot rewrite history about who was supposed to sign off.
- **…but the tier is re-judged on the CURRENT total at approval.** Lines can change after the
  request is raised; what matters is the value actually being approved, not what it was worth when
  someone first clicked. The frozen `required_permission` is a record, not the gate.
- **Two questions, both required, to decide.** The base right (`procurement.decide`) **and** the
  tier. `ApprovalPolicy::canApprove()` answers "which manager", never "may this person touch
  procurement at all" — with no bands configured it returns true for *any* signed-in user (its own
  docblock says so; the spare-part draw shipped that hole once already).
- **You cannot approve your own purchase.** Second pair of eyes, as FR-CM-10 demands of a part draw.
- **`unit_cost` uses `filled()`, not `??`** — a blank `''` is not an absent value, and `(float) ''`
  is `0.0`, which would price a line at nothing and drop the whole request to the lowest tier.
- **Receipt is the only path here that writes stock**, and it stamps `source_type`/`source_id`
  (FR-WH-02). Services produce no movement. A line already carrying a `stock_movement_id` is
  skipped — `received` is terminal so the transition matrix is the first guard, this is the backstop.
- **Goods are received into the mall that requested them** — guarded in the service, not only the
  form, because the form is one caller.
- **FR-PROC-05's status history is the activity log**, not a bespoke table: `logOnly([...'status'...])`
  records who/when/from→to, and spatie v5 stores the before/after in `attribute_changes`. A
  dedicated table is only needed if per-step comments or attachments are required — the FRD asks for
  neither. **Confirm before building one.**
- **Lines are frozen once approved.** Editing what is being bought behind an approved *value* is
  exactly the failure FR-PROC-02 exists to prevent.

---

## 3. Why this module matters to the books

A goods receipt posts **Dr Inventory / Cr GRNI** (Goods Received Not Invoiced, `21701001`) — a
dedicated clearing liability, deliberately *not* the AP control, so the AP tie-out stays honest
(a receipt has no vendor bill behind it yet). GRNI is meant to be cleared later against that bill:
**Dr GRNI / Cr AP**.

It never was. The ad-hoc receipt action (`ListStockMovements::receiveAction`) writes a movement with
a free-text `reference` and **no `source_type`/`source_id`** — so nothing can match a receipt to a
bill. Measured on the demo books: **166,120 EGP of GRNI credits, zero debits, across 12 lines — 0 of
12 receipts carried a source link.** The journalizer's own docblock calls the fix "a future
enhancement".

A receipt raised through a purchase request now carries its source, which is both what FR-WH-02 asks
for and the precondition for ever clearing GRNI. Proven in `PurchaseReceiptLedgerTest`.

A bill for those goods now **clears** it. `vendor_bills.purchase_request_id` links the invoice to
the purchase it pays for, and `VendorBillJournalizer` posts the goods portion to **Dr GRNI** instead
of Dr Expense — so the purchase costs the company its money exactly once:

```
Receipt               Dr Inventory / Cr GRNI     "we have the goods, not yet the invoice"
Bill for those goods  Dr GRNI      / Cr AP       the invoice — GRNI nets to zero
Consumption           Dr Expense   / Cr Inventory the cost hits the P&L when it is USED
```

> ⚠️ **It was worse than an uncleared account.** Before this, buying 500 EGP of stock **once** left
> `Inventory +500`, **`Expense +500`**, `GRNI −500` and **`AP −500`** — the same money recognised
> twice (once as an asset, once in the P&L) and the liability recorded twice. Every stock purchase
> whose supplier bill was entered overstated **both** the P&L and the balance sheet by its full
> value. Proven, then fixed; pinned by `GrniClearingTest`.

Three rules make the clearing honest, each pinned by a test that fails without it:
- **Only RECEIVED, stockable lines clear.** A service line never touched stock; an unreceived line
  has posted no GRNI credit yet. Read from the lines that actually produced a movement
  (`stock_movement_id`), so it stays true for a partially-received purchase.
- **Never clear more than the receipt credited** (`min($net, $goods)`). A bill larger than the goods
  — freight, a price rise — must not manufacture a GRNI debit no receipt ever credited; that would
  swing GRNI positive and hide a real discrepancy. The excess is an expense.
- **A bill with no purchase behind it is unchanged**: all of net is expense. That is most bills.

> **Still open:** the **ad-hoc receipt action** still writes sourceless movements, so a purchase
> received that way remains unlinkable and its GRNI uncleared. It has legitimate non-purchase uses
> (opening balances, found stock), so it was not removed — but the demo's 166,120 EGP is still
> untraceable for exactly this reason: `DemoSeeder` uses that path. Route purchases through a
> request.

---

## 4. RBAC & module flag

- `procurement.view/create/edit/delete` (delete = super_admin only) + `procurement.decide`
  (approve/reject/order/cancel) + `procurement.receive`.
- **`procurement.decide` is withheld from `operations`.** Operations raises the need and receives
  the goods; committing the mall's money is a management act. Manager inherits it via the blanket
  non-delete grant.
- Deciding and ordering share one permission on purpose: FR-PROC-02 puts approval *before* order
  placement, so whoever may place the order is exactly whoever may approve it.
- Module flag **`procurement`** (`Modules::KEYS` + `ModulesSettings`), on by default.

---

## 5. Roadmap

| Phase | Scope | Status |
|-------|-------|--------|
| **1 — Request → Approve → Order → Receive** | `purchase_requests` + lines, the value-based approval ladder reusing [module 28](28-approvals.md), the transition matrix that makes FR-PROC-02 unrepresentable, receipt → stock with a **source link** (FR-WH-02), status history via the activity log, property-scoped resource + lines relation manager | ✅ shipped |
| **2 — Clear GRNI against the vendor bill** | `vendor_bills.purchase_request_id` + the GRNI split in `VendorBillJournalizer`. Fixed a proven **double count**: a purchase was hitting Inventory *and* Expense, with GRNI and AP both carrying the liability. Only received stockable lines clear, capped at what the receipt credited | ✅ shipped |
| **3 — The Purchase Order document + UX** | `po_number` stamped at order time (its own identity, distinct from the requisition `reference`); `PurchaseOrderPdfService` + a bilingual blade renders the numbered, itemized, priced PO — downloadable from the row and the edit page once ordered. Plus the UX pass: a **"View working"** modal (lines → total → which approval tier the value falls into) and **feedback carrying the resulting state** (order → the PO number + who it went to; receive → how many items stocked where). Demo seeds one ordered + one received request | ✅ shipped |
| **4 — Partial receipts** | receive some lines / some quantity now, the rest later. The line-level `stock_movement_id` is the seam; not built because the FRD does not ask and half-receiving is a real workflow decision | ⬜ deferred |

---

## 6. Gotchas

- **The FRD's own open item on approval:** *"The client did not specify a formal approval hierarchy
  for procurement itself. Confirm whether procurement approval also follows a price-based manager
  hierarchy or a separate rule."* We default to **price-based**, identical bands to a stock draw —
  because that is the only hierarchy the client has ever described (FR-CM-11), and because it is
  **configuration**, so their answer is a row change rather than a rewrite. Flagged in
  BUSINESS-RULES.
- **A test that raises a request as `operations` and asserts self-approval is refused proves
  nothing** — `operations` has no `procurement.decide`, so it dies on the permission check long
  before self-approval is reached. Mutation testing caught exactly that. Raise it as a *manager*.
- The item catalog is a deliberately SHARED register — a pump seal is the same part in every mall —
  so it is not property-filtered. Warehouses are.

### Close-out sweep — 2026-07-27

- **A PR-linked vendor bill re-derives its SIBLINGS' GRNI clearing when it changes.** The clearing is FIFO
  across a purchase's bills (`VendorBillJournalizer::goodsAwaitingInvoice`), so cancelling/adding/re-dating one
  bill changes what the others should clear — but the windowed `accounting:sync-ledger` keys on each row's own
  `updated_at`. `VendorBill::touchPurchaseRequestSiblings()` (fired on `saved`/`deleted`/`restored`) bumps the
  siblings so the sweep revisits them. **Any new field that moves the FIFO (status/bill_date/total/vat/PR link)
  must keep triggering the touch**, or a sibling strands a stale clearing until a CLI `--all`. See
  [[project_child_source_windowed_sweep]].
- **A bill that clears GRNI must be in the SAME property as its purchase request** (`VendorBill::saving` gate) —
  the clearing debit posts to the bill's `asset_id`, the receipt credited GRNI in the request's; a mismatch
  strands GRNI in one mall and is invisible to the close gate.
- **The header freezes wholesale once the request leaves `requested`** (`PurchaseRequest::updating`): `asset_id`,
  `warehouse_id`, `justification` are immutable after approval — a received request's warehouse must not diverge
  from the movement, and the PO must keep naming the storeroom that received the goods. The `warehouse_id` is
  also validated in-scope on `saving` (a crafted foreign warehouse would surface its name on the PO PDF).
  `receive()`'s cross-property check remains the backstop.
- **Stock movements honour the closed-period guard** (`StockMovementService::record` → `PostingDate::assertOpen`
  on `moved_on`) — a back-dated receipt/adjustment into a closed period is refused, not silently stranded.

---

## 7. Tests

`tests/Feature/Scenarios/ProcurementScenarioTest.php` — the lifecycle and its refusals: a request
needs items and a justification; the total derives from the lines and follows them; **ordering an
unapproved request is refused** (FR-PROC-02) and so is receiving an unordered one; the tier rises
with value and is judged on the *current* total; self-approval refused; a service line stocks
nothing; a mixed request stocks only its stockable half; no line is ever stocked twice; receiving
stockable goods with no warehouse, or into another property's warehouse, is refused — while a
service-only request with no warehouse is fine; the status history reads
`['approved','ordered','received']`.

`tests/Feature/Regression/PurchaseReceiptLedgerTest.php` — the **money half**, and it never touches
`LedgerPoster`: it drives the service and then the real `accounting:sync-ledger` sweep. A receipt
posts balanced, debits inventory and credits **GRNI not AP**, doesn't double-post on re-run, and —
the point of the module — **every GRNI credit traces back to the purchase request that made it**.

All four guards verified load-bearing by mutation (and the fifth, self-approval, was found *not* to
be and its test fixed).

**Related:** 22 Inventory (the stock a receipt lands in), 28 Approvals (the value → approver ladder),
26 Facility Maintenance (the jobs that consume what this buys), 21 General Ledger (GRNI).

## The purchase order document (2026-08-27)

Rebuilt on the shared document shell — see
[OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference). Two things
specific to a PO:

- **It is written in the SUPPLIER's language.** The Download PO button carries a language picker
  defaulting to the vendor's own `vendors.locale` (added 2026-08-28 — see
  [12-vendors](12-vendors.md)). Blank is the normal state and falls through to the operator's, with
  the picker as the answer.
- **It now carries a signature block.** A PO is an instruction a supplier acts on, and the
  countersigned copy is what settles an argument about what was ordered — the lines were being sent
  with nowhere to sign them.

## The document, set in Direction D (2026-08-28)

Built on the shared shell (`resources/views/pdf/layout.blade.php`) and rendered by
`App\Support\Pdf\PdfDocument`: a full-bleed navy band carrying the mall's identity, everything below
it white paper with hairlines, and the one figure the reader came for set apart on the accent.

The direction was chosen from four drawn side by side in both languages; the tradeoff accepted with
it is that this is the heaviest of the four on ink, which is why the band is the ONLY large ink field
and the accent is spent once per page. See
[OVERVIEW → Core business rules](../OVERVIEW.md#4-core-business-rules-quick-reference).

**It is written in its reader's language**, resolved through `App\Support\Pdf\DocumentLocale` —
what the operator picked on the download modal, else the recipient's own stored `locale`, else the
request's. Blank is the normal state.

**Do NOT add an `@page` rule to the template.** Page geometry belongs to the renderer, which is also
the thing that knows there is a running footer; a template that sets its own margins leaves no room
for it and the footer renders nowhere at all.
