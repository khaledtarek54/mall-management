# Credit Notes

> Adjustable credit instruments that offset tenant invoice balances via durably-tracked AR settlement.

## 1. Purpose & business context

An Eltizam (mall operator) issues credit notes to tenants (retailers) to adjust invoice balances for reasons like overcharges, partial refunds, or service disputes. A credit note is a debit memo: it reduces the amount a tenant owes, similar to a payment but recorded separately so it survives payment recomputes and never mixes with actual cash flow. Unlike payments (which are external money), credits are internal accounting adjustments that require operator discretion to issue and apply.

The system allows an operator to:
- Create and issue a credit note (status: draft → issued).
- Apply its balance incrementally against one or more invoices of the same tenant (status flips to `applied` when fully consumed).
- Void an unapplied note (status → void, balance → 0).
- Never void an already-applied note (caller must issue an offsetting note to reverse).

## 2. Domain model

| Table | Model | Key columns | Meaning |
|-------|-------|-------------|---------|
| `credit_notes` | `CreditNote` | `number` (unique, auto-generated e.g., "CN-AW-202602-0001"); `tenant_id` (FK); `invoice_id` (FK, nullable); `lease_id` (FK, nullable); `status` (enum: draft/issued/applied/void); `issue_date`; `reason` (enum: return/dispute/adjustment/discount/refund/other); `reason_notes` (text); `subtotal`, `vat_amount`, `total` (all decimal:2); `applied_amount` (cumulative total applied to invoices, decimal:2); `balance` = total − applied_amount (decimal:2); `currency` (default 'EGP'); `issued_by_user_id` (FK); `applied_at` (timestamp, stamped on first apply); `voided_at` (timestamp); `notes`; soft deletes | The note header; tracks issuance, application, and voidance. Applied credit is durable via `credit_applied_amount` on invoices. |
| `credit_note_items` | `CreditNoteItem` | `credit_note_id` (FK, cascade); `description`; `amount` (pre-VAT, decimal:2); `vat_rate` (%, 0–100, decimal:2); `vat_amount` (computed, decimal:2); `total` = amount + vat_amount (decimal:2) | Line items; each note has ≥1 items. Total = sum of item totals. |
| `invoices` | `Invoice` | `credit_applied_amount` (decimal:2, default 0) | (Added via migration 2026_06_27_000001.) Tracks applied credit per invoice so `recomputeTotals()` includes it in `paid_amount`. Backfilled from existing `paid_amount` minus captured payments at migration time. |

### Relationships

- `CreditNote` → `Tenant` (belongsTo); `Invoice` (belongsTo, nullable); `Lease` (belongsTo, nullable); `User` (issuedBy, belongsTo on issued_by_user_id).
- `CreditNote` → `CreditNoteItem[]` (hasMany, cascade delete).
- `Invoice` does NOT have a direct hasMany to CreditNote (credit is applied via `credit_applied_amount`, not a relationship).

## 3. Business rules & invariants

