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
| **3 — Partial receipts** | receive some lines / some quantity now, the rest later. The line-level `stock_movement_id` is the seam; not built because the FRD does not ask and half-receiving is a real workflow decision | ⬜ deferred |

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
