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

    /**
     * Every schema the scan flags as a candidate, and what was decided about it.
     *
     * The verdict vocabulary is deliberately narrow, because the interesting distinction is between
     * "handled" and "there is nothing to handle" — and the second is the one that rots:
     *
     *   - `DERIVES`   — it computes the relationship; the note says where.
     *   - `NO_TARGET` — there is a start and a duration but **no field to derive into**. Nothing to
     *                   do until somebody adds one, at which point this entry stops being true and
     *                   the gate is the thing that notices.
     *   - `INDEPENDENT` — both dates are separately-observed facts and neither computes the other.
     *
     * This table is what retired DF-05. That row claimed four remaining derivable pairs and every
     * one was false: fixed-asset depreciation end and vendor-contract term have no field to derive
     * into, PDC maturity IS the typed date rather than a derivation of it, and work-order SLA due is
     * computed by `SlaResolver` and never typed at all. The row had been carried as outstanding work
     * on the strength of a plausible guess.
     *
     * @var array<class-string|string, array{verdict: string, note: string}>
     */
    public const CANDIDATE_VERDICTS = [
        'app/Filament/Admin/Resources/RecurringExpenses/Schemas/RecurringExpenseForm.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'starts_on + ends_on on a recurring cost schedule (EG-33). A lease insurance premium, a real-estate tax assessment or a cleaning retainer starts on a date and runs until something ends it — a policy is not renewed, a contract lapses, the levy is abolished. There is no duration and there must not be one: `ends_on` is nullable and blank is the normal state, meaning "until further notice", which is what an open-ended standing cost actually is. Deriving an end from the frequency would invent a stop date nobody agreed and silently switch the run off. Same verdict and same reasoning as the charge-schedule rows this mirrors.',
        ],
        'app/Filament/Admin/RelationManagers/UnitOwnershipChargesRelationManager.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'A recurring assessment (\u{635}\u{64a}\u{627}\u{646}\u{629}) is OPENED on one date and CLOSED on another, and the two live in two different actions — you add a row, and months or years later you end it. There is no duration field and there must not be one: an assessment runs until something ends it (a resale, a re-rate, the owner\'s handover reversed), none of which is knowable on the day it starts. The lease charge schedule next door works the same way — `ChargeScheduleService::close()` closes-and-opens a dated rung rather than projecting an end — and this is the same registry of rows read by the same billing run.',
        ],
        'app/Filament/Admin/Resources/UnitOwnerships/Schemas/UnitOwnershipForm.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'started_at + ended_at on a unit ownership. The end of a tenure is a fact recorded when the unit is resold, never a projection from the purchase — a freehold has no term at all, and deriving an end date would invent one. Same verdict, and the same reasoning, as the property-ownership tenure next door.',
        ],
        'app/Filament/Imports/UnitOwnershipImporter.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'The bulk path for the same tenure, and the same verdict as the form: `started_at` opens it, `ended_at` records the resale that closed it, and there is no term between them to derive either from. A migrating operator loading history has both dates as FACTS off the deeds, which is precisely the case where projecting one from the other would overwrite what the file says.',
        ],
        'app/Filament/Admin/RelationManagers/AssetOwnersRelationManager.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'An ownership tenure starts when it starts and ends when it ends — a sale is not scheduled from the purchase. There is no duration field and there should not be one.',
        ],
        'app/Filament/Admin/Resources/FixedAssets/Schemas/FixedAssetForm.php' => [
            'verdict' => 'NO_TARGET',
            'note' => 'acquisition_date + useful_life_months with NO end column on fixed_assets. The depreciation schedule is computed by the posting service month by month, and `disposed_on` is a fact rather than a projection — storing a derived end date would be a second truth about when the asset stops depreciating.',
        ],
        'app/Filament/Imports/FixedAssetImporter.php' => [
            'verdict' => 'NO_TARGET',
            'note' => 'The bulk path for the same fields, and the same absence of a target.',
        ],
        'app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php' => [
            'verdict' => 'DERIVES',
            'note' => 'What is left here after the record actions moved to LeaseActions (2026-08-17): the Quick new lease wizard, which asks commencement + term_months and never an expiry — `LeaseCreationService` derives the stored expiry through `LeaseTerm`, so there is no second field to disagree with it.',
        ],
        'app/Filament/Admin/Actions/LeaseActions.php' => [
            'verdict' => 'DERIVES',
            'note' => 'Two actions, both deriving. RENEW: `new_expiry_preview` is a live Placeholder over `LeaseTerm::expiryFrom(commencement, new_term_months)` and `LeaseRenewalService` derives the stored value from the same two — read-only, because there is nothing for the operator to type. EXTEND TERM: the operator states the new expiry alone and `LeaseExtensionService` re-derives `term_months` from it via `LeaseTerm::monthsSpanning`, which is the same rule the lease form applies in reverse — a further term is negotiated to a DATE (a financial year end, the neighbour\'s fit-out), so the date is the fact and the month count describes it.',
        ],
        'app/Filament/Admin/Resources/PostDatedCheques/Pages/ListPostDatedCheques.php' => [
            'verdict' => 'DERIVES',
            'note' => 'Bulk series lodging: first cheque_date + interval_months derives the whole series of maturity dates. A cheque\'s own maturity is the typed fact, not a derivation — which is why this is the only PDC surface here.',
        ],
        'app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php' => [
            'verdict' => 'DERIVES',
            'note' => '`notice_deadline` = end_date − notice_period_days, derived in `VendorContract`\'s saving hook and rendered read-only. Model layer rather than form, so every writer gets it — missing that window auto-renews a contract the operator meant to end. `vendor_contracts` has no term column, so start/end is not itself a derivable pair.',
        ],
        'app/Filament/Admin/Resources/Vendors/RelationManagers/DocumentsRelationManager.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'A certificate is issued on one date and expires on another, both printed on the document. There is no validity-period field, and inventing one would let us compute an expiry that contradicts the paper.',
        ],
        'app/Filament/Admin/Resources/Tenants/RelationManagers/DocumentsRelationManager.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'As the vendor documents above — transcribed from the certificate, not computed.',
        ],
        'app/Filament/Admin/Resources/MarketingPosts/Schemas/MarketingPostForm.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'A campaign\'s run dates are chosen, not derived; there is no duration field. (Module 36 has TWO date pairs — valid vs shown — and neither computes the other.)',
        ],
        'app/Filament/Portal/Resources/MarketingPosts/Schemas/MarketingPostForm.php' => [
            'verdict' => 'INDEPENDENT',
            'note' => 'The portal half of the same form, and the same answer: a retailer picks the dates their offer runs between, and there is no duration field for either one to be computed from.',
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

    /**
     * Schemas that LOOK like they carry a derivable relationship, whether or not a group names one.
     *
     * {@see GROUPS} only finds what it already knows about, so it can never answer "is there
     * anything left?" — it answers "are the two things I listed still handled?". That gap is not
     * hypothetical: DF-05 sat on the roadmap claiming four remaining pairs (fixed-asset depreciation
     * end, vendor-contract term, PDC maturity, work-order SLA due), and **all four were false** —
     * two have no field to derive INTO, one is already derived in a model hook, one is not a form
     * field at all. Nobody could have known that without scanning, and the answer goes stale the
     * next time somebody adds a form.
     *
     * So this scans instead of listing: any schema exposing a start-ish date AND either an end-ish
     * date or a duration is a CANDIDATE, and the gate makes every candidate carry a verdict. A new
     * form that ships a start + term + end triple with no derivation now fails the build rather than
     * waiting to be noticed.
     *
     * Deliberately over-inclusive. A false candidate costs one line of classification; a missed one
     * costs the operator a field they have to keep in their head.
     *
     * @return array<string, array{start: array<int, string>, end: array<int, string>, duration: array<int, string>}>
     */
    public static function candidatesIn(string $source): array
    {
        preg_match_all(
            '/(?:TextInput|DatePicker|DateTimePicker|Select|ImportColumn)::make\(\s*[\'"]([a-z_]+)[\'"]/',
            $source,
            $matches,
        );

        $fields = array_values(array_unique($matches[1] ?? []));
        $dates = array_values(array_filter($fields, fn (string $f) => preg_match('/(_date|_at|_on)$/', $f)));

        $start = array_values(array_filter($dates, fn (string $f) => preg_match(self::START_WORDS, $f)));
        $end = array_values(array_filter($dates, fn (string $f) => preg_match(self::END_WORDS, $f)));
        $duration = array_values(array_filter($fields, fn (string $f) => preg_match(self::DURATION_WORDS, $f)));

        if ($start === [] || ($end === [] && $duration === [])) {
            return [];
        }

        return compact('start', 'end', 'duration');
    }

    private const START_WORDS = '/(start|commence|acquisition|issue|received|from|open)/';

    private const END_WORDS = '/(end|expiry|expires|due|maturity|close|to)/';

    private const DURATION_WORDS = '/(months|years|days|hours|life|duration|term)/';
}
