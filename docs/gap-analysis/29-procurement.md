# Module 29 — Procurement (purchase requests)

> **Round 2**, audited 2026-07-17 — first ever gap analysis, on a module built the day before
> (`e4b2e3f`, audited at `9ba4562`). Spec: [../modules/29-procurement.md](../modules/29-procurement.md)
> · methodology: [000-plan.md](000-plan.md).
> All findings **reproduced** against the real MySQL demo books, driven through the real services
> and a real `accounting:sync-ledger`, inside rolled-back transactions.

**Status: 🔴 Red.** The module's own lifecycle is genuinely well built — locks, a service-side
transition matrix, tier-on-current-total, source-linked receipts all hold up under attack. Its headline defect — **the GRNI clearing it exists to enable was unreachable from the product** — is closed, together with the aggregate cap it would otherwise have exposed.

`pest --parallel --filter='Purchase|Procurement|Grni'` → **46 passed**. Conformance gates → 63 passed.

---

## 1. Findings

### 🔴 F-100. The GRNI clearing has no production writer — every real bill still double-counts
`app/Filament/Admin/Resources/VendorBills/Schemas/VendorBillForm.php` (13 fields, none of them the
link) · `app/Services/Accounting/Journalizers/VendorBillJournalizer.php:134`

`goodsAwaitingInvoice()` returns `0.0` the moment `purchase_request_id` is null — and **nothing in
the application can make it non-null.** Verified by searching the capability, not the spelling:
`grep -rn "purchase_request" app/Filament/` finds nothing outside `Resources/PurchaseRequests`; the
only `VendorBill::create` in `app/` + `database/` + `routes/` is `DemoSeeder:2172`, which doesn't set
it; there is no `bills()` relation on `PurchaseRequest`, no relation manager, no lang key. **The
column is written only by `GrniClearingTest`.**

**Reproduced** — 500 EGP of stock through the full request → approve → order → receive flow, then
the supplier's invoice entered using *only the fields the form offers*:

```
purchase_request_id = NULL
Inventory  +500   (asset)      ← the receipt
Expense    +500   (P&L)        ← the bill: THE SAME MONEY, AGAIN
GRNI       −500   (liability)  ← the receipt, never cleared
AP         −500   (liability)  ← the bill
```

That is verbatim the four-line signature the commit message, the migration docblock and the module
doc all say is fixed. **The journalizer is correct; it is dead code.** The doc's *"A bill for those
goods now **clears** it"* is false in production, and the 166,120 EGP of uncleared GRNI has no path
to shrink.

**Fix:** give the form the link — a `purchase_request_id` Select scoped to that vendor's `received`
requests at that property. **Do it together with F-101**, never alone (see below).

### 🔴 F-101. Two bills against one purchase clear GRNI twice — latent *only* because F-100 blocks it
`app/Services/Accounting/Journalizers/VendorBillJournalizer.php:72` ·
`database/migrations/2026_07_17_100001_link_vendor_bills_to_purchase_requests.php:44`

`min($net, $goods)` caps **one bill** at what the receipt credited; nothing caps the **aggregate
across bills**, and `purchase_request_id` is a plain index (`vb_purchase_request_index`), not
unique. `goodsAwaitingInvoice()` re-reads the same received `line_value` sum for every bill, never
subtracting what earlier bills already cleared.

**Reproduced** — one purchase (10 × 50 = 500, received), two 500 bills both linked to it (a split
delivery, a deposit + balance, or simply a duplicate entry):

```
DELTA  {"GRNI": +500, "INV": +500, "EXP": 0, "AP": −1000}
        ↑ a clearing LIABILITY left with a debit balance
                                   ↑ 500 of cost vanished from the P&L
```

This is precisely what the doc says the `min()` exists to prevent — *"must not manufacture a GRNI
debit no receipt ever credited — that would swing GRNI positive and hide a real discrepancy"* —
achieved with two bills instead of one big one. **The books still balance (Dr = Cr), so the
`BooksReconciliationService` tie-out cannot catch it.**

> ⚠️ **Sequencing.** F-101 is unreachable today *because* F-100 blocks the link. **It goes live the
> moment F-100 is fixed** — which is the natural next commit. Fix them together, or the fix for the
> double-count ships the double-clear.

### 🟡 F-102. `cancel()` bypasses the approval ladder that `reject()` enforces · **FIXED 2026-07-17**
`app/Services/PurchaseRequestService.php:252` · `PurchaseRequestsTable.php:163`

