<?php

/*
|--------------------------------------------------------------------------
| Val Plaza opens on its first day, with nothing on the books
|--------------------------------------------------------------------------
| The demo is for the people who will RUN Val Plaza, and what they need to see
| is what an action does. `DemoSeeder` seeds a mall mid-life — 33 leases, 290
| invoices, 693 journal entries — which proves the system holds a portfolio and
| is exactly the wrong dataset for that, because every figure on every screen
| was put there by somebody else.
|
| So `ValPlazaSeeder` extends `LearningSeeder`, not `DemoSeeder`: the trial
| balance opens EMPTY, the first lease created in the room is the first lease
| that ever existed, and the entries that appear in the ledger are the ones the
| audience just watched being made.
|
| **The empty half and the ready half are both load-bearing.** Empty books with
| no chart of accounts, no posting map or no open period would bill perfectly
| and post NOTHING — the worst thing to discover mid-demo, and the failure
| `ConfigurationHealth` exists to catch. So this asserts both: nothing on the
| books, and everything needed to put something there.
|
| **A test for an empty dataset passes vacuously by default** — `every()` over
| an empty collection is true, `pluck()->contains()` is false. The first version
| of this file checked the branding by asserting every lease reference started
| `LSE-VP-`, which on an empty mall says nothing at all. It now proves the
| branding by CREATING the first document, which is also the demo itself.
*/

use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Accounting\LedgerPoster;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Settings\TaxSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\LearningSeeder;
use Database\Seeders\ValPlazaSeeder;

it('opens as Val Plaza with nothing on the books', function () {
    $this->seed(ValPlazaSeeder::class);

    expect(Asset::where('code', 'VP')->value('name'))->toBe('Val Plaza')
        ->and(Asset::query()->pluck('name')->contains(fn ($n) => str_contains((string) $n, 'Atriom')))->toBeFalse()
        // Units and tenants exist so there is something to lease, and nothing else does.
        ->and(Unit::count())->toBeGreaterThan(0)
        ->and(Unit::where('status', '!=', 'vacant')->count())->toBe(0)
        ->and(Tenant::count())->toBeGreaterThan(0)
        ->and(Lease::count())->toBe(0)
        ->and(Invoice::count())->toBe(0)
        ->and(JournalEntry::count())->toBe(0);
});

it('is ready to post, so the first invoice reaches the ledger', function () {
    $this->seed(ValPlazaSeeder::class);

    // Empty books are only useful if something can be written into them. Without
    // these the demo bills perfectly and the trial balance never moves.
    expect(LedgerAccount::count())->toBeGreaterThan(0)
        ->and(ChargeCode::count())->toBeGreaterThan(0)
        ->and(AccountingPeriod::where('status', 'open')->count())->toBeGreaterThan(0);
});

/*
| THE DEMO ITSELF — and the only non-vacuous proof of the branding.
*/
it('numbers the first documents for Val Plaza, and the trial balance moves', function () {
    $this->seed(ValPlazaSeeder::class);

    $lease = Lease::create([
        'unit_id' => Unit::where('status', 'vacant')->firstOrFail()->id,
        'tenant_id' => Tenant::firstOrFail()->id,
        'status' => 'active',
        'commencement_date' => '2026-09-01',
        'expiry_date' => '2029-08-31',
        'term_months' => 36,
        'base_rent_monthly' => 90000,
        'service_charge_monthly' => 13500,
    ]);
    LeaseCreationService::seedStandardCharges($lease, rent: 90000, service: 13500);

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-09-01'), false)['invoice'];

    expect($invoice)->not->toBeNull()
        // The mall code is baked in at ALLOCATION time — this is why the estate's
        // identity has to live in the seeder rather than in a rename afterwards.
        ->and($lease->reference)->toStartWith('LSE-VP-')
        ->and($invoice->number)->toStartWith('INV-VP-');

    app(LedgerPoster::class)->sync($invoice->fresh());

    $lines = JournalLine::selectRaw('coalesce(sum(debit),0) d, coalesce(sum(credit),0) c')->first();

    expect((float) $lines->d)->toBeGreaterThan(0.0)
        ->and((float) $lines->d)->toBe((float) $lines->c);
});

/*
| CONTROL — the teaching seeder is untouched. Without this, "Val Plaza works"
| could be satisfied by renaming the estate for everybody.
*/
it('leaves the default empty mall as Atriom Walk', function () {
    $this->seed(LearningSeeder::class);

    expect(Asset::where('code', 'AW')->value('name'))->toBe('Atriom Walk');
});

it('does not invent a tax registration', function () {
    $this->seed(ValPlazaSeeder::class);

    expect(app(TaxSettings::class)->seller_tax_registration_number)->toBeEmpty();
});
