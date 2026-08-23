<?php

namespace App\Support;

use App\Settings\AccountingSettings;
use Carbon\Carbon;
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
        // PTW is the universal abbreviation for a permit to work; anyone in facilities reads it
        // without being told, which matters on a document a contractor is handed at a gate.
        'work_permit' => ['default' => 'PTW', 'label' => 'Permit to work'],

        // ── Counterparty codes ────────────────────────────────────────────────────────────────
        //
        // Not documents, and included anyway. A tenant code and a vendor code are read aloud,
        // quoted in emails and typed into this system's search boxes exactly like a document
        // number is, they are allocated by the same MAX-within-a-prefix rule under the same lock,
        // and an operator arriving from Yardi has their own tenant coding they will want to keep.
        // Every argument for making `INV` configurable applies unchanged.
        //
        // They also inherit the collision rule, which matters more here than it looks: a tenant
        // and a vendor are both "the other party" on screens that show them side by side, and one
        // shared prefix would make `XX-000318` ambiguous to the only people who read it.
        'tenant' => ['default' => 'TN', 'label' => 'Tenant code'],
        'vendor' => ['default' => 'VN', 'label' => 'Supplier code'],
    ];

    /**
     * A prefix is letters and digits, 2–6 of them.
     *
     * Not cosmetic: the prefix is the LOCK KEY that serialises number allocation and part of the
     * `LIKE` used to find the last number in a series, so a `%`, a `-` or a space would either
     * widen that match or split the series in two.
     */
    public const PATTERN = '/^[A-Z0-9]{2,6}$/';

    /**
     * When a document series starts counting again — the other half of EG-10.
     *
     * Atriom shipped a MONTHLY reset (`INV-AW-202608-0417`), which is a convention nobody chose and
     * that **no major system uses**. The market splits two ways and neither is monthly:
     *
     * - **SAP, Oracle, NetSuite and Odoo** reset accounting document numbers per YEAR. Odoo's
     *   sequences are the closest analogue to this one — a prefix with a date range and a counter.
     * - **Yardi Voyager and MRI** use continuous control numbers that never reset; the property or
     *   entity is a field on the record rather than a segment of the number.
     *
     * So `ANNUAL` is the default, and the scheme is CONFIGURABLE because every one of those systems
     * treats a number range as configuration rather than as code.
     *
     * ## The reset is on the DOCUMENT's own date, and it is a calendar year
     *
     * SAP resets per FISCAL year. That is deliberately not copied: this system already lets a
     * property run an April→March year, and a March-2027 invoice numbered `…-2026-…` reads as a
     * mistake to everyone who is not an accountant. An operator whose fiscal year is not the
     * calendar year should choose {@see NEVER}, which is Yardi's behaviour and has no year in the
     * number to disagree with.
     *
     * ## Changing it after go-live does the same thing changing a prefix does
     *
     * Numbers are allocated as `MAX(number)` within a prefix, so a new scheme means a new prefix
     * shape and a new sequence starting at 1 — the old documents are untouched. Allowed for the
     * same reason, surfaced the same way, and it is exactly why this row has a deadline rather than
     * a preference.
     */
    public const NEVER = 'never';

    public const ANNUAL = 'annual';

    public const MONTHLY = 'monthly';

    /** @var array<int, string> */
    public const RESET_SCHEMES = [self::NEVER, self::ANNUAL, self::MONTHLY];

    /** The market default — see {@see RESET_SCHEMES}'s docblock for why it is not monthly. */
    public const DEFAULT_RESET = self::ANNUAL;

    /** How this install numbers its series. */
    public static function resetScheme(): string
    {
        $configured = app(AccountingSettings::class)->document_number_reset;

        // A value the settings screen cannot produce falls back rather than throwing, for the same
        // reason a mistyped prefix does: numbering runs inside document creation, and a scheduled
        // billing run must not die because a settings row was hand-edited.
        return in_array($configured, self::RESET_SCHEMES, true) ? $configured : self::DEFAULT_RESET;
    }

    /**
     * The period segment of a document number, including its trailing separator.
     *
     * `''` · `'2026-'` · `'202608-'`. Returned WITH the dash so a caller composing a prefix does
     * not have to know whether the segment is present — the `never` scheme would otherwise leave a
     * double dash, which changes the `LIKE` that finds the last number in the series.
     */
    public static function periodSegment(?\DateTimeInterface $date = null): string
    {
        $date = $date ? Carbon::instance($date) : Carbon::now();

        return match (self::resetScheme()) {
            self::NEVER => '',
            self::MONTHLY => $date->format('Ym').'-',
            default => $date->format('Y').'-',
        };
    }

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