`reject()` carries the tier check with an explicit rationale (`:141` — *"whoever cannot approve a
50,000 request should not be able to block it either"*). `cancel()` — the other refusal path,
reachable from the **same `requested` state**, producing the **same terminal outcome** — checks the
base right only.

**Reproduced**, `manager@mall.test` (`procurement.decide` + tier_2, no tier_3) vs a 50,000 request:
```
approve: refused (needs a higher authorisation level than yours)
reject : refused (needs a higher authorisation level than yours)
cancel : ALLOWED → status 'cancelled', decided_by=2
```
Also reproduced: that manager cancelled a 50,000 purchase **already approved and ordered by a
tier_3 senior** — unwinding a commitment they may not authorise.

### 🟡 F-103. `order()` has no tier check, contradicting the doc's stated reason for sharing the permission · **FIXED 2026-07-17**
`app/Services/PurchaseRequestService.php:164` · `PurchaseRequestsTable.php:117`

Doc §4: *"Deciding and ordering share one permission on purpose … whoever may place the order is
exactly whoever may approve it."* Base right only — the tier is never consulted. **Reproduced**: the
manager placed the order on a 50,000 request they cannot approve. Same root and same one-line fix as
F-102 — **the tier check is on 2 of the 4 `procurement.decide` paths.**

### 🟡 F-104. The Filament create page never calls `PurchaseRequestService::request()`
`app/Filament/Admin/Resources/PurchaseRequests/Pages/CreatePurchaseRequest.php:9` — a plain
`CreateRecord`. `request()` has **no production caller** (only tests).

Doc §2 opens *"Everything goes through `PurchaseRequestService`; the Filament actions are thin
callers."* True for the five table actions, **false for create**. Reproduced by replaying what the
page does:

- **`required_permission` is NULL on 100% of production rows.** The "frozen tier … the record must
  still say who was SUPPOSED to approve this" never exists; `pr_pending_queue_index` on
  `(status, required_permission)` indexes a permanently-null column; and the list table renders its
  `unknown` fallback — **"Needs a higher authority"** on every request, including a 500 EGP one that
  only needs a supervisor.
- **FR-PROC-01's "item(s)" is unenforced.** `request()` refuses `empty($data['lines'])`, but the
  page creates the header first. A request with **no lines**, total 0, was created and approved
  (0 → tier_1 band).

No money moves wrong — the gate is `canApprove()` on the *current* total, which is correct. This is
the frozen-record/audit half, and FR-PROC-01.

### 🟡 F-105. `receive()` null-derefs a soft-deleted warehouse and misdiagnoses it
`app/Services/PurchaseRequestService.php:213` — `(int) $locked->warehouse->asset_id`

`Warehouse` uses `SoftDeletes`, so the FK's `nullOnDelete` never fires and `warehouse_id` keeps
pointing at a trashed row; the `belongsTo` then resolves to `null`, slipping past the
`warehouse_id === null` guard at `:209`. **Reproduced** (retire a storeroom while its order is in
transit): `null->asset_id` → `(int) null` = `0` ≠ `asset_id`, so it lands in the **cross-property**
branch and tells the operator something false. Isolation still holds (it refuses), but the request
is stuck in `ordered` forever — `warehouse_id` is only editable while `requested`.

*Reasoned, not reproduced:* under Laravel's HTTP `HandleExceptions` the same warning becomes an
`ErrorException`, which `PurchaseRequestsTable` (catching `\DomainException` only) would not handle
→ **a 500**. Tinker's own error handler is why the probe saw a warning instead. Fix is the
registry's known pattern: `withTrashed()` on the relation.

---

## 2. The sibling-guard check

The round-2 pattern, applied deliberately. Every 🔴 this round was a guard that existed one branch
away — so for each guard procurement *has*, which sibling lacks it?

| Guard it has | Sibling path | Verdict |
|---|---|---|
| `approve()` tier check | `reject()` | ✅ consistent |
| `reject()` tier check | **`cancel()`, `order()`** | 🔴 **missing — F-102 / F-103** |
| `approve()` self-approval block | `reject()` / `cancel()` / `order()` | ✅ benign — refusing or ordering *your own already-approved* request isn't a second-pair-of-eyes failure |
| `min($net, $goods)` caps **one** bill | **N bills against one purchase** | 🔴 **missing — F-101** |
| `request()` refuses empty lines + freezes the tier | **the Filament create page** | 🟡 **missing — F-104** |
| Line model: catalog needs `unit_cost > 0` | service `request()` coercion | ✅ consistent — the model is the backstop for every caller |
| `receive()` re-checks the warehouse's property | the form's `clampAssetId` | ✅ consistent — service guards, form is one caller |
| `receive()` per-line `stock_movement_id` dedupe | terminal `received` in the matrix | ✅ belt and braces |
| `assertAssetInScope` on create | edit page | ✅ **both** have it |
| 5 custom table actions carry `->authorize()` | **`EditAction` (`:178`)** | 🟡 `visible()` only; `EditAction::setUp()` sets no default authorization, so the status gate is bypassable via `mountAction('edit', $id)`. Low impact (`asset_id` is `disabled()`, lines stay frozen, `receive()` still catches a bad warehouse) — worst case is rewriting `justification` behind an approval. One word to match its five siblings. → **D-95** |
| `StockMovementService` 0-cost + on-hand floor | the PR goods receipt | ✅ consistent — goes through `record()` |

## 3. D-90 — does the procurement caller honour both approval rules?

**`approve()`: yes, both.** Base right (`:99`) **and** the tier (`:106`), plus self-approval blocked
(`:114`) — matching `WorkOrderPartsRelationManager:71`. `canDecide()` checks both and hides approve
from the requester. The `ApprovalPolicy` docblock's "no bands → true for any signed-in user" hole is
correctly closed.

**The module as a whole: no.** `procurement.decide` covers four verbs; **two consult the ladder**
(F-102/F-103). D-90 is answered and becomes those two findings.

---

## 4. Verified-correct — don't re-audit

1. **The tier is judged on the CURRENT total, inside the lock** (`(float) $locked->total_value`), not
   the frozen `required_permission`. The "approved 500 quietly becomes 50,000" attack **fails**, and
   lines are frozen post-approval by the relation manager's `->authorize()` — the correct dispatch gate.
2. **FR-PROC-02 is genuinely unrepresentable, not merely discouraged.** No `requested → ordered` edge;
   `assertTransition` sits in the service, ahead of every caller.
3. **Concurrency is right.** Every mutating method re-reads through `lockForUpdate` *inside* the
   transaction; `received` is terminal and each line's `stock_movement_id` is a second dedupe —
   receiving twice or over-receiving is not reachable.
4. **Money math doesn't drift.** `line_value` derives in the model's `saving()` on every write path;
   the header re-derives via a DB `SUM` over `decimal(14,2)`; negative quantity and cost refused in
   the model.
5. **`PostingDate` is correctly not needed.** Procurement takes no operator-typed date — `receive()`
   passes no `moved_on`, so `StockMovementService` defaults to `today()`. Checked, not assumed.
   *(Adjacent, outside this module: `VendorBill::bill_date` IS operator-typed and becomes the GL
   `entry_date` with no `PostingDate` guard — pre-existing to modules 15/21. → **D-96**)*
6. **Property isolation is clean.** Registered, gate green, guard on create *and* edit, service-side
   re-check on receipt, shared catalog / per-property warehouses correctly distinguished.

---

## 5. Test gaps

- **🔴 GRNI clearing is NOT proven end to end — and the way it fails is new.** `GrniClearingTest`
  deserves real credit for dodging the named trap: it never touches `LedgerPoster` and drives the
  real services plus a real `accounting:sync-ledger`. But its `billFor()` helper (`:70`) sets
  `purchase_request_id` inside `VendorBill::create()` — **a state no production code path can
  produce.**
  > **This is the same trap one level up: the test doesn't fake the *posting*, it fakes the *input
  > the fix depends on*.** Nine tests green over a column the product cannot write. A Livewire test
  > driving `VendorBillForm` would have failed instantly. **Add that lens to 000-plan.md's
  > methodology** — "drive the real service" is necessary but not sufficient; the *inputs* must also
  > be reachable from the product.
- **No test puts two bills on one purchase** (F-101).
- **No test creates a `PurchaseRequest` through the Filament create page.** Every test calls
  `request()` — the one path production never uses. That is why `required_permission` is NULL
  everywhere and unnoticed, and why `ProcurementScenarioTest:78/152` can assert
  `required_permission === 'approvals.tier_3'` while no real row ever has it (F-104).
- **No test for `cancel()` or `order()` against the tier** (F-102/F-103).
- **No test soft-deletes a warehouse with an open order** (F-105).

## 6. Deferred

- **D-91 — F-100 + F-101 TOGETHER.** The link on `VendorBillForm`, *and* the aggregate cap
  (subtract what prior bills cleared; consider `unique` on the FK if one-bill-per-purchase is the
  intent). **Never F-100 alone.**
- ~~**D-92**~~ — ✅ **F-102/F-103 fixed 2026-07-17.** Extracted rather than copied a third time.
- **D-93** — F-104: route the create page through `PurchaseRequestService::request()`, so the tier
  freezes and FR-PROC-01's "item(s)" is enforced.
- **D-94** — F-105: `withTrashed()` on `PurchaseRequest::warehouse()`.
- **D-95** — `->authorize()` on the `EditAction`, matching its five siblings.
- **D-96** — `PostingDate` on `VendorBill::bill_date` (outside this module; modules 15/21).