| Rule | Enforcement | Test coverage |
|------|-------------|---------------|
| **Initialization**: A new credit note defaults to `status='draft'`, `balance=total`, `applied_amount=0`, `currency='EGP'`, `applied_at=null`. | `CreditNote` boot hook (creating). | Implicit in setup. |
| **Number generation**: Auto-generated on create if empty. Format: `CN-{AssetCode}-{YearMonth}-{ZeroPadded4DigitSequence}`. Falls back to 'AW' if no asset/lease is linked. Resets sequence per asset per month. | `CreditNote::generateNumber()` static; called in boot. | `CreditNoteScenarioTest::test_generates_sequential_zero_padded_numbers_within_a_property_+_month()` |
| **hasBalance() guard**: A note can only be applied if `balance > 0` AND `status in ['issued', 'applied']`. Draft notes return false. | `CreditNote::hasBalance()` method. | `CreditNoteScenarioTest::test_cannot_apply_a_draft_note()` |
| **Issue() idempotency**: Issuing a draft note moves status → `issued` and computes balance = total − applied_amount. If already `issued` or `applied`, returns unchanged (early exit, no re-save). A zero-total note flips straight to `applied`. | `CreditNoteService::issue()` check at line 17. | `CreditNoteScenarioTest::test_issue_is_idempotent_an_already_issued_note_is_returned_unchanged()`, `test_issuing_a_zero_total_note_flips_straight_to_applied()` |
| **Apply capping**: When applying to an invoice, the actual amount is `min(note.balance, invoice.balance, requestedAmount)`. Cannot apply <= 0 or to a note with no balance or void status; returns 0 instead. | `CreditNoteService::applyToInvoice()` lines 36–74. | `CreditNoteScenarioTest::test_over_apply_caps_at_minimum()`, `test_applying_a_non_positive_requestedAmount_is_a_no_op()` |
| **Applied credit durability**: When a credit is applied, `invoice.credit_applied_amount` is bumped (not the payments pivot). `Invoice::recomputeTotals()` folds this into `paid_amount = captured_payments + credit_applied_amount`. This survives later payment recomputes. | `CreditNoteService::applyToInvoice()` line 69; `Invoice::recomputeTotals()` line 187. | `CreditNoteBalanceDriftTest::test_keeps_an_applied_credit_when_a_later_captured_payment_recomputes_the_invoice()` |
| **Status flips on apply**: After applying, note status becomes `issued` if balance > 0, or `applied` if balance = 0. | `CreditNoteService::applyToInvoice()` line 61. | `CreditNoteServiceTest::test_fully_applied_note_status_flips_to_applied()` |
| **applied_at stamp**: Set on first apply; never re-stamped on later applies (protected by `applied_at ?? now()`). | Line 62. | `CreditNoteScenarioTest::test_applied_at_is_stamped_on_the_first_apply_and_never_moved()` |
| **Void guard**: Cannot void a note that has already been partially or fully applied (`applied_amount > 0`). Throws `DomainException`. Unapplied draft/issued notes can be voided. Void is idempotent. | `CreditNoteService::void()` lines 87–89. | `CreditNoteScenarioTest::test_void_throws_when_already_applied()`, `test_void_is_idempotent()` |
| **Void effects**: Voiding zeroes `balance`, stamps `voided_at`, appends reason to `notes`, flips `status → void`. | Lines 97–98. | `CreditNoteScenarioTest::test_void_on_an_unapplied_issued_note_zeroes_the_balance()` |
| **Item VAT computation**: Per item: `vat_amount = round(amount * vat_rate / 100, 2)`, `total = amount + vat_amount`. Note totals aggregate: `subtotal = sum(item.amount)`, `vat_amount = sum(item.vat_amount)`, `total = subtotal + vat_amount`. | Form `recomputeItem()` (line 176 in CreditNoteForm). | Implicit in form UI. |
| **Scoping**: In Filament, a lease-linked credit note is visible only under its asset's property scope. A standalone note (no lease_id, no invoice_id) is visible under all properties (tenant-level adjustment). | `CreditNoteResource::getEloquentQuery()` lines 85–93. | `CreditNoteScenarioTest::test_scopes_a_lease_linked_credit_note_to_its_own_property()`, `test_shows_a_standalone_credit_note_under_every_property_scope()` |

## 4. Lifecycle / state machine

```
draft
  ├─ issue()
  │   └─ issued (or applied if total=0)
  │       ├─ applyToInvoice()
  │       │   └─ issued (if balance>0) or applied (if balance=0)
  │       │       ├─ applyToInvoice() again
  │       │       │   └─ applied (when drained)
  │       │       └─ void() → ERROR (cannot void if applied_amount > 0)
  │       └─ void()
  │           └─ void (balance zeroed, voided_at stamped)
  └─ void()
      └─ void (balance zeroed, voided_at stamped)

void (terminal, immutable)
  ├─ applyToInvoice() → returns 0, no-op
  └─ void() → idempotent, no-op

applied (terminal if fully drained, but can re-issue or re-apply manually via Filament; not truly immutable)
```

**Transitions:**
- **draft → issued**: `issue()` (manual action in Filament).
- **issued → applied**: `applyToInvoice()` when balance reaches 0.
- **issued ↔ issued**: Multiple `applyToInvoice()` calls drain the balance.
- **issued/draft → void**: `void()` (manual action; fails if applied_amount > 0).
- **void → void**: Idempotent (early return in `void()`).
- **No reverse**: A note cannot go from applied/void back to draft. To reverse an applied note, issue an offsetting credit note.

**Immutability & terminals:**
- `void` is terminal. `applied` is logically terminal (balance=0) but not locked; re-editing in Filament could theoretically change items, but this is not a common flow.
- Once `applied_at` is stamped, it never changes.

