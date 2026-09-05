<?php

/*
|--------------------------------------------------------------------------
| A sales deduction is worth what the operator typed (SW-163, SW-164)
|--------------------------------------------------------------------------
| Percentage rent is charged on turnover NET of agreed deductions, so every one of these is money
| that reaches a tenant's invoice. Two ways the figure went wrong, both silent, both over-billing:
|
| **SW-164 — an unknown key is worth nothing.** `sales_exclusions` is a free `KeyValue`, and
| `SalesExclusions::total()` skips any key not in the catalogue. So `VAT`, `refunds` or
| `sales returns` typed by hand deducted **0.00**, and the tenant was billed percentage rent on
| turnover the operator had agreed to exclude. Nothing said so — the screen shows the derived total,
| never the parse. Refused now, and refusing costs nothing: `other` exists precisely so a real
| negotiated clause never has to be invented as a new key.
|
| **SW-164 again — `(float) '1,200.00'` is 1.0 in PHP.** It stops at the comma. A thousands
| separator is the ordinary way to write money and is what a POS report prints, so a 1,200
| deduction was worth one pound. `SalesExclusions::amount()` reads it the way a person wrote it,
| Arabic-Indic digits included, because the panel is bilingual.
|
| **SW-163 — the VAT deduction did not follow its gross.** It is computed once when the toggle is
| flipped, from the gross at that instant, so CORRECTING the gross afterwards left a deduction taken
| from the old figure. And because the breakpoint is subtracted first, a small error in sales becomes
| a large one in the overage.
|
| The unknown-key refusal is on the MODEL, not the form: the portal, the importer and the API reach
| the same column and this is the one seam all of them cross.
*/

use App\Filament\Admin\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\TenantSalesDeclaration;
use App\Support\SalesExclusions;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'SDX']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    $this->lease = makeLease(makeUnit($this->asset));

    // A Filament page renders its resource's index URL, which is tenant-scoped — without this the
    // page throws `Missing parameter: tenant` before any callback runs.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * One exclusion's amount, read off a `KeyValue`'s Livewire state.
 *
 * **The trap this exists for:** a `KeyValue` holds `['vat' => 140000]` after `fillForm()`, and
 * `[['key' => 'vat', 'value' => 280000]]` after any Livewire UPDATE — it switches to the
 * pair-array shape the component actually edits in. A test that reads only the first shape sees
 * `0.0` after the very interaction it is testing, and reports the fix as broken.
 */
function exclusionAmount(mixed $state, string $type): float
{
    $state = (array) $state;

    if (array_key_exists($type, $state) && ! is_array($state[$type])) {
        return (float) $state[$type];
    }

    foreach ($state as $row) {
        if (is_array($row) && ($row['key'] ?? null) === $type) {
            return (float) ($row['value'] ?? 0);
        }
    }

    return 0.0;
}

function declarationWith(array $exclusions, float $gross = 1000000): TenantSalesDeclaration
{
    return TenantSalesDeclaration::create([
        'lease_id' => test()->lease->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
        'gross_sales' => $gross,
        'sales_exclusions' => $exclusions,
        'status' => 'submitted',
        'declared_at' => '2026-09-01',
    ]);
}

it('reads a deduction written with thousands separators', function () {
    // `(float) '1,200.00'` is 1.0. The whole defect in one line.
    expect(SalesExclusions::amount('1,200.00'))->toEqual(1200.0)
        ->and(SalesExclusions::amount('1200'))->toEqual(1200.0)
        ->and(SalesExclusions::amount(1200))->toEqual(1200.0);
});

it('reads Arabic-Indic digits, because the panel is bilingual', function () {
    expect(SalesExclusions::amount('١٬٢٠٠٫٥٠'))->toEqual(1200.5);
});

it('never lets a deduction go negative, whatever was typed', function () {
    expect(SalesExclusions::amount('-1,200'))->toEqual(1200.0)
        ->and(SalesExclusions::amount('abc'))->toEqual(0.0)
        ->and(SalesExclusions::amount(''))->toEqual(0.0);
});

