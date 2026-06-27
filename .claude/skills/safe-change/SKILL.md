---
name: safe-change
description: The definition-of-done for changing a module's business logic in Atriom — read the doc, change the service, honor invariants, test, regression-guard, update docs, commit. Use whenever modifying existing module behavior.
argument-hint: <what you're changing> (e.g. "raise the marketing levy to 6%")
---

# Make a safe change to module logic

Follow this every time you change existing behavior. It's why the system stays maintainable.

## 1. Understand before touching
Read the module's **`docs/modules/NN-*.md`** — especially *Business rules & invariants*, *Extension points (how to change safely)*, and *Gotchas* — then the service/model it cites. The Extension-points section usually tells you the exact safe seam.

## 2. Change in the right place
Business logic lives in **single-action services** (`app/Services`); keep controllers + Filament pages thin. Prefer extending a service over inlining logic in a page.

## 3. Honor the invariants (don't break these)
- **Money/AR:** `Invoice::recomputeTotals()` is the single source of truth (`paid = captured payments + credit_applied_amount`, `balance = total − paid`). Never set `paid_amount`/`balance` elsewhere.
- **VAT 14%** on service charges; base rent VAT-exempt. **Marketing levy 5%** of base rent (versioned/effective-dated — change the rate config, not hard-coded numbers).
- **Delete = super_admin only**; bulk-delete off. CRUD via `RoleGatedActions` on `{module}.{action}`.
- **Property scoping:** cross-property selects via `TenantScope::selectable*` / `visibleAssetIds()`.
- **NOT-NULL columns:** coerce blank form values in the model; never insert `null`.
- **Scheduled scans:** idempotent + `lockForUpdate()` + re-check the stamp inside the transaction.
- **Terminal records immutable:** closed/cancelled maintenance, responded owner-requests.
- **Auth surfaces:** `/admin`=User+roles · `/portal`=`TenantUser` (only `is_admin` writes) · `/api/v1`=Sanctum `tenant-api`; cross-tenant API → **404**.

## 4. Prove it
- `vendor/bin/pest --parallel` stays green.
- If this is a **bug fix**, add a `tests/Feature/Regression/` test that **fails without the fix** (verify it does), then passes.
- If you changed a field/validation, add/adjust a `tests/Feature/Regression/Validation/` guard.
- If you changed the schema, `php artisan migrate:fresh --seed` and re-check the demo data.

## 5. Docs are part of "done"
Update the module's `docs/modules/NN-*.md` (and `docs/OVERVIEW.md` if cross-cutting) **in the same commit** as the code. Update the relevant memory note if a convention/decision changed.

## 6. Ship
Commit to `main` directly (owner's preference), ending the message with:
`Co-Authored-By: Claude <noreply@anthropic.com>`
