<?php

/*
|--------------------------------------------------------------------------
| Input VAT is classified, not just keyed
|--------------------------------------------------------------------------
| `vendor_bills.vat_amount` and `expenses.vat_amount` were plain typed figures, and BOTH post to
| `vat_recoverable` — the account the VAT return reads for input VAT. So the entire input side of a
| filed return rested on a number with nothing saying what it was: whether the supplier was
| registered, whether the supply was exempt, or whether the figure was 14% of the net or a typo.
|
| The purchase side is deliberately gated more lightly than the sales side, and that asymmetry is
| the thing most worth pinning here: on an invoice the rate is OUR decision, so it is re-derived and
| an operator without `tax_codes.override` cannot land anything else. On a supplier's bill the tax is
| THEIR number on THEIR document — a system that refused to record what a supplier actually charged
| would push the difference somewhere worse. So the amount stays editable and a real departure asks
| for a written reason. Odoo and SAP both work this way.
*/

use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Models\Expense;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Support\CatalogueTaxRate;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('derives the tax a purchase code implies, on the document date', function () {
    expect(CatalogueTaxRate::deriveOnNet('VAT_14_P', 1000, '2026-03-01'))->toBe(140.0)
        // An unregistered supplier or an exempt supply reclaims nothing — and that is a stated
        // answer, not an absent one, which is the whole reason `VAT_IN_NONE` is a code.
        ->and(CatalogueTaxRate::deriveOnNet('VAT_EXEMPT_P', 1000, '2026-03-01'))->toBe(0.0)
        // Unclassified: the caller must leave the operator's figure alone rather than replace it
        // with a zero it cannot justify.
        ->and(CatalogueTaxRate::deriveOnNet(null, 1000, '2026-03-01'))->toBeNull();
});

it('treats rounding as rounding and a real gap as a decision', function () {
    // A pound. A rounding difference between two systems computing the same percentage on the same
    // base is sub-unit; anything larger is a different rate or a different base.
    expect(CatalogueTaxRate::purchaseTaxDeparts('VAT_14_P', 1000, 140.00, '2026-03-01'))->toBeFalse()
        ->and(CatalogueTaxRate::purchaseTaxDeparts('VAT_14_P', 1000, 139.60, '2026-03-01'))->toBeFalse()
        ->and(CatalogueTaxRate::purchaseTaxDeparts('VAT_14_P', 1000, 137.00, '2026-03-01'))->toBeTrue()
        // Unclassified departs from nothing — there is no figure to depart from.
        ->and(CatalogueTaxRate::purchaseTaxDeparts(null, 1000, 999.00, '2026-03-01'))->toBeFalse();
});

it('refuses an unexplained departure on an expense, through the real form', function () {
    // `required()` is server-side validation — unlike `readOnly`, which is only an input attribute —
    // so this is the gate rather than the hint. Driven through the form because that is where an
    // operator meets it.
    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'utilities',
            'description' => 'Electricity — March',
            'expense_date' => '2026-03-05',
            'amount' => 1000,
            'tax_code' => 'VAT_14_P',
            'vat_amount' => 40,          // ← 14% would be 140
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasFormErrors(['tax_override_reason']);

    expect(Expense::count())->toBe(0);
});

it('accepts the same departure once it is explained', function () {
    // The control. Without it the refusal above would pass just as happily if the form refused
    // every expense, which would be a different and worse bug.
    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'utilities',
            'description' => 'Electricity — March',
            'expense_date' => '2026-03-05',
            'amount' => 1000,
            'tax_code' => 'VAT_14_P',
            'vat_amount' => 40,
            'tax_override_reason' => 'Supplier billed a partly exempt supply',
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::sole();

    expect((float) $expense->vat_amount)->toBe(40.0, 'the supplier\'s own figure must be recorded, not ours')
        ->and($expense->tax_code)->toBe('VAT_14_P')
        ->and($expense->tax_override_reason)->toBe('Supplier billed a partly exempt supply');
});

it('lets a rounding difference through without a reason', function () {
    // The everyday case, and the reason the purchase side is not re-derived: a supplier's document
    // is the fact, and demanding an essay for 40 piastres would train operators to type noise.
    $this->actingAs(makeUser('accounting'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'category' => 'utilities',
            'description' => 'Electricity — March',
            'expense_date' => '2026-03-05',
            'amount' => 1000,
            'tax_code' => 'VAT_14_P',
            'vat_amount' => 139.60,
            'status' => 'draft',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect((float) Expense::sole()->vat_amount)->toBe(139.6);
});

it('classifies what the backfill can prove and leaves the rest null', function () {
    // Two inferences are sound: a zero reclaim IS `VAT_IN_NONE` (a restatement, not a guess), and a
    // figure equal to the standard rate on the net IS the standard code. A bill whose tax is neither
    // is telling us something the migration cannot read, and a confident code would bury it.
    $vendor = Vendor::factory()->create();
    $asset = makeAsset();

    $exact = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id,
        'category' => 'services', 'bill_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'subtotal' => 1000, 'vat_amount' => 140, 'total' => 1140,
        'paid_amount' => 0, 'balance' => 1140, 'status' => 'draft', 'currency' => 'EGP',
    ]);
    $none = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id,
        'category' => 'services', 'bill_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000, 'status' => 'draft', 'currency' => 'EGP',
    ]);
    $odd = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id,
        'category' => 'services', 'bill_date' => '2026-03-01', 'due_date' => '2026-03-31',
        'subtotal' => 1000, 'vat_amount' => 77, 'total' => 1077,
        'paid_amount' => 0, 'balance' => 1077, 'status' => 'draft', 'currency' => 'EGP',
    ]);

    // Re-run the migration's inference over rows created after it ran.
    DB::table('vendor_bills')->update(['tax_code' => null]);
    $migration = require database_path('migrations/2026_08_12_160000_add_tax_code_to_purchase_documents.php');
    (function () {
        $this->backfill();
    })->call($migration);

    expect($exact->fresh()->tax_code)->toBe('VAT_14_P')
        ->and($none->fresh()->tax_code)->toBe('VAT_EXEMPT_P')
        ->and($odd->fresh()->tax_code)->toBeNull();
});
