<?php

namespace App\Support;

use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Admin\Resources\Leases\Schemas\LeaseForm;
use App\Filament\Admin\Resources\VendorBills\Schemas\VendorBillForm;
use App\Filament\Imports\LeaseImporter;
use App\Filament\Imports\OpeningInvoiceImporter;

/**
 * Which operator-typed fields must be COMPUTED from their neighbours — the registry behind
 * `DerivedFieldsConformanceTest`.
 *
 * The pattern this protects: a field an operator can work out from two others should be filled in
 * for them, stay editable, and — where the relationship runs both ways — recompute its partner
 * rather than contradicting it. Yardi and MRI both behave this way, and the alternative is what
 * this system shipped until 2026-08-12: `commencement_date`, `term_months` and `expiry_date` as
 * three independent inputs, so a lease could be saved as "36 months" spanning twelve.
 *
 * **Wiring one form fixes one form.** The gate exists because the next person adding a screen with
 * the same field names will not know this file exists, and their form will look finished. So a
 * schema that exposes every field of a registered GROUP as an INPUT must be listed here as either
 * derived or exempt — an unclassified one fails the build, exactly as an unclassified model does in
 * `PropertyIsolation` and an unclassified resource in `SearchPolicy`.
 *
 * **What the gate does NOT do is prove the derivation works.** That is behaviour, and behaviour is
 * proved by driving the real form: `DerivedDateFieldsTest` for the lease term and the invoice due
 * date, `LeaseImportExecutesTest` for the bulk path. Each entry names its test, and the gate checks
 * that file exists — so "this is covered" cannot become a claim nobody kept.
 */
class DerivedFields
{
    /**
     * Field groups that carry a derivable relationship, and the vocabulary the scan looks for.
     *
     * A group matches a schema only when EVERY one of its fields appears there as a form input. Two
     * of three is a table column list or a filter row, not a place an operator can create the
     * disagreement.
     *
     * @var array<string, array{fields: array<int, string>, rule: string}>
     */
    public const GROUPS = [
        'lease_term' => [
            'fields' => ['commencement_date', 'term_months', 'expiry_date'],
            'rule' => 'expiry = commencement + term − 1 day, month ends clamped (App\Support\LeaseTerm)',
        ],
        'invoice_due' => [
            'fields' => ['issue_date', 'due_date'],
            'rule' => 'due = issue + the lease\'s payment_terms_days',
        ],
    ];

    /**
     * Schemas that expose a group and DO derive it, with the test that proves the behaviour.
     *
     * @var array<class-string, array<string, array{group: string, test: string, note: string}>>
     */
    public const DERIVED = [
        LeaseForm::class => [
            'lease_term' => [
                'group' => 'lease_term',
                'test' => 'tests/Feature/Regression/DerivedDateFieldsTest.php',
                'note' => 'Bidirectional: commencement or term recomputes the expiry; a typed expiry recomputes the term.',
            ],
        ],
        LeaseImporter::class => [
            'lease_term' => [
                'group' => 'lease_term',
                'test' => 'tests/Feature/Regression/LeaseImportExecutesTest.php',
                'note' => 'The bulk path. Derives whichever of term/expiry the CSV omits, and REFUSES a row where both are present and disagree — neither can be preferred, because the expiry is a contract date and the term describes it.',
            ],
        ],
        InvoiceForm::class => [
            'invoice_due' => [
                'group' => 'invoice_due',
                'test' => 'tests/Feature/Regression/DerivedDateFieldsTest.php',
                'note' => 'One-way: the due date follows the issue date and the lease terms, and stays editable. There is nothing to back-derive — a due date implies no issue date.',
            ],
        ],
    ];

    /**
     * Schemas that expose a group and deliberately do NOT derive it.
     *
     * A reason is mandatory, and "we did not get to it" is not one — that is a roadmap row, not an
     * exemption.
     *
     * @var array<class-string, array<string, string>>
     */
    public const EXEMPT = [
        VendorBillForm::class => [
            'invoice_due' => 'A supplier states their own payment terms on their own document. Deriving a due date from OUR terms would overwrite what the bill says and quietly change when we are in default. The dates here are transcribed, not computed.',
        ],
        OpeningInvoiceImporter::class => [
            'invoice_due' => 'A cutover import carries the dates the PREVIOUS system issued the invoice under. Re-deriving them would restate the ageing of every migrated receivable, which is the one thing an opening balance must not do.',
        ],
    ];

    /** @return array<int, string> every field name any group mentions */
    public static function vocabulary(): array
    {
        return collect(self::GROUPS)->pluck('fields')->flatten()->unique()->values()->all();
    }

    /** @return array<int, string> the groups this source file exposes as form INPUTS */
    public static function groupsExposedBy(string $source): array
    {
        // Inputs only. A `TextColumn`/`TextEntry`/`ExportColumn` naming the same field is a
        // read-only rendering of a value someone else computed — there is no disagreement an
        // operator can create there, and treating one as a form field would make the registry a
        // list of every table in the app.
        $inputs = [];
        preg_match_all(
            '/(?:TextInput|DatePicker|DateTimePicker|Select|ImportColumn)::make\(\s*[\'"]([a-z_]+)[\'"]/',
            $source,
            $matches,
        );
        $inputs = array_unique($matches[1] ?? []);

        return collect(self::GROUPS)
            ->filter(fn (array $group) => empty(array_diff($group['fields'], $inputs)))
            ->keys()
            ->all();
    }
}
