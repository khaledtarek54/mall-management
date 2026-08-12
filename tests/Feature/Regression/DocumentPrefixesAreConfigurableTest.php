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

use App\Support\DocumentNumbering;
use App\Settings\AccountingSettings;
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

    expect(App\Models\Invoice::numberPrefix('AW', new DateTimeImmutable('2026-03-05')))
        ->toBe('TX-AW-202603-');
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

    $first = App\Models\Invoice::create([
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

    Livewire::test(App\Filament\Admin\Resources\Leases\Pages\CreateLease::class)
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

    Livewire::test(App\Filament\Admin\Resources\Leases\Pages\CreateLease::class)
        ->assertSet('data.term_months', 1);

    Filament::setTenant(null, isQuiet: true);
});