## 5. Services, jobs & scheduled commands

### `CreditNoteService` (`app/Services/CreditNoteService.php`)

#### `issue(CreditNote $note): CreditNote`
- **What**: Moves a draft note to `issued` status. Sets `balance = total - applied_amount`. Returns early (unchanged) if already issued/applied.
- **Idempotency**: Full (repeated calls on the same note return unchanged, no re-save).
- **Transaction**: Yes (`DB::transaction()`).
- **Locking**: None (optimistic concurrency OK for single-operator flow).
- **When**: Called by Filament action `EditCreditNote::getHeaderActions()` line 29.

#### `applyToInvoice(CreditNote $note, Invoice $invoice, ?float $requestedAmount = null): float`
- **What**: Applies (part of) a credit note's remaining balance to one invoice. Returns the actual amount applied (0 if no balance, note voided, or invoice fully paid).
- **Capping**: `min(note.balance, invoice.balance, $requestedAmount)`.
- **Side effects**: Bumps `note.applied_amount`, recomputes `note.balance`, flips `note.status` (issued ↔ applied), stamps `note.applied_at` if first apply. Updates `invoice.credit_applied_amount` and calls `invoice.recomputeTotals()` to fold the credit into `paid_amount`.
- **Idempotency**: Partial. Repeated calls on the same note/invoice pair accumulate. The guard `if (!$note->hasBalance())` prevents re-applying a fully-consumed note.
- **Transaction**: Yes (`DB::transaction()`).
- **Locking**: None; but the transaction ensures both `CreditNote` and `Invoice` updates are atomic.
- **When**: Called by Filament action `EditCreditNote::getHeaderActions()` line 67.

#### `void(CreditNote $note, ?string $reason = null): CreditNote`
- **What**: Voids an unapplied note. Sets `status → void`, `balance → 0`, stamps `voided_at = now()`, appends reason to `notes` if provided. Returns early (unchanged) if already void.
- **Guard**: Throws `DomainException` if `applied_amount > 0` (cannot void an applied note).
- **Idempotency**: Full on the void branch (early return if already void).
- **Transaction**: Yes (`DB::transaction()`).
- **Locking**: None.
- **When**: Called by Filament action `EditCreditNote::getHeaderActions()` line 99.

**Note**: No scheduled commands; no background jobs for credit notes. All operations are synchronous, manual (operator-triggered via Filament).

## 6. Filament resources & key fields

### Resource: `CreditNoteResource` (`app/Filament/Admin/Resources/CreditNotes/CreditNoteResource.php`)

**Navigation**: Under "Accounting" group, sort order 3, icon: receipt-refund.

**RBAC**: Uses `RoleGatedActions` trait; permission module key = "credit_notes" (default from model name). Permissions checked: `canViewAny`, `canCreate`, `canEdit`, `canDelete`.

**Scoping**: Custom `getEloquentQuery()` (lines 85–93):
- If an asset_id is in TenantScope, only show notes linked to that asset (via `lease.unit.asset`).
- Standalone notes (lease_id = null) visible everywhere.

**Navigation Badge**: Shows count of `status='issued'` notes with `balance > 0` (ready to apply).

#### Form: `CreditNoteForm` (`app/Filament/Admin/Resources/CreditNotes/Schemas/CreditNoteForm.php`)

**Sections & fields:**

1. **Credit Note Details**
   - `number`: Disabled, auto-generated, dehydrated (shown but not edited).
   - `tenant_id`: Required, searchable (name, legal_name, email), live (triggers invoice_id refresh).
   - `invoice_id`: Optional, dynamic options (top 50 invoices for selected tenant, scoped to asset if present). Selecting an invoice auto-fills `lease_id`.
   - `lease_id`: Optional, scoped to asset, searchable, preloaded.
   - `reason`: Required, enum (return/dispute/adjustment/discount/refund/other), default 'adjustment'.
   - `issue_date`: Required, date picker, default = today, native=false.
   - `status`: Required, enum (draft/issued/applied/void), default 'draft'.

2. **Items** (Repeater, min 1)
   - `description`: Text, required, span 5.
   - `amount`: Numeric (0+), prefix 'EGP', required, live (onBlur), span 2.
   - `vat_rate`: Numeric (0–100, %), default 0, required, live (onBlur), span 2.
   - `total`: Computed (read-only, dehydrated), span 3.
   - Recompute on item add/edit/delete via `afterStateUpdated()` → calls `recomputeTotals()` (form level).

