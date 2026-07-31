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
 *   it; once it has been used, deletion is REFUSED and the operator is pointed at deactivation.
 *   Operator's call 2026-07-31, matching Yardi/MRI/Entrata: none of them offer
 *   "delete anyway with a warning", because the damage lands on the reports and audit trail that
 *   referenced the record, not on the record itself.
 * - Everything else keeps the standard gate: super_admin only, soft-deleted, bulk-delete off.
 *
 * `DeletionPolicyConformanceTest` fails the build when a NEVER model's resource ships a Delete
 * action or its permission reappears — the hand-maintained delete test it replaces covered 10 of
 * 41 resources, which is how a gap this size stayed invisible.
 */
class DeletionPolicy
{
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


    /**
     * Master data: deletable only while nothing in the system points at it.
     *
     * **The industry standard, and why.** Yardi, MRI and Entrata all refuse to remove a record
     * that carries history — a tenant with ledger activity becomes *Past*, a vendor goes on
     * *Hold*, a unit goes *Down*. None of them offer "delete anyway with a warning", because the
     * damage is not to the record you deleted: it is to every report, statement and audit trail
     * that referenced it. A warning dialog puts that decision on whoever is clicking fastest.
     *
     * So the rule is **refuse, and say what to do instead**. A record with no history is a genuine
     * mistake — a vendor typed twice, a unit created in error — and stays deletable, because
     * nothing is lost and the alternative is an operator whose lists fill with rubbish they cannot
     * clear.
     *
     * `blocked_by` names the relations that constitute history. They are checked against the model
     * at build time by `DeletionPolicyConformanceTest` — a mistyped relation would silently block
     * nothing, which is the failure mode this whole registry exists to avoid.
     *
     * @var array<class-string, array{blocked_by: array<int, string>, instead: string}>
     */
    public const WHEN_UNUSED = [
        \App\Models\Tenant::class => [
            'blocked_by' => ['leases', 'invoices', 'payments', 'creditNotes', 'salesDeclarations', 'maintenanceRequests'],
            'instead' => 'set the tenant to inactive — the history stays queryable and the AR still ties out',
        ],
        \App\Models\Vendor::class => [
            'blocked_by' => ['bills', 'contracts', 'maintenanceRequests', 'documents'],
            'instead' => 'set the vendor to inactive (or blacklisted) — it disappears from every assignment picker without losing its bills',
        ],
        \App\Models\Lease::class => [
            'blocked_by' => ['invoices', 'charges', 'salesDeclarations', 'camAllocations', 'maintenanceRequests', 'renewals'],
            'instead' => 'terminate the lease — that is the documented end of a tenancy, and it keeps the billing history',
        ],
        \App\Models\Unit::class => [
            // allLeases, NOT leases: a multi-unit lease keeps its extra units in the lease_unit
            // pivot, so the master-only relation would report a leased unit as never used.
            'blocked_by' => ['allLeases', 'maintenanceRequests', 'utilityMeters'],
            'instead' => 'set the unit to maintenance if it is out of service — a unit that has been leased is part of the property record',
        ],
        \App\Models\Asset::class => [
            'blocked_by' => ['units', 'leases', 'camPools', 'utilityMeters'],
            'instead' => 'deactivate the property — deleting one would orphan every book that reports on it',
        ],
        \App\Models\Employee::class => [
            'blocked_by' => ['payrollLines', 'advances', 'custodies'],
            'instead' => 'set the employee inactive — payroll history is a statutory record',
        ],
    ];

    /**
     * DELIBERATELY NOT LISTED: UtilityMeter.
     *
     * Soft-deleting a meter is already this product's retirement mechanism, and it works — the
     * module-10 close-out fixed the energy trend to exclude soft-deleted meters, and the readings
     * survive because they are what past recharges were billed from. Adding it here would have
     * overridden a deliberate, tested design with a generic rule; the existing scenario test caught
     * that, which is the only reason this note exists rather than a regression.
     *
     * The distinction the tier is actually drawing: does removing the record HIDE history that
     * reports must still show (tenant, lease, unit, property), or is it a supported retirement the
     * system already handles end-to-end (meter)?
     */

    /** Is this model deletable only while unreferenced? */
    public static function isDeletableWhenUnused(string $model): bool
    {
        return array_key_exists($model, self::WHEN_UNUSED);
    }

    /** The relations that constitute history for this model. */
    public static function blockingRelationsFor(string $model): array
    {
        return self::WHEN_UNUSED[$model]['blocked_by'] ?? [];
    }

    /** What the operator should do instead of deleting. */
    public static function insteadFor(string $model): ?string
    {
        return self::WHEN_UNUSED[$model]['instead'] ?? null;
    }

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
