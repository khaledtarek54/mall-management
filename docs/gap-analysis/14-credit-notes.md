# Module 14 — Credit Notes

> Date: 2026-05-31
> Status: 🟡 Yellow — service is excellent and tested; 2 inline fixes (F-17 carryover, status-enum/query mismatch); 2 Yellow extensibility (Portal/Owner visibility, void-applied workflow).
> Surface: [CreditNote model](../../app/Models/CreditNote.php), [CreditNoteItem model](../../app/Models/CreditNoteItem.php), [CreditNoteService](../../app/Services/CreditNoteService.php), [Admin CreditNotes resource](../../app/Filament/Admin/Resources/CreditNotes/).

## 1. Inventory

### 1.1 Models

**[CreditNote.php](../../app/Models/CreditNote.php) (118 LOC)**. Traits: `HasFactory`, `LogsActivity`, `SoftDeletes`. Fillable 18 cols (identity / financial / lifecycle / audit). Date+datetime casts; decimal:2 on monetary. Relations: `tenant()` BelongsTo, `invoice()` BelongsTo nullable, `lease()` BelongsTo nullable, `items()` HasMany, `issuedBy()` BelongsTo User. Status enum (migration): `draft, issued, applied, void`. Helpers: `hasBalance()`, `generateNumber($assetCode, $issueDate)` → `CN-HW-YYYYMM-####`. Boot: auto-number, default currency EGP, init balance.

**[CreditNoteItem.php](../../app/Models/CreditNoteItem.php) (30 LOC)**. Fillable: description, amount, vat_rate, vat_amount, total. decimal:2 casts. Relation `creditNote()` BelongsTo (cascade-on-delete via migration).

### 1.2 Migration — `2026_05_24_131937_create_credit_notes_table.php`

- 18 cols + softDeletes; FKs: `tenant_id` restrict, `invoice_id` nullOnDelete, `lease_id` nullOnDelete, `issued_by_user_id` nullable.
- Status enum 4 values: `draft, issued, applied, void`.
- Reason enum 6 values: `return, dispute, adjustment, discount, refund, other`.
- Indexes `(status, issue_date)`, `tenant_id`, `invoice_id`.

### 1.3 Admin Resource (`app/Filament/Admin/Resources/CreditNotes/`)

