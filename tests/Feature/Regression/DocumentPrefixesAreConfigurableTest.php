<?php

/*
|--------------------------------------------------------------------------
| The letters at the front of every document number (CFG-04)
|--------------------------------------------------------------------------
| `INV-`, `CN-`, `JE-`, `BILL-` and six more were literals inside ten models, so "our invoices are
| numbered TX, not INV" was a deploy. An operator arrives with conventions their auditor already
| knows and their previous system printed for years.
|
| This is the CFG-04 item with a DEADLINE rather than a preference: after go-live the prefix is on
| issued documents that cannot be renumbered, so the window to set it closes with the first invoice.
|
| It also found a collision that had been shipping: PAYROLL and PURCHASE REQUESTS both used
| `PR-{asset}-{YYYYMM}-`. Different tables, so no unique index ever complained — and
| `PR-AW-202603-0007` could be either document with nothing to say which.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payroll;
use App\Settings\AccountingSettings;
use App\Support\DocumentNumbering;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    test()->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function setPrefixes(array $prefixes): void
{
    $settings = app(AccountingSettings::class);
    $settings->document_prefixes = $prefixes;
    $settings->save();
}

it('uses the shipped letters when nothing is configured', function () {
    // The default path, and the reason this is safe to add to a live install: unconfigured must
    // behave exactly as the literals did.
    expect(DocumentNumbering::prefixFor('invoice'))->toBe('INV')
        ->and(DocumentNumbering::prefixFor('credit_note'))->toBe('CN')
        ->and(DocumentNumbering::prefixFor('lease'))->toBe('LSE');
});

it('numbers an invoice with the operator letters', function () {
    // Driven through the model that actually builds the number, not through the registry — a
    // prefix the registry knows and the model ignores would be an inert setting.
    setPrefixes(['invoice' => 'TX']);

    // The reset scheme is stated rather than assumed. This test is about the PREFIX; it read
    // `TX-AW-202603-` only because monthly happened to be the shipped default, and it broke the day
    // EG-10 changed that default to the annual reset the market actually uses.
    setNumberReset(DocumentNumbering::MONTHLY);

    expect(Invoice::numberPrefix('AW', new DateTimeImmutable('2026-03-05')))
        ->toBe('TX-AW-202603-');

    setNumberReset(DocumentNumbering::ANNUAL);

    expect(Invoice::numberPrefix('AW', new DateTimeImmutable('2026-03-05')))
        ->toBe('TX-AW-2026-');
});

it('reaches every document type, not just the one that was checked', function () {
    // Ten models each carried their own literal. Checking the invoice and calling it done is how
    // nine of them stay hardcoded.
    setPrefixes(array_map(fn (array $m) => 'Z'.$m['default'], DocumentNumbering::TYPES));

    foreach (DocumentNumbering::TYPES as $type => $meta) {
        expect(DocumentNumbering::prefixFor($type))
            ->toBe('Z'.$meta['default'], "{$type} did not take its configured prefix");
    }
});

it('no longer lets payroll and purchase requests share one series', function () {
    // The collision that had been shipping. Both were `PR-`; payroll moved to `PAY` because `PR` is
    // the standard procurement abbreviation and purchase requests had five tests asserting it.
    expect(DocumentNumbering::prefixFor('payroll'))->toBe('PAY')
        ->and(DocumentNumbering::prefixFor('purchase_request'))->toBe('PR');
});

it('refuses two document types sharing a prefix', function () {
    // Nothing would ERROR — the unique index is per table — so invoices and credit notes would
    // simply interleave one sequence, and a ledger would read as though documents had gone missing.
    expect(fn () => DocumentNumbering::assertValid(['invoice' => 'DOC', 'credit_note' => 'DOC']))
        ->toThrow(DomainException::class);

    // The control: distinct prefixes are fine, so the refusal is not just "always throws".
    expect(fn () => DocumentNumbering::assertValid(['invoice' => 'TX', 'credit_note' => 'CRN']))
        ->not->toThrow(DomainException::class);
});

it('refuses a prefix that would break the sequence lookup', function () {
    // The prefix is the LOCK KEY and part of the `LIKE` that finds the last number in a series, so
    // a `%` widens the match and a space or dash splits the series in two.
    foreach (['', 'A', 'TOOLONGXX', 'IN V', 'IN-V', 'IN%'] as $bad) {
        $refused = false;

        try {
            DocumentNumbering::assertValid(['invoice' => $bad]);
        } catch (DomainException) {
            $refused = true;
        }

        expect($refused)->toBeTrue("'{$bad}' should be refused as a prefix");
    }
});

it('falls back rather than dying inside a billing run', function () {
    // Numbering happens during document creation. A scheduled billing run must not die part-way
    // through a month because somebody typed a space into a settings field.
    setPrefixes(['invoice' => 'not valid!']);

    expect(DocumentNumbering::prefixFor('invoice'))->toBe('INV');
});

it('does not renumber anything that already exists', function () {
    // The hazard worth stating: numbers are allocated as MAX() WITHIN a prefix, so changing one
    // leaves every existing document untouched and starts a second series. Nothing is corrupted —
    // but the document type now has two series, which is what an auditor will ask about.
    $asset = makeAsset(['code' => 'AW']);
    $lease = makeLease(makeUnit($asset));

    $first = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'issued',
        'issue_date' => '2026-03-05', 'due_date' => '2026-03-12',
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'paid_amount' => 0, 'balance' => 100,
    ]);

    $originalNumber = $first->number;

    setPrefixes(['invoice' => 'TX']);

    expect($first->fresh()->number)->toBe($originalNumber)
        ->and($originalNumber)->toStartWith('INV-');
});

it('names every document type in English and Arabic', function () {
    // The settings screen labels each field from these; an untranslated one reaches production
    // reading "admin.document_types.deposit".
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach (array_keys(DocumentNumbering::TYPES) as $type) {
            if (__("admin.document_types.{$type}") === "admin.document_types.{$type}") {
                $missing[] = "{$type} [{$locale}]";
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], 'Untranslated document types: '.implode(', ', $missing));
})->group('i18n');

it('starts a new lease form from the configured term', function () {
    // The other CFG-04 literal: 36 was hardcoded on the form. It is a leasing convention, not a law
    // — an anchor signs ten years and a kiosk signs one — so every operator's standard differs.
    //
    // Driven through the real create page rather than a bare Schema, which needs a Livewire host —
    // and which is also what an operator actually opens.
    $settings = app(AccountingSettings::class);
    $settings->default_lease_term_months = 60;
    $settings->save();

    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(CreateLease::class)
        ->assertSet('data.term_months', 60);

    Filament::setTenant(null, isQuiet: true);
});

it('never starts a lease at a zero-month term', function () {
    // A mistyped 0 would produce a lease whose expiry is the day before it commences, which Lease's
    // own saving guard then refuses — leaving an operator on a form they cannot submit.
    $settings = app(AccountingSettings::class);
    $settings->default_lease_term_months = 0;
    $settings->save();

    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(CreateLease::class)
        ->assertSet('data.term_months', 1);

    Filament::setTenant(null, isQuiet: true);
});

/*
|--------------------------------------------------------------------------
| When a series starts counting again (EG-10)
|--------------------------------------------------------------------------
|
| Atriom shipped a MONTHLY reset — `INV-AW-202608-0417` — which is a convention nobody chose and
| that no major system uses. SAP, Oracle, NetSuite and Odoo reset accounting document numbers per
| YEAR; Yardi and MRI use continuous control numbers that never reset. Twelve series per mall per
| year is the kind of thing an auditor asks about, and the answer has to be "we chose that".
|
| Like the prefix, this has a DEADLINE rather than a preference: the number is printed on issued
| documents that cannot be renumbered, so the window closes the day the first invoice is sent.
*/