3. **Amounts** (read-only aggregates)
   - `subtotal`: Dehydrated, read-only.
   - `vat_amount`: Dehydrated, read-only.
   - `total`: Dehydrated, read-only.
   - `balance`: Dehydrated, read-only (= total − applied_amount).

4. **Notes** (collapsible)
   - `reason_notes`: Textarea.
   - `notes`: Textarea (includes void/audit trail).

**Validation**: Items validated by Repeater (minItems=1). Required fields enforced by Filament validators.

#### Table: `CreditNotesTable` (`app/Filament/Admin/Resources/CreditNotes/Tables/CreditNotesTable.php`)

**Columns** (with actions):
- `number`: Searchable, copyable, mono font, small size.
- `tenant.name`: Bold, searchable.
- `invoice.number`: Mono font, placeholder '—'.
- `reason`: Badge, translatable enum label.
- `issue_date`: Date (d/m/Y format), sortable.
- `total`: Money, EGP, right-aligned, sortable.
- `applied_amount`: Money, EGP, info color, right-aligned.
- `balance`: Money, EGP, right-aligned, bold, color green if > 0, gray if 0, sortable.
- `status`: Badge, translatable, color-coded (issued=info, applied=success, void=gray, draft=warning).

**Filters**: status, reason, tenant_id, trashed.

**Actions**: Edit (if canEdit), bulk delete (if canDeleteAny).

**Default sort**: issue_date desc.

#### Pages

- **ListCreditNotes**: Standard list page with filters.
- **CreateCreditNote**: Standard create page; mutates form data to set `issued_by_user_id = auth()->id()`.
- **EditCreditNote**: Standard edit page with three header actions:
  1. **Issue**: Visible if status='draft'. Calls `CreditNoteService::issue()`, refreshes form, shows success notification.
  2. **Apply**: Visible if `hasBalance()` and status ≠ 'void'. Modal form: select invoice (balance > 0, status in [issued, partially_paid, overdue], sorted by issue_date desc), input amount (0.01–note.balance, default = note.balance). Calls `CreditNoteService::applyToInvoice()`, refreshes form, shows success notification with amount and invoice number.
  3. **Void**: Visible if status in [draft, issued] and applied_amount ≤ 0. Requires confirmation. Calls `CreditNoteService::void()`, refreshes form, shows success notification. Catches `DomainException` and shows as danger notification.
  4. **Delete**: Standard.

## 7. Notifications & integrations

**Notifications** (Filament in-app only):
- `admin.notifications.credit_note_issued`: Shown when a note is issued (title only, body = note number).
- `admin.notifications.credit_note_applied`: Shown when applied (title + body with amount and invoice number).
- `admin.notifications.credit_note_voided`: Shown when voided (title only).
- `admin.notifications.credit_note_apply_failed`: Shown if apply returned 0 (warning-level).

**External integrations**: None. Credit notes are internal GL adjustments; they do not trigger ETA submission or Paymob payment. They reduce AR locally via `credit_applied_amount` but do not affect remittance to ETA or owner statements.

## 8. Extension points — how to change/extend SAFELY

### To add a new credit reason enum:
1. Update the migration and the `reason` enum in the `credit_notes` table.
2. Add the new reason to `admin.enums.credit_note_reason` in translation files (e.g., `resources/lang/en/admin.php`).
3. It will automatically appear in the form and table filters (both use `__('admin.enums.credit_note_reason')`).
4. No service logic change needed.

### To add a new status (e.g., 'hold' for pending approval):
1. Add to the `status` enum in the migration and model.
2. Update the status translations in `admin.statuses.credit_note`.
3. Modify `CreditNoteService::issue()` and/or `CreditNote::hasBalance()` to handle the new status if it affects workflow.
4. Update `EditCreditNote::getHeaderActions()` visible() guards to show/hide actions for the new status.
5. Add test cases in `CreditNoteScenarioTest`.
6. Do NOT add new permissions automatically; coordinate with `RolesPermissionsSeeder`.

### To allow applying a credit to multiple tenants (cross-tenant credits):
1. **Blocking change**: Currently `applyToInvoice()` assumes same tenant. Add a tenant_id check or allow nullable tenant_id in schema.
2. Update `applyToInvoice()` to skip tenant validation.
3. Add test in scenario suite to verify cross-tenant applies.
4. Update form invoice_id options query to include multi-tenant invoices if tenant_id is nullable.

