<?php

use App\Filament\Admin\Resources\Custodies\CustodyResource;
use App\Models\Employee;
use App\Services\GrantCustodyService;
use App\Services\SettleCustodyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Module 25 UX pass — the عهدة (custody) register is exportable to CSV: each custodian's grant,
 * settled-to-date and the cash still in their hands, plus totals. Outstanding (amount − settled) must
 * match the screen, be property-scoped like the derived `settled_sum` column, and total correctly.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->mallA = makeAsset(['code' => 'AAA']);
    $this->mallB = makeAsset(['code' => 'BBB']);
});

function custodianFor(int $assetId, string $name): Employee
{
    return Employee::create([
        'asset_id' => $assetId, 'code' => 'C-'.uniqid(), 'name' => $name,
        'hire_date' => '2026-01-01', 'base_salary' => 8000, 'payment_method' => 'cash',
    ]);
}

it('values the register at amount − settled, scoped to the user', function () {
    $custody = app(GrantCustodyService::class)->grant(custodianFor($this->mallA->id, 'Karim'), [
        'reference' => 'CUS-A', 'amount' => 5000, 'custody_date' => now()->toDateString(),
        'paid_from' => 'cash', 'purpose' => 'Site materials',
    ]);
    app(SettleCustodyService::class)->settle($custody, [
        'type' => 'expense', 'amount' => 1200, 'transaction_date' => now()->toDateString(), 'category' => 'maintenance',
    ]);
    // Another mall's custody — must be out of scope for a mall-A user.
    app(GrantCustodyService::class)->grant(custodianFor($this->mallB->id, 'Omar'), [
        'reference' => 'CUS-B', 'amount' => 9000, 'custody_date' => now()->toDateString(), 'paid_from' => 'bank',
    ]);

    $this->actingAs(makeUser('accounting', [$this->mallA->id]));

    $csv = CustodyResource::registerCsv();
    $row = collect($csv['rows'])->firstWhere(2, 'CUS-A');

    // amount 5000, settled 1200, outstanding 3800 — and mall B's custody is not in scope.
    expect((float) $row[5])->toBe(5000.0)
        ->and((float) $row[6])->toBe(1200.0)
        ->and((float) $row[7])->toBe(3800.0)
        ->and(collect($csv['rows'])->firstWhere(2, 'CUS-B'))->toBeNull();
});

it('closes the register with amount / settled / outstanding totals', function () {
    $c1 = app(GrantCustodyService::class)->grant(custodianFor($this->mallA->id, 'Karim'), [
        'reference' => 'CUS-1', 'amount' => 5000, 'custody_date' => now()->toDateString(), 'paid_from' => 'cash',
    ]);
    app(SettleCustodyService::class)->settle($c1, [
        'type' => 'expense', 'amount' => 2000, 'transaction_date' => now()->toDateString(), 'category' => 'admin',
    ]);
    app(GrantCustodyService::class)->grant(custodianFor($this->mallB->id, 'Omar'), [
        'reference' => 'CUS-2', 'amount' => 3000, 'custody_date' => now()->toDateString(), 'paid_from' => 'bank',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->mallA->id, $this->mallB->id]));

    $csv = CustodyResource::registerCsv();
    $total = collect($csv['rows'])->last();

    // amount 8000, settled 2000, outstanding 6000.
    expect((float) $total[5])->toBe(8000.0)
        ->and((float) $total[6])->toBe(2000.0)
        ->and((float) $total[7])->toBe(6000.0);
});
