<?php

/*
|--------------------------------------------------------------------------
| The catalogue's promise reaches RECURRING billing
|--------------------------------------------------------------------------
| `charge_codes` was built so an accountant could add a code — key money, a chiller charge, a
| signage fee — without a deploy. `invoice_items.type` was freed for that in June; `charges.type`
| was left an enum, so their code could be billed as a ONE-OFF invoice line and could not be set up
| as a recurring lease charge: the database rejected it. The promise stopped at recurring billing,
| which is most of the money (validation sweep §9 L7).
|
| Freeing the column is only half — the other half is that nothing on any screen could add a charge
| of any type. Rent, service charge, the levy and parking each arrive from their own service, and
| everything else had no way onto a lease at all.
|
| So this walks the whole path the accountant's code now has: catalogue row → the lease's schedule
| screen → the monthly run → the ledger, under the account THEY chose.
*/

use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\ChargeScheduleService;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChargeCodeSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Filament::setTenant(null, isQuiet: true);
});

/** The code an accountant adds on a Tuesday, with no developer involved. */
function chillerCode(): ChargeCode
{
    return ChargeCode::create([
        'code' => 'chiller_charge',
        'name_en' => 'Chiller charge',
        'name_ar' => 'رسوم التبريد',
        // Their choice of account, from the posting map — the same field the seeded codes use.
        'posting_role' => 'service_charge_revenue',
        'tax_code' => 'VAT_14',
        'sort_order' => 200,
    ]);
}

function chillerLease(): Lease
{
    $asset = makeAsset();
    Filament::setTenant($asset);

    $lease = makeLease(makeUnit($asset, ['code' => 'C-01']), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2029-12-31',
        'base_rent_monthly' => 30000,
    ])->fresh();

    // A rent row, because `makeLease` writes none: without it a month with no chiller charge has
    // nothing at all to bill, and "the charge stopped" would pass on an empty invoice run.
    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 30000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => Vat::rateForType('base_rent'),
        'start_date' => '2026-01-01',
        'is_active' => true,
    ]);

    return $lease;
}

it('puts an accountant-added code on a lease from the schedule screen, and bills it', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    chillerCode();
    $lease = chillerLease();

    Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->callAction(TestAction::make('addCharge')->table(), data: [
        'type' => 'chiller_charge',
        'amount' => 2500,
        'frequency' => 'monthly',
        'effective_from' => '2026-03-01',
        'vat_rate' => Vat::rateForType('chiller_charge'),
    ]);

    $charge = $lease->charges()->where('type', 'chiller_charge')->sole();

    expect((float) $charge->amount)->toBe(2500.0)
        // The catalogue's VAT treatment, not a hard-coded rate and not zero.
        ->and((float) $charge->vat_rate)->toBe(Vat::standardRate())
        ->and($charge->vat_applicable)->toBeTrue()
        // Added in March, so it is owed from March — not back to the January commencement.
        ->and($charge->start_date->toDateString())->toBe('2026-03-01');

    $result = app(MonthlyBillingService::class)->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'));
    $item = $result['invoice']->items()->where('type', 'chiller_charge')->sole();

    expect($result['status'])->toBe('created')
        ->and((float) $item->amount)->toBe(2500.0)
        ->and((float) $item->vat_amount)->toBe(Vat::on(2500));
});

