<?php

/*
|--------------------------------------------------------------------------
| A register answers the question it is opened with (SW-024, SW-044, SW-083)
|--------------------------------------------------------------------------
| Three registers that could not be asked the first question an operator arrives with.
|
| **SW-024 — the deposit register could not be searched or filtered by TENANT.** The only searchable
| column was the deposit NUMBER, which nobody remembers; "what is held for Cilantro" needed scrolling.
| The search goes through the tenant's own FOLDED blob, never a raw `tenant.name` path — folding one
| side matches nothing, and a raw path silently misses exactly the Arabic spellings the fold exists to
| reconcile while still working for plain ASCII, which is what anyone spot-checking it would try.
|
| **SW-044 — the rentable-items register showed the WRONG holder.** The column read `leases.tenant.name`,
| the whole morph history unfiltered by status or by whether the holding is still open, so it listed a
| tenant who gave the bay back last year — and, reading the LEASE relation only, it showed nothing at
| all for a bay held by a UNIT OWNER. `RentableItem::currentHolderLabel()` already answered correctly
| and is documented as the reading half of `isSpokenFor()` so the map and the register cannot disagree;
| the register simply was not asking. Two doors onto one fact, the same shape as SW-076 and SW-165.
|
| **SW-083 — the AP and expense registers had no date range**, while every AR money list has one.
| "Which bills did we take in March" is the payables clerk's first question.
*/

use App\Filament\Admin\Resources\DepositTransactions\Pages\ListDepositTransactions;
use App\Filament\Admin\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Admin\Resources\RentableItems\Pages\ListRentableItems;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Models\DepositTransaction;
use App\Models\RentableItem;
use App\Support\Filament\DateRangeFilter;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RGX']);
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('finds a deposit by the tenant’s name, not just by its number', function () {
    $wanted = makeTenant(['name' => 'Cilantro']);
    $other = makeTenant(['name' => 'Zara']);

    $keep = DepositTransaction::create([
        'lease_id' => makeLease(makeUnit($this->asset), $wanted)->id,
        'asset_id' => $this->asset->id, 'tenant_id' => $wanted->id,
        'type' => 'receipt', 'status' => 'recorded', 'method' => 'bank',
        'amount' => 50000, 'transaction_date' => '2026-03-10',
    ]);
    $drop = DepositTransaction::create([
        'lease_id' => makeLease(makeUnit($this->asset), $other)->id,
        'asset_id' => $this->asset->id, 'tenant_id' => $other->id,
        'type' => 'receipt', 'status' => 'recorded', 'method' => 'bank',
        'amount' => 60000, 'transaction_date' => '2026-03-11',
    ]);

    // The refusal AND the control: a search that returned nothing would satisfy "Zara is absent"
    // on its own.
    Livewire::test(ListDepositTransactions::class)
        ->searchTable('Cilantro')
        ->assertCanSeeTableRecords([$keep])
        ->assertCanNotSeeTableRecords([$drop]);
});

it('names the holder who has the bay NOW, not one who gave it back', function () {
    $former = makeTenant(['name' => 'Former Holder']);
    $current = makeTenant(['name' => 'Current Holder']);

    $item = RentableItem::create([
        'asset_id' => $this->asset->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
        'type' => 'parking', 'monthly_rate' => 500, 'status' => RentableItem::STATUS_ASSIGNED,
    ]);

    $old = makeLease(makeUnit($this->asset), $former, ['status' => 'terminated']);
    $live = makeLease(makeUnit($this->asset), $current, ['status' => 'active']);

    // A CLOSED holding by a lease that has ended, and a live open one beside it.
    $item->leases()->attach($old->id, ['effective_from' => '2025-01-01', 'effective_to' => '2025-12-31']);
    $item->leases()->attach($live->id, ['effective_from' => '2026-01-01', 'effective_to' => null]);

    expect($item->fresh()->currentHolderLabel())->toBe('Current Holder');

    Livewire::test(ListRentableItems::class)
        ->assertCanSeeTableRecords([$item])
        ->assertSee('Current Holder')
        ->assertDontSee('Former Holder');
});

it('offers a date range on the AP register and the expense register', function () {
    // Both had none while every AR money list has one. Asserting the filter EXISTS on the mounted
    // table rather than reading the source, so a call site that chains it away is still caught.
    foreach ([ListVendorBills::class, ListExpenses::class] as $page) {
        $filters = array_keys(Livewire::test($page)->instance()->getTable()->getFilters());

        expect($filters)->toContain($page === ListVendorBills::class ? 'bill_date' : 'expense_date');
    }
});

it('narrows to the range, and leaves what falls outside it', function () {
    $wanted = makeTenant(['name' => 'In Range']);
    $lease = makeLease(makeUnit($this->asset), $wanted);

    $inside = DepositTransaction::create([
        'lease_id' => $lease->id, 'asset_id' => $this->asset->id, 'tenant_id' => $wanted->id,
        'type' => 'receipt', 'status' => 'recorded', 'method' => 'bank',
        'amount' => 1000, 'transaction_date' => '2026-03-15',
    ]);
    $outside = DepositTransaction::create([
        'lease_id' => $lease->id, 'asset_id' => $this->asset->id, 'tenant_id' => $wanted->id,
        'type' => 'receipt', 'status' => 'recorded', 'method' => 'bank',
        'amount' => 2000, 'transaction_date' => '2026-05-15',
    ]);

    Livewire::test(ListDepositTransactions::class)
        ->filterTable('transaction_date', ['from' => '2026-03-01', 'until' => '2026-03-31'])
        ->assertCanSeeTableRecords([$inside])
        ->assertCanNotSeeTableRecords([$outside]);
});

it('uses whereDate, so the closing day is inside the range', function () {
    // These columns are `date` on some tables and `datetime` on others. A plain `>=` against a
    // datetime silently excludes everything recorded after midnight on the closing day — the
    // operator picks a range that includes today and today's rows are missing.
    $sql = DateRangeFilter::make('transaction_date')
        ->apply(DepositTransaction::query(), ['from' => '2026-03-01', 'until' => '2026-03-31'])
        ->toSql();

    // The rendered function differs by DRIVER — sqlite compiles `whereDate` to `strftime`, MySQL
    // to `date(`. Asserting one spelling would pass here and prove nothing about production, so
    // assert that a DATE function is applied at all, either way.
    expect($sql)->toContain('transaction_date')
        ->and(str_contains($sql, 'strftime') || str_contains($sql, 'date('))->toBeTrue();
});