function setNumberReset(string $scheme): void
{
    $settings = app(AccountingSettings::class);
    $settings->document_number_reset = $scheme;
    $settings->save();
}

it('defaults to the CONTINUOUS series Yardi uses, not the monthly one it shipped with', function () {
    // Yardi Voyager and MRI use control numbers that never reset, with the property as a field on
    // the record rather than a segment of the number. Yardi is this project's primary reference, so
    // it is the default; SAP/Oracle/NetSuite/Odoo's annual reset is offered for an operator whose
    // auditor expects a year in the series. Monthly, which Atriom shipped, is used by none of them.
    expect(DocumentNumbering::DEFAULT_RESET)->toBe(DocumentNumbering::NEVER)
        ->and(DocumentNumbering::resetScheme())->toBe(DocumentNumbering::NEVER);
});

it('puts the year, the month or nothing in the number, on the DOCUMENT’s own date', function () {
    $august = new DateTimeImmutable('2026-08-15');

    setNumberReset(DocumentNumbering::ANNUAL);
    expect(Invoice::numberPrefix('AW', $august))->toBe('INV-AW-2026-')
        ->and(JournalEntry::numberPrefix($august))->toBe('JE-2026-');

    setNumberReset(DocumentNumbering::MONTHLY);
    expect(Invoice::numberPrefix('AW', $august))->toBe('INV-AW-202608-')
        ->and(JournalEntry::numberPrefix($august))->toBe('JE-202608-');

    // Yardi's behaviour: a continuous control number with no period in it at all. The separator
    // must not double up — the prefix IS the `LIKE` that finds the last number in the series.
    setNumberReset(DocumentNumbering::NEVER);
    expect(Invoice::numberPrefix('AW', $august))->toBe('INV-AW-')
        ->and(JournalEntry::numberPrefix($august))->toBe('JE-');
});