it('posts it to the account the accountant chose', function () {
    // The GL invariant: a source must be driven through the real service AND the sweep. A new code
    // is not a new journalizer — it rides the invoice one — so what is proved here is that the
    // catalogue's posting role reaches the ledger rather than dropping to misc_income.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    CarbonImmutable::setTestNow('2026-03-05');
    chillerCode();
    $lease = chillerLease();

    app(ChargeScheduleService::class)->setAmount(
        $lease,
        'chiller_charge',
        2500,
        CarbonImmutable::parse('2026-03-01'),
        ['name' => 'Chiller charge', 'frequency' => 'monthly', 'first_row_from_effective' => true],
    );

    $invoice = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'))['invoice'];
    $invoice->update(['status' => 'issued']);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // 41102001 = service_charge_revenue, the role on the code above.
    $credited = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '41102001'))->sum('credit');

    expect($credited)->toBeGreaterThanOrEqual(2500.0)
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('refuses a charge type no catalogue knows', function () {
    // The app-layer guard that replaced the DB enum. Without it, freeing the column would let a
    // typo bill for years — the invoice line would post to miscellaneous income and look deliberate.
    $lease = chillerLease();

    expect(fn () => Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Mystery',
        'type' => 'chillar_charge',   // the typo, one letter from a real code
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => '2026-03-01',
        'is_active' => true,
    ]))->toThrow(DomainException::class);

    // The control: the same write with a code the catalogue knows goes through, so the refusal
    // above is the guard and not a broken fixture.
    chillerCode();

    expect(Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Chiller charge',
        'type' => 'chiller_charge',
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => '2026-03-01',
        'is_active' => true,
    ])->exists)->toBeTrue();
});

it('stops a charge without disturbing what it already billed', function () {
    CarbonImmutable::setTestNow('2026-03-05');
    chillerCode();
    $lease = chillerLease();

    app(ChargeScheduleService::class)->setAmount(
        $lease, 'chiller_charge', 2500, CarbonImmutable::parse('2026-03-01'),
        ['name' => 'Chiller charge', 'frequency' => 'monthly', 'first_row_from_effective' => true],
    );

    $march = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'))['invoice'];
    $marchTotal = (float) $march->total;

    app(ChargeScheduleService::class)->close($lease->fresh(), 'chiller_charge', CarbonImmutable::parse('2026-04-01'));

    $april = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-04-01'));

    expect($april['invoice']->items()->where('type', 'chiller_charge')->exists())->toBeFalse()
        // March is history and stays exactly as billed — stopping a charge is not a reversal.
        ->and((float) $march->fresh()->total)->toBe($marchTotal)
        ->and($march->items()->where('type', 'chiller_charge')->exists())->toBeTrue()
        // The row survives too, closed rather than deleted: the schedule keeps its own history.
        ->and($lease->fresh()->charges()->where('type', 'chiller_charge')->sole()->end_date->toDateString())
        ->toBe('2026-03-31');
});

it('refuses to hand-write a charge type another service owns', function () {
    // base_rent, marketing and parking are DERIVED — from the Change Rent action, from base rent,
    // from the rentable-items pivot. A row typed here would sit beside the one its service
    // maintains and double-bill, so the picker disables them and the dispatch is refused.
    CarbonImmutable::setTestNow('2026-03-05');
    chillerCode();
    $lease = chillerLease();

    $rm = fn () => Livewire::test(ChargeScheduleRelationManager::class, [
        'ownerRecord' => $lease->fresh(),
        'pageClass' => EditLease::class,
    ]);

    $before = $lease->charges()->where('type', 'base_rent')->count();

    // Refused at the dispatch, not merely greyed out: Filament validates the submitted value
    // against the ENABLED options server-side. (`action()` carries the same rule in its
    // `abort_unless` — a second layer this path never reaches, kept because a disabled option is a
    // UI fact and the rule is not.)
    $rm()->callAction(TestAction::make('addCharge')->table(), data: [
        'type' => 'base_rent',
        'amount' => 99999,
        'frequency' => 'monthly',
        'effective_from' => '2026-03-01',
        'vat_rate' => 0,
    ])->assertHasActionErrors(['type']);

    expect($lease->fresh()->charges()->where('type', 'base_rent')->count())->toBe($before);

    // The control: the identical dispatch with a catalogue code goes through, so the refusal above
    // is the derived-type rule rather than the action being unreachable in this test.
    $rm()->callAction(TestAction::make('addCharge')->table(), data: [
        'type' => 'chiller_charge',
        'amount' => 2500,
        'frequency' => 'monthly',
        'effective_from' => '2026-03-01',
        'vat_rate' => Vat::rateForType('chiller_charge'),
    ])->assertHasNoActionErrors();

    expect($lease->fresh()->charges()->where('type', 'chiller_charge')->exists())->toBeTrue();
});