| File | Notes |
|---|---|
| [CreditNoteResource.php](../../app/Filament/Admin/Resources/CreditNotes/CreditNoteResource.php) (132 LOC) | Custom `getEloquentQuery()` scopes via `lease.unit.asset_id` **OR** allows standalone notes (`lease_id IS NULL`) — clean handling of tenant-level vs. asset-scoped notes. Nav: `OutlinedReceiptRefund`, sort 3, group Billing. Badge: count of `issued + balance>0` (info color + tooltip). **F-17 fix applied this module**. |
| CreditNoteForm (231 LOC) | Tenant select (live) → Invoice select (cascades to tenant's open invoices, auto-syncs lease_id) → Lease select (optional). Items repeater with live `recomputeItem()` (VAT auto), `recomputeTotals()` sums to subtotal/vat_amount/total/balance. Status enum, reason enum, notes sections. |
| CreditNotesTable (105 LOC) | 9 cols incl. status badge (issued=info, applied=success, void=gray, draft=warning); 4 filters (status, reason, tenant, trashed). |
| EditCreditNote (117 LOC) | 4 header actions: **Issue** (visible on draft), **Apply to Invoice** (visible if hasBalance() && status≠void; modal picks tenant's open invoice + amount input capped at note balance), **Void** (visible draft/issued if applied_amount=0; requires confirmation), Delete. |

### 1.4 Service — [CreditNoteService.php](../../app/Services/CreditNoteService.php) (108 LOC)

| Method | Behavior |
|---|---|
| `issue(CreditNote): CreditNote` | Idempotent on already-issued/applied. Sets `balance = total − applied_amount`. Flips status → `issued` if balance > 0, else `applied`. DB transaction. |
| `applyToInvoice(CreditNote, Invoice, ?float $requested): float` | Returns 0 if note voided, no balance, or invoice no balance. Caps applied amount at `min(note.balance, invoice.balance, requested)`. **Atomic both sides**: note's `applied_amount += amount, balance -= amount`, status flips to `applied` if `balance ≤ 0`; invoice's `paid_amount += amount, balance -= amount`, status → `partially_paid` or `paid`. Returns the actual amount applied. DB transaction. |
| `void(CreditNote, ?string $reason): CreditNote` | Idempotent on already-void. **Throws `DomainException`** if `applied_amount > 0` ("Cannot void applied note; issue offsetting instead"). Sets status=`void`, balance=0, voided_at=now. Optionally appends reason to notes. DB transaction. |

**State machine** (effectively):
```
draft → issue() → issued
issued → applyToInvoice() (partial) → still issued (balance > 0)
issued → applyToInvoice() (full) → applied
issued → void() → void (if applied_amount=0)
applied → void() → ✗ throws (caller must issue offsetting note)
```

### 1.5 Cross-refs

- `Tenant::outstandingBalance()` queries `whereIn('status', ['issued', 'partially_applied'])`. **The `partially_applied` value isn't in the enum** — see F-55. In practice the service uses `issued` for both fully-unused and partially-used credits, so the filter behaves correctly through luck rather than design.
- No `Invoice::creditNotes()` HasMany — credit application updates `Invoice.paid_amount` directly without a join row, treating credits exactly like cash for AR purposes.
- CAM and Sales Declaration flows don't reference credit notes.

## 2. Spec map

| Source | Verbatim | Verified |
|---|---|---|
| FEATURES.md | "Credit Notes & Refunds — full AR lifecycle at `/admin/credit-notes` (issue → apply → void with idempotent service-layer math)." | ✅ |
| FEATURES.md | "CreditNoteService covers the three real operations: issue(), applyToInvoice(invoice, ?amount) (caps at min of note balance + invoice balance + requested amount; updates both sides atomically and flips invoice status to partially_paid/paid; idempotent on void), void(reason) (refuses if already applied)." | ✅ exact match |
| FEATURES.md | "6 PHPUnit tests in tests/Feature/CreditNoteServiceTest.php locking the math." | ✅ — 8 cases / 24 assertions actually (more than docs claim) |
| DEMO.md (numbers cheat) | "Credit notes: 4 (across draft / issued / applied / void)" | ✅ seeder produces 4 |

## 3. Findings

### 🔴 F-17 (Fixed inline, CreditNotes — 6th instance discovered) — nav badge bypassed tenant scope

Before:
```php
$count = static::getModel()::query()->where('status','issued')->where('balance','>',0)->count();
```

After:
```php
$count = static::getEloquentQuery()->where('status','issued')->where('balance','>',0)->count();
```

The custom `getEloquentQuery()` on this resource is interesting — it scopes via `lease.unit.asset_id` OR allows standalone notes (`lease_id IS NULL`). So an operator in Haya Walk now sees their per-property issued credit count + the tenant-level standalone notes that apply to any of their tenants. The ALL pseudo-asset still gets the portfolio-wide count.

This was the 6th F-17 instance (Units / Invoices / Maintenance / TenantSales / Vendors-pending + this one). Module 03's audit table listed 5; CreditNotes was overlooked. Cross-cutting progress reset: **5 of 6 done** (Vendors remaining).

### 🟡 F-55. `Tenant::outstandingBalance()` filters for unreachable `partially_applied` status

[Tenant.php:117](../../app/Models/Tenant.php#L117):

```php
$creditNoteBalance = (float) $this->creditNotes()
    ->whereIn('status', ['issued', 'partially_applied'])
    ->sum('balance');
```

But the migration enum is `('draft', 'issued', 'applied', 'void')` — there is no `partially_applied` value. The service's `applyToInvoice` flips status to `applied` only when balance reaches 0, leaving `issued` for partial applications. So in practice the `issued` filter alone covers the intended case.

The dead `partially_applied` filter is harmless today but:
- Misleads readers (suggests a 5-state machine that's actually 4-state).
- Would silently mishandle the partial case if someone later refactored to use `partially_applied` without also adding it to the enum.

**Two fix paths (D-41):**
- **A (small)**: Remove `partially_applied` from the Tenant query. Code now precisely matches the actual enum.
- **B (medium)**: Add `partially_applied` to the enum + migration + update `applyToInvoice` to set it when 0 < balance < total. More precise state machine; better LogsActivity story.

Recommend A for cleanliness, B if you want a more expressive AR audit trail. Defer for explicit decision — both are correct.

### 🟡 F-56. No Portal / Owner visibility of credit notes

Tenants see their balance shrink (via `outstandingBalance()`) when a credit is applied, but no line-item view of which credit/why. Owners don't see credits at all on their portfolio invoice list. Maybe intentional (credits are admin-side adjustments), but worth confirming.

**Defer D-42** — bundle with Module 11 F-43 portal-financial-detail discussion.

### 🟢 Void-applied refusal is correct

`void()` throws DomainException if `applied_amount > 0` with a clear message. Forces operators to issue an offsetting credit note instead — preserves the AR audit trail (you can see both the original credit and the reversal). Tested at [CreditNoteServiceTest](../../tests/Feature/CreditNoteServiceTest.php).

### 🟢 Standalone credit notes supported

Migration `invoice_id` and `lease_id` are nullable. Create-flow allows tenant-only credit notes ("general adjustment"). The custom `getEloquentQuery()` correctly keeps them visible in any property view via `OR lease_id IS NULL`.

### 🟢 Service idempotency is tested

`issue()`, `applyToInvoice()`, `void()` all idempotent on their target state and DB-transactional. 8 test cases cover the matrix.

## 4. Test sweep

| Filter | Result | Time |
|---|---|---|
| `php artisan test --parallel --filter='CreditNote'` | **8 passed / 0 failed** | 0.99 s |
| `npx playwright test tests/e2e/16-credit-notes-vendors.spec.js` | **6 passed / 0 failed** | 11.1 s |
| Full Pest post-F-17 fix | **295 passed / 0 failed** | 4.28 s |

## 5. Inline fix this module

**F-17 (CreditNotes carryover, 6th instance)**: 6 LOC. Pest 295/295 + 6 e2e all green.

Cross-cutting progress update — what I previously thought was a 5-resource problem is actually 6:
- ✅ Units (M03) · ✅ Invoices (M05) · ✅ Maintenance (M09) · ✅ TenantSales (M12) · ✅ CreditNotes (this) · ⏳ Vendors (M15).

## 6. Deferred decisions

| # | Decision | Default |
|---|---|---|
| D-41 | F-55: clean `partially_applied` dead filter (A) or extend enum + service (B) | A — clean is simpler; B if want a 5-state machine for audit |
| D-42 | F-56: portal/owner credit visibility — bundle with M11 F-43 | Defer post-pilot |

## 7. Verdict

**🟡 Yellow.** Credit Notes is a well-bounded module with a clean state machine, idempotent service, atomic AR updates, and strong tests. The F-17 carryover I missed in the original sweep is now fixed. F-55 is a code-cleanliness item, not a functional bug.

Module ratings: 00 🟢 · 01 🟡 · 02 🟢 · 03 🟡 · 04 🟡 · 05 🟡 · 06 🟢 · 07 🟢 · 08 🟡 · 09 🟡 · 10 🟢 · 11 🟢 · 12 🟡 · 13 🟡 · 14 🟡.

## Next

Module 15 — Vendors. Surface: [Vendor model](../../app/Models/Vendor.php), [VendorContact](../../app/Models/VendorContact.php), [VendorContract](../../app/Models/VendorContract.php), [Admin Vendors resource](../../app/Filament/Admin/Resources/Vendors/), and the **final** F-17 carryover fix.