### To allow reversing a voided note:
1. Add a new status, e.g., 'reversed', and a `reversed_at` timestamp.
2. Modify `void()` to transition → 'reversed' and set a `reversed_by` user_id.
3. Ensure existing void logic (idempotency, guard) still works.
4. Add test: reversing a reversed note is a no-op.

### To integrate credit notes with Paymob or external payment sync:
1. Add a new enum status 'remitted' and a `remitted_at` timestamp.
2. Create a new service method `remit(CreditNote $note): bool` that calls the external API and stamps `remitted_at`.
3. Add a header action in `EditCreditNote` to trigger remit (visible if applied and not yet remitted).
4. Do NOT call remit automatically from `applyToInvoice()` (sync writes to GL first, async posts later).
5. Add test: remitting an already-remitted note is idempotent (early return).

### To add audit logging (already done via LogsActivity):
- Credit notes already log status, number, total, applied_amount, balance, reason, and invoice/tenant changes.
- To add more columns, update the `getActivitylogOptions()` in the model, e.g., `->logOnly(['number', 'status', 'voided_at', ...])`.

### To add VAT on the credit note itself (currently per-item):
1. The form and model already support item-level VAT; aggregate it to the note level.
2. A "note-level VAT rate" override is not yet supported. If needed, add a `note_vat_rate` column and modify `recomputeTotals()` in the form.
3. Ensure the vat_amount computation does not double-tax (items have VAT, then note adds more).

### CRITICAL: Do NOT:
- **Do NOT edit applied_amount or balance directly on the note after it's issued.** Always use `applyToInvoice()` or `void()` so both sides (note and invoice) stay in sync and `applied_at` and `credit_applied_amount` are updated consistently.
- **Do NOT modify an invoice's balance without calling `recomputeTotals()`.** Applying credit directly to `invoice.paid_amount` will be erased on the next payment recompute.
- **Do NOT bypass the `credit_applied_amount` column.** If you add a separate "credits" table or relationship, ensure it merges into `recomputeTotals()` so AR balances stay durable.
- **Do NOT allow users to manually set `status` without calling the service.** The service ensures invariants (balance ≤ total, applied_at stamped once, etc.); manual updates bypass these.

## 9. Gotchas, edge cases & recently-fixed bugs

### AR Drift (FIXED)
**Bug**: A credit applied to an invoice was erased when a later payment was added, because `Invoice::recomputeTotals()` summed only the `captured` payments pivot. The credit was recorded in `paid_amount` but no durable column tracked it.

**Fix** (migration 2026_06_27_000001_add_credit_applied_amount_to_invoices.php):
- Added `invoices.credit_applied_amount` column (decimal:2, default 0).
- `CreditNoteService::applyToInvoice()` bumps both `note.applied_amount` AND `invoice.credit_applied_amount`.
- `Invoice::recomputeTotals()` folds `credit_applied_amount` into `paid_amount` BEFORE applying status logic.
- Backfill: Computed credit for each existing invoice as `paid_amount − sum(captured_payments)` at migration time.

**Test**: `CreditNoteBalanceDriftTest::test_keeps_an_applied_credit_when_a_later_captured_payment_recomputes_the_invoice()` ensures the credit is never erased.

### Multi-Apply Accumulation
A single credit note can be applied incrementally to one invoice across multiple calls to `applyToInvoice()`. E.g., apply 1000, then 2000 to the same invoice. The `applied_at` is stamped only on the first call; later calls do not re-stamp.

Test: `CreditNoteScenarioTest::test_accumulates_two_sequential_partial_applies()`.

### Cross-Invoice Drain (Single Note, Multiple Invoices)
A credit note can be drained across two (or more) invoices of the same tenant. E.g., note with 5000 balance applied to invoice A (2000 balance) → fully covers A, 3000 left. Then apply to invoice B (4000 balance) → applies 3000, invoice B has 1000 left, note is fully consumed.

Test: `CreditNoteScenarioTest::test_drains_one_credit_note_across_two_invoices()`.

### Void Guard
Cannot void an applied note (`applied_amount > 0`). This is by design: if a note is already reducing an invoice, voiding would require reversing that reduction. Instead, the caller must issue an offsetting note (e.g., a negative credit) or use a separate "reversal" flow (not yet implemented).

