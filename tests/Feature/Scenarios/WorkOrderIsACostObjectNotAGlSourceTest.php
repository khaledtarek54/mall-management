<?php

/*
|--------------------------------------------------------------------------
| The work order costs money and must never POST it (2026-08-20)
|--------------------------------------------------------------------------
| Close-out step 2 gives `facility_work_orders` a cost roll-up. The single most dangerous thing
| anyone could do next is read "the work order now knows what it cost" as "the work order should
| post what it cost".
|
| It must not. The money is ALREADY in the ledger, through three documents that each own their
| entry:
|
|     material  → StockMovement      → InventoryMovementJournalizer
|     service   → VendorBill/Expense → VendorBillJournalizer / ExpenseJournalizer
|     labour    → Payroll            → PayrollJournalizer (as salaries_expense, in total)
|
| The `act_*` columns are a MANAGEMENT DIMENSION over already-posted money — which job, which
| machine, which trade consumed it. Registering a journalizer for them would post every maintenance
| cost in the business twice, and the books would still balance, which is what makes it dangerous.
|
| The same caution applies to reading: a job's labour cost does not ADD to the wage bill, it
| EXPLAINS part of it.
*/

use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Trade;
use App\Services\Accounting\LedgerPoster;
use Database\Seeders\RolesPermissionsSeeder;

it('never registers the work order or its labour as a GL posting source', function () {
    $sources = LedgerPoster::sources();

    expect($sources)->not->toContain(FacilityWorkOrder::class,
        'A work order is a COST OBJECT, not a posting source: its material is already posted by '
        .'StockMovement, its service by VendorBill/Expense and its labour by Payroll. Registering '
        .'it would post every maintenance cost twice — and balanced, which is why nothing would '
        .'look wrong. Read the migration docblock before changing this.')
        ->and($sources)->not->toContain(FacilityWorkOrderLabour::class,
            'Reported hours ALLOCATE an already-posted wage to the job that consumed it. They do '
            .'not create a cost, so they have nothing to post.');
});

/**
 * The gate above proves a registry entry is absent, which is weak on its own — this proves the
 * property that entry would violate: money moving on a job leaves the general ledger alone.
 */
it('moves no money into the ledger when a job records what it cost', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $this->actingAs(makeUser('manager', [$asset->id]));

    $trade = Trade::where('code', 'hvac')->firstOrFail();
    $trade->update(['standard_hourly_rate' => 300]);

    $wo = FacilityWorkOrder::create([
        'asset_id' => $asset->id, 'work_order_type' => 'cm', 'execution_type' => 'internal',
        'title' => 'Chiller down', 'description' => 'No cooling on the second floor.',
        'trade_id' => $trade->id, 'status' => 'open', 'priority' => 'high', 'scheduled_for' => now()->toDateString(),
    ]);

    $entriesBefore = JournalEntry::count();
    $linesBefore = JournalLine::count();

    FacilityWorkOrderLabour::create([
        'facility_work_order_id' => $wo->id, 'worked_on' => now(), 'hours' => 6,
    ]);

    // The cost object learned something…
    expect($wo->fresh()->act_labour_cost)->toEqual(1800.00)
        // …and the ledger did not move.
        ->and(JournalEntry::count())->toBe($entriesBefore)
        ->and(JournalLine::count())->toBe($linesBefore);
});