it('carries a separated figure into the CHARGE BASIS, not one pound', function () {
    $declaration = declarationWith(['returns' => '120,000.00']);

    // declared_sales is derived on save: 1,000,000 − 120,000. Before the fix it was 999,999.00,
    // and the tenant paid percentage rent on 119,999 of returned goods.
    expect(round((float) $declaration->fresh()->declared_sales, 2))->toEqual(880000.0);
});

it('REFUSES a deduction type the catalogue does not know', function () {
    // Silently worth 0.00 before, so the tenant was over-billed by exactly the deduction they had
    // been granted. Asserting the refusal AND that nothing was written.
    // The MESSAGE, not just the type — every guard on this model throws `DomainException`, so
    // asserting the class alone passes on an unrelated refusal. It did: the first draft of this
    // fixture used an invalid status and both refusal cases went green for that reason instead.
    expect(fn () => declarationWith(['refunds' => 50000]))
        ->toThrow(DomainException::class, 'refunds');

    expect(TenantSalesDeclaration::query()->count())->toBe(0);
});

it('names the offending types in the refusal, so the operator can fix it', function () {
    try {
        declarationWith(['refunds' => 50000, 'VAT' => 10000]);
        expect()->fail('the write should have been refused');
    } catch (DomainException $e) {
        // A refusal that does not say WHICH key is wrong leaves the operator guessing on a
        // free-text field — the shape this codebase records for every anonymous refusal.
        expect($e->getMessage())->toContain('refunds')->toContain('VAT');
    }
});

it('accepts every catalogued type, including the escape hatch', function () {
    // The control: refusing must not narrow what an operator may legitimately record. `other` is
    // why refusing costs nothing.
    $declaration = declarationWith(['vat' => 100000, 'returns' => 20000, 'other' => 5000]);

    expect(round((float) $declaration->fresh()->declared_sales, 2))->toEqual(875000.0);
});

it('re-derives the VAT deduction when the gross is CORRECTED (SW-163)', function () {
    // The VAT row is computed once, when the toggle is flipped, from the gross at that instant.
    // Correcting the gross afterwards left a deduction taken from the OLD figure, and it flowed
    // straight into the charge basis. Driven through the real form, because the staleness lives in
    // a `->live()` callback and a service-level test cannot see it.
    // A declaration whose gross INCLUDES VAT — the state the toggle leaves behind, set here
    // directly so the case under test is the gross being corrected and not the toggle itself.
    $page = Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm([
            'lease_id' => $this->lease->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'declared_at' => '2026-09-01',
            'gross_sales' => 1140000,
            'sales_exclusions' => ['vat' => 140000],
        ]);

    expect(round(exclusionAmount($page->get('data.sales_exclusions'), 'vat'), 2))->toEqual(140000.0);

    // The operator now corrects the figure — a misread column, the commonest reason to retype it.
    // Before the fix the deduction stayed at 140,000, taken from a gross that no longer existed,
    // and it flowed straight into the charge basis.
    $page->set('data.gross_sales', 2280000);

    expect(round(exclusionAmount($page->get('data.sales_exclusions'), 'vat'), 2))->toEqual(280000.0);
});

it('does NOT invent a VAT deduction the operator never asked for', function () {
    // The other direction, and the reason the re-derivation is conditional: correcting the gross on
    // a declaration whose gross is stated NET must not silently start deducting VAT from it.
    $page = Livewire::test(CreateTenantSalesDeclaration::class)
        ->fillForm([
            'lease_id' => $this->lease->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'declared_at' => '2026-09-01',
            'gross_sales' => 1140000,
        ])
        ->set('data.gross_sales', 2280000);

    expect(exclusionAmount($page->get('data.sales_exclusions'), 'vat'))->toEqual(0.0);
});

it('computes VAT WITHIN the gross, never gross times the rate', function () {
    // Pinned because it is the arithmetic the SW-163 fix re-runs: over-deducting by a factor of
    // 1.14 would show up here first.
    expect(round(SalesExclusions::vatWithin(1140000, 14.0), 2))->toEqual(140000.0);
});
