<?php

use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Filament\Admin\Resources\ChargeCodes\Pages\EditChargeCode;
use App\Filament\Admin\Resources\ChargeCodes\Pages\ListChargeCodes;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Support\MorphMap;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The charge-code catalogue — gap-analysis row 216.
 *
 * Adding a billable line used to mean editing a PHP enum and a private const map inside the
 * journalizer, then deploying. The point of this table is that an accountant can do it; the point of
 * these tests is that a code they add actually reaches the general ledger, rather than being a row
 * on a screen that nothing reads — which is exactly what `account_mappings` was until this morning.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the catalogue', function () {
    Livewire::test(ListChargeCodes::class)->assertOk();
});

it('bills and POSTS a code the accountant added, with no deploy', function () {
    // The whole claim of row 216, end to end: a new code, billed on a real invoice, landing in the
    // account the accountant chose — through the real journalizer, not a unit-tested map.
    ChargeCode::create([
        'code' => 'key_money',
        'name_en' => 'Key money',
        'name_ar' => 'خلو رجل',
        'posting_role' => 'misc_income',
    ]);

    $lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);
    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => now(),
        'due_date' => now()->addDays(7),
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'subtotal' => 50000,
        'vat_amount' => 0,
        'total' => 50000,
        'paid_amount' => 0,
        'balance' => 50000,
        'currency' => 'EGP',
    ]);
    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'description' => 'Key money',
        'type' => 'key_money',
        'amount' => 50000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 50000,
    ]);

    // The REAL sweep, not LedgerPoster::post() — the project invariant for proving a GL source.
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $entry = JournalEntry::query()
        ->where('source_type', MorphMap::alias(Invoice::class))
        ->where('source_id', $invoice->id)
        ->with('lines.account')
        ->first();

    $miscIncome = LedgerAccount::query()->where('code', '42101001')->sole();

    expect($entry)->not->toBeNull()
        ->and($entry->lines->firstWhere('ledger_account_id', $miscIncome->id)?->credit)
        ->toEqual(50000);
});

it('follows the account when the accountant re-points a code', function () {
    // Re-pointing is the other half of "configuration": the next invoice must land somewhere new
    // without a deploy. Read through the model's own resolver so the in-request memo is exercised.
    expect(ChargeCode::roleFor('late_fee'))->toBe('late_fee_income');

    ChargeCode::query()->where('code', 'late_fee')->sole()->update(['posting_role' => 'misc_income']);

    expect(ChargeCode::roleFor('late_fee'))->toBe('misc_income');
});

it('refuses to delete or deactivate a code the billing engine names', function () {
    // `cam_recovery` drives the monthly anti-double-bill probe. Removing the row would not remove
    // the behaviour — it would leave the engine posting a code the catalogue no longer describes.
    $systemCode = ChargeCode::query()->where('code', 'cam_recovery')->sole();

    Livewire::test(EditChargeCode::class, ['record' => $systemCode->getKey()])
        ->assertActionHidden('delete');

    // …while an operator-added code is ordinary cleanup — the control that proves the refusal is
    // about system codes and not a blanket block.
    $ownCode = ChargeCode::create([
        'code' => 'signage_fee',
        'name_en' => 'Signage fee',
        'name_ar' => 'رسوم لافتات',
        'posting_role' => 'misc_income',
    ]);

    Livewire::test(EditChargeCode::class, ['record' => $ownCode->getKey()])
        ->assertActionVisible('delete');
});

it('lets only the books-owning roles change the catalogue', function () {
    $this->actingAs(makeUser('viewer'));

    expect(ChargeCodeResource::canViewAny())->toBeTrue()
        ->and(ChargeCodeResource::canCreate())->toBeFalse();

    // The control.
    $this->actingAs(makeUser('accounting'));

    expect(ChargeCodeResource::canCreate())->toBeTrue();
});