it('actually numbers a document the configured way, and keeps counting within the series', function () {
    // The prefix method is only half of it — this drives the real allocation, which is what an
    // auditor would be reading.
    setNumberReset(DocumentNumbering::ANNUAL);

    $asset = makeAsset(['code' => 'AW']);
    $tenant = makeTenant(['asset_id' => $asset->id]);
    $lease = makeLease(makeUnit($asset), $tenant);

    $first = makeInvoice($lease, ['issue_date' => '2026-03-01']);
    $second = makeInvoice($lease, ['issue_date' => '2026-08-15']);

    // March and August are ONE series under an annual reset — that is the whole point.
    expect($first->number)->toStartWith('INV-AW-2026-')
        ->and($second->number)->toStartWith('INV-AW-2026-')
        ->and($second->number)->not->toBe($first->number);

    // …and the next year starts again, which is what makes it a reset rather than a label.
    $nextYear = makeInvoice($lease, ['issue_date' => '2027-01-04']);

    expect($nextYear->number)->toStartWith('INV-AW-2027-')
        ->and($nextYear->number)->toEndWith('0001');
});

it('counts a continuous series past its zero-padding', function () {
    // The bug that made Yardi's scheme unsafe to default to. The next number comes from
    // `MAX(number)` within the prefix, which was a STRING sort — so once a series passed `%04d`,
    // `INV-PAD-9999` sorted ABOVE `INV-PAD-10000` and the allocator proposed a number already taken.
    //
    // Asserted on `generateNumber()` rather than by creating invoices, and that is the point: the
    // allocator's collision loop RETRIES on a duplicate, so end to end the bug is invisible — it
    // just costs a query per collision until it exceeds its 100-attempt cap and throws. A test that
    // created two invoices passed with the broken sort restored, which is how this was caught.
    setNumberReset(DocumentNumbering::NEVER);

    $asset = makeAsset(['code' => 'PAD']);
    $lease = makeLease(makeUnit($asset), makeTenant());

    $seed = makeInvoice($lease, ['issue_date' => '2026-03-01']);
    $seed->forceFill(['number' => 'INV-PAD-9999'])->saveQuietly();

    $second = makeInvoice($lease, ['issue_date' => '2026-03-02']);
    $second->forceFill(['number' => 'INV-PAD-10000'])->saveQuietly();

    // With a string sort this answers `INV-PAD-10000` — a number that already exists.
    expect(Invoice::generateNumber('PAD', new DateTimeImmutable('2026-03-03')))
        ->toBe('INV-PAD-10001');
});

it('leaves PAYROLL on the month, because there the month is the run’s identity', function () {
    // A stated exception. A payroll run is per property per MONTH by definition and there is one of
    // them, so `202608` names the run rather than resetting a counter — annualising it would give
    // `PAY-AW-2026-0007` and lose the period the run is for.
    setNumberReset(DocumentNumbering::ANNUAL);

    expect(Payroll::numberPrefix('AW', new DateTimeImmutable('2026-08-15')))->toBe('PAY-AW-202608-');
});

it('falls back rather than throwing when the stored scheme is not one we offer', function () {
    // Numbering runs inside document creation. A hand-edited settings row must not kill a scheduled
    // billing run — the same reasoning the prefix fallback already uses.
    setNumberReset('fortnightly');

    expect(DocumentNumbering::resetScheme())->toBe(DocumentNumbering::NEVER);
});
