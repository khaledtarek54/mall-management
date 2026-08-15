<?php

namespace App\Support;

use App\Settings\AccountingSettings;
use DomainException;

/**
 * The letters at the front of every document number (CFG-04).
 *
 * `INV-`, `CN-`, `JE-`, `BILL-` and five more were literals inside nine models. An operator arrives
 * with document conventions they already use — their auditor knows them, their tenants recognise
 * them on an invoice, and their previous system printed them for years — and "our invoices are
 * numbered TX, not INV" was a deploy.
 *
 * This is the item on CFG-04's tail with a **deadline** rather than a preference. After go-live the
 * prefix is printed on issued documents that cannot be renumbered, so the window to set it closes
 * the day the first invoice is sent.
 *
 * ## Changing one does not renumber anything, and that is the hazard
 *
 * Numbers are allocated as `MAX(number)` **within a prefix**, so changing `INV-` to `TX-` leaves
 * every existing invoice exactly as it is and starts a new sequence at 1. Nothing is corrupted —
 * which is precisely why it needs saying out loud: the document type now has **two series**, and an
 * Egyptian tax invoice series is expected to run continuously. An auditor reading a jump from
 * INV-…-0417 to TX-…-0001 will ask about it.
 *
 * It is allowed anyway, because refusing would block a legitimate need (a new legal entity, a
 * restructured ETA registration) and because the operator, not this class, is the one accountable
 * for their numbering. `ConfigurationHealth` surfaces it instead — advisory, not blocking.
 *
 * ## What is refused
 *
 * Two document types sharing a prefix. The UNIQUE index on `number` is per TABLE, so nothing would
 * error — invoices and credit notes would simply interleave one sequence and look, to anyone
 * reading a ledger, like documents that had gone missing.
 */
class DocumentNumbering
{
    /**
     * Every numbered document, its shipped prefix, and what the number is for.
     *
     * The KEY is a stable identifier stored in settings; the prefix is what the operator may change.
     * Renaming a key would orphan an operator's configured value, so it is the one part of this
     * table that must not move.
     *
     * @var array<string, array{default: string, label: string}>
     */
    public const TYPES = [
        'invoice' => ['default' => 'INV', 'label' => 'Tax invoice'],
        'credit_note' => ['default' => 'CN', 'label' => 'Credit note'],
        'journal_entry' => ['default' => 'JE', 'label' => 'Journal entry'],
        'vendor_bill' => ['default' => 'BILL', 'label' => 'Supplier bill'],
        'expense' => ['default' => 'EXP', 'label' => 'Expense'],
        'deposit' => ['default' => 'DEP', 'label' => 'Security deposit movement'],
        // PAY, not PR. Payroll and purchase requests BOTH shipped `PR-{asset}-{YYYYMM}-` — the
        // same scheme on two document types, so `PR-AW-202603-0007` could be either and nothing
        // said which. Different tables, so no unique index ever complained. Payroll moved because
        // `PR` is the standard procurement abbreviation and purchase requests had five tests
        // asserting it; payroll had none. Free to fix now and not after go-live, which is the whole
        // reason this row had a deadline.
        'payroll' => ['default' => 'PAY', 'label' => 'Payroll run'],
        'purchase_request' => ['default' => 'PR', 'label' => 'Purchase request'],
        'lease' => ['default' => 'LSE', 'label' => 'Lease'],
        'post_dated_cheque' => ['default' => 'PDC', 'label' => 'Post-dated cheque'],
        // UO, not OWN: the series identifies the OWNERSHIP agreement over one unit, which is the
        // peer of a lease (LSE) and not of the property-owner records module 32 apportions to.
        'unit_ownership' => ['default' => 'UO', 'label' => 'Unit ownership'],
    ];

    /**
     * A prefix is letters and digits, 2–6 of them.
     *
     * Not cosmetic: the prefix is the LOCK KEY that serialises number allocation and part of the
     * `LIKE` used to find the last number in a series, so a `%`, a `-` or a space would either
     * widen that match or split the series in two.
     */
    public const PATTERN = '/^[A-Z0-9]{2,6}$/';

    /** The configured prefix for a document type, or the one it ships with. */
    public static function prefixFor(string $type): string
    {
        $configured = self::configured()[$type] ?? null;

        if (is_string($configured) && preg_match(self::PATTERN, $configured)) {
            return $configured;
        }

        // A mistyped prefix falls back rather than throwing. Numbering runs inside document
        // creation — a scheduled billing run must not die because somebody typed a space.
        return self::TYPES[$type]['default'] ?? 'DOC';
    }

    /** @return array<string, string> */
    public static function configured(): array
    {
        $stored = app(AccountingSettings::class)->document_prefixes;

        return is_array($stored) ? $stored : [];
    }

    /**
     * Refuse a set that would make two document types share a series.
     *
     * The UNIQUE index on `number` is per TABLE, so nothing errors — invoices and credit notes just
     * interleave one sequence, and a ledger reads as though documents had gone missing.
     *
     * @param  array<string, string>  $prefixes
     */
    public static function assertValid(array $prefixes): void
    {
        $resolved = [];

        foreach (array_keys(self::TYPES) as $type) {
            $prefix = strtoupper(trim((string) ($prefixes[$type] ?? self::TYPES[$type]['default'])));

            if (! preg_match(self::PATTERN, $prefix)) {
                throw new DomainException(__('admin.errors.document_prefix_invalid', ['prefix' => $prefix]));
            }

            if (in_array($prefix, $resolved, true)) {
                throw new DomainException(__('admin.errors.document_prefix_duplicated', ['prefix' => $prefix]));
            }

            $resolved[$type] = $prefix;
        }
    }

    /** Document types whose prefix has been changed away from the shipped default. */
    public static function changed(): array
    {
        return collect(self::TYPES)
            ->filter(fn (array $meta, string $type) => self::prefixFor($type) !== $meta['default'])
            ->keys()
            ->all();
    }
}