Test: `CreditNoteScenarioTest::test_void_throws_when_already_applied()`.

### Zero-Total Note
A credit note with `total=0` (or all items = 0) is issued straight to `status='applied'` because there is no balance to apply. This is a no-op flow but valid.

Test: `CreditNoteScenarioTest::test_issuing_a_zero_total_note_flips_straight_to_applied()`.

### Scoping: Standalone vs. Asset-Linked
- **Asset-linked**: A note with a `lease_id` is visible ONLY under its unit's asset in multi-property scopes. This enforces property isolation.
- **Standalone** (lease_id = null, invoice_id = null): Visible under ALL properties. Used for tenant-level adjustments that are not tied to a specific unit.

Test: `CreditNoteScenarioTest::test_scopes_a_lease_linked_credit_note()`, `test_shows_a_standalone_credit_note()`.

### Decimal Precision
All amounts use `decimal:2` (2 decimal places). Rounding happens per-item in the form (`recomputeItem()` line 181) and in the service (`applyToInvoice()` implicit via typed casts). Edge case: applying 100.07 to 100.07 invoice drains exactly to 0.0 (no floating-point slop).

Test: `CreditNoteScenarioTest::test_a_cent_level_apply_drains_exactly_to_zero()`.

### Applied_at Immutability
Once `applied_at` is stamped (first apply), it is protected by `applied_at ?? now()` and never moves. This is the "first application" timestamp, useful for AR aging and audit.

Test: `CreditNoteScenarioTest::test_applied_at_is_stamped_on_the_first_apply_and_never_moved()`.

### Filament Form Re-computation
The form's `recomputeTotals()` and `recomputeItem()` are form-level aggregations using `Get` and `Set` utilities. They happen client-side before save, so the UI always shows correct totals. But a malicious or glitchy form submission could bypass these. The model's boot hook (`booted()` method) should re-validate or recompute server-side if needed (currently it only initializes `balance` if null).

### Invoice Transition to 'paid'
When `applyToInvoice()` fully covers an invoice, the invoice transitions to `status='paid'` via `recomputeTotals()`. This is automatic. A later manual payment recompute could flip it back to 'partially_paid' if a payment is reversed; the credit is not affected (it stays in `credit_applied_amount`).

### No Audit Trail for Apply Calls
The model logs status/balance changes via ActivityLog. But individual apply calls are not logged separately; only the final state of `applied_amount` and `balance` are in the log. Consider adding a separate `credit_note_applies` table or event if audit of each apply is needed.

## 10. Tests & related modules

### Test Files

1. **`tests/Feature/CreditNoteServiceTest.php`**
   - Core service tests: issue idempotency, apply with capping, apply to zero-balance invoice, void guard, apply-then-fully-drain status flip.

2. **`tests/Feature/Scenarios/CreditNoteScenarioTest.php`**
   - Comprehensive scenario suite: state transitions (issue, void idempotency), multi-apply accumulation, cross-invoice drain, invalid states (draft apply, voided apply), boundary cases (over-apply, cent-level precision), scoping (asset-linked vs. standalone), numbering.
   - Entry point: `cnFixtures()`, `cnDraft()`, `cnSvc()` helper functions.

3. **`tests/Feature/Regression/CreditNoteBalanceDriftTest.php`**
   - AR drift fix regression: verifies that an applied credit survives a later payment recompute (the 2026_06_27_000001 migration fix).

### Related Modules

- **Invoices** (`docs/modules/XX-invoices.md` if it exists): Credit notes apply to invoices; see `Invoice::recomputeTotals()` which folds in `credit_applied_amount`.
- **Payments** (`docs/modules/XX-payments.md` if it exists): Payments and credits are sibling settlement methods. Both reduce `invoice.balance` but via different columns (`invoice_payment` pivot vs. `credit_applied_amount`).
- **Leases** (`docs/modules/XX-leases.md` if it exists): Lease scoping used to filter which credit notes are visible in Filament.
- **Tenants** (`docs/modules/XX-tenants.md` if it exists): Tenants receive credit notes; a note is scoped to one tenant (multi-tenant apply not yet supported).

### Activity Log

Credit notes use Spatie ActivityLog (`LogsActivity` trait). Logged fields: number, status, invoice_id, tenant_id, total, applied_amount, balance, reason. Changes trigger a new activity entry in the `activity_log` table with log_name='credit_note'.

