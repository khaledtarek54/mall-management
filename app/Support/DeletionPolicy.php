<?php

namespace App\Support;

/**
 * What may be deleted, and what must be corrected instead.
 *
 * **The decision (operator, 2026-07-31).** A financial document is not a row you remove — it is a
 * record of something that happened. Under Egyptian bookkeeping (and ETA e-invoicing once live) an
 * issued invoice is a legal record: you cancel it or credit-note it, and the trail survives. This
 * system already speaks that language — cancel, void, credit note, reverse, un-apply — and every
 * one of those leaves a document explaining the change. Delete was a fifth path that bypassed all
 * of them.
 *
 * **What it looked like before.** `Invoice`, `Payment` and `JournalEntry` had no deletion guard at
 * all (only `CreditNote` and `MeterReading` did), and every one of the eight money resources
 * offered a Delete button on its Edit page. Soft deletes meant nothing was destroyed — but a
 * super_admin could delete a PAID invoice and the tenant's AR would simply change, with no
 * cancellation, no reason, and nothing to show an auditor. The money moved and the paperwork
 * didn't.
 *
 * **The tiers.**
 *
 * - `NEVER` — money and audit records. No Delete button, no `.delete` permission, and a model-level
 *   guard as the backstop. Correct via the document's own correction path.
 * - `WHEN_UNUSED` — master data that history can point at. Deletable only while nothing references
 *   it; deactivate instead once it has been used. *(Tier B — pending the operator's call on
 *   refuse-vs-warn, so nothing is enforced for it yet.)*
 * - `ALLOWED` — records with no financial footprint. Still super_admin only, still soft-deleted.
 *
 * `DeletionPolicyConformanceTest` fails the build when a NEVER model's resource ships a Delete
 * action or its permission reappears — the hand-maintained delete test it replaces covered 10 of
 * 41 resources, which is how a gap this size stayed invisible.
 */
class DeletionPolicy
{
    public const NEVER = 'never';

    public const WHEN_UNUSED = 'when_unused';

    public const ALLOWED = 'allowed';

    /**
     * Money and audit records: never deletable, with the correction path that replaces it.
     *
     * @var array<class-string, string>
     */
    public const NEVER_DELETABLE = [
        \App\Models\Invoice::class => 'cancel the invoice, or issue a credit note',
        \App\Models\Payment::class => 'void the payment (VoidPaymentService) — it reverses the GL and re-opens the invoice',
        \App\Models\JournalEntry::class => 'post a reversing entry; a posted entry is never removed',
        \App\Models\CreditNote::class => 'cancel the note — it un-applies against the original invoice',
        \App\Models\VendorBill::class => 'cancel the bill',
        \App\Models\Expense::class => 'cancel the expense',
        \App\Models\DepositTransaction::class => 'reverse the deposit transaction',
        \App\Models\Payroll::class => 'cancel the run — payslips and their GL entries follow it',
        \App\Models\PostDatedCheque::class => 'cancel or bounce the cheque',
    ];

    /** Is this model one the books depend on? */
    public static function isNeverDeletable(string $model): bool
    {
        return array_key_exists($model, self::NEVER_DELETABLE);
    }

    /** How the operator is told to correct this record instead of deleting it. */
    public static function correctionFor(string $model): ?string
    {
        return self::NEVER_DELETABLE[$model] ?? null;
    }

    /**
     * The `{module}.delete` permissions that must no longer exist.
     *
     * Removing the Delete button is the UI half; leaving the permission seeded would let a role be
     * granted a right nothing can exercise, and would quietly restore the button the moment
     * someone re-added the action.
     *
     * @var array<int, string>
     */
    public const RETIRED_PERMISSIONS = [
        'invoices.delete',
        'payments.delete',
        'journal_entries.delete',
        'credit_notes.delete',
        'vendor_bills.delete',
        'expenses.delete',
        'deposit_transactions.delete',
        'payrolls.delete',
        'post_dated_cheques.delete',
    ];
}
