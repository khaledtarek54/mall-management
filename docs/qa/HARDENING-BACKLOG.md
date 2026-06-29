# Pre-prod hardening backlog

Findings from the two adversarial review passes this session that are **not yet
fixed** — deferred because they need a careful, reviewed change (money/disk
migrations, or a model hook) rather than a rushed one. Prioritised; each has the
verified analysis + recommended fix. **14 other findings from these passes were
fixed** (see the git log + `docs/plans/02-qa-testing-plan.md`).

> Run a third adversarial pass after clearing these (loop-until-dry): the first
> pass found 8 bugs, the second 11 — the codebase still has hardening headroom.

---

## HIGH

### H1 — Cancelling an invoice with applied credit leaks the credit
**Where:** `app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php` (status select) → no reversal hook; `CreditNoteService`.
**Problem:** When an invoice with `credit_applied_amount > 0` is manually set to `cancelled`/`credited`, the consumed credit is **never returned** to the tenant — the source credit note's balance isn't restored and no offsetting note is issued. The credit is lost against a non-collecting invoice.
**Fix:** On the cancel/credited transition (an `Invoice` `updating` observer, or a single-action service called from the form), if `credit_applied_amount > 0` auto-issue an **offsetting CreditNote** for that amount (the invoice doesn't track which note(s) it came from, so a fresh note is the safe restore) and zero `credit_applied_amount`. Regression: cancel a credited invoice → the tenant's net credit is preserved + books tie.

### H2 — Tenant attachments on the PUBLIC disk with enumerable URLs (cross-tenant file disclosure)
**Where:** `CreateMaintenanceRequestAction.php` (media to the default/public disk) + `MaintenanceRequestResource.php` returns `getFullUrl()`.
**Problem:** Request photos/PDFs are served from public, **unauthenticated, guessable** URLs — any party can fetch another tenant's attachment.
**Fix:** Store the `attachments` collection on a **private** disk and serve only through an **authenticated, tenant-scoped** controller (`$request->user()->maintenanceRequests()->findOrFail($id)` → stream the media), or Spatie temporary signed URLs. Set `MEDIA_DISK` explicitly. (Existing attachments must be migrated to the private disk.)

---

## MEDIUM

### M1 — Reconciliation control totals / reports show GROSS AR, ignoring unapplied credit notes
**Where:** `app/Services/Reconciliation/BooksReconciliationService.php` (`outstandingAR` control total) + portfolio/AR-aging reports.
**Problem:** `outstandingAR` sums invoice balances but does **not** net **unapplied** issued credit-note balances, so the close report's net AR diverges from `Tenant::outstandingBalance()` (which does net them). Largely mitigated now that CAM credits **auto-apply** (review pass-2 #1), but a manually-issued standing credit still causes the divergence.
**Fix:** Net unapplied issued credit-note balances into `outstandingAR` (and surface a `creditOutstanding` line), so the report's net AR matches `outstandingBalance()`.

### M2 — Monthly-billing double-bill backstop (full fix)
**Where:** `MonthlyBillingService::runForPeriod` / `generateForLease` (existence check outside the txn).
**Status:** *Partly mitigated* — `RunMonthlyBilling` now has `WithoutOverlapping` middleware (serialises scheduled/dispatched runs). The synchronous `billing:run-monthly` path + a hard DB backstop are still open.
**Fix:** Add a stored `period_key` (`lease_id, period_year, period_month`) **UNIQUE** column and catch the duplicate-key exception (turn a lost race into a clean "skipped"); and/or a `Cache::lock("billing:{period}")` around `runForPeriod` to cover the sync path too.

---

## LOW

### L1 — `LateFeeService` writes subtotal/total/balance directly, bypassing `Invoice::recomputeTotals()`
**Where:** `app/Services/LateFeeService.php`.
**Problem:** Invariant smell — the single source of truth for invoice money is `recomputeTotals()`; the late-fee path mutates the header columns directly, which is fragile if the derivation changes.
**Fix:** After creating the `late_fee` `InvoiceItem`, bump only the non-derived header amounts then call `$invoice->recomputeTotals()` so balance/status are re-derived. (Currently correct, but brittle.)
