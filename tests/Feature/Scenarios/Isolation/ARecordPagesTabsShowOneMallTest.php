<?php

use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\Violation;
use App\Models\Warehouse;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\Support\CrossPropertyTabs;

/**
 * **EVERY RECORD-PAGE TAB THAT COULD SHOW ANOTHER MALL'S ROWS, ASKED WHETHER IT DOES.**
 *
 * `ARestrictedOperatorSeesOneMallTest` drives every admin LIST, and
 * `PropertyIsolationConformanceTest` proves every RESOURCE is classified and scoped. Neither can
 * see a RELATION MANAGER — a tab builds its query from `$owner->relation()`, and no resource's
 * `getEloquentQuery()` is involved. There are 67 of them and isolation coverage was **five**, across
 * two files and two occasions — four from the 2026-07 adversarial sweep and one from SW-191 — so
 * sixty-two had never been asked.
 *
 * Measured when this file was written: of the nine tabs that CAN leak, seven scoped — in **five
 * different spellings** — and two were scoped by nothing at all. Five of the seven already had a
 * regression test; this sweep covers all nine and fails on a tenth that arrives without one. A tenant's **violations** and
 * **declared sales** were readable from any mall the tenant traded in, by an operator who held one
 * of them. Turnover is the number percentage rent is billed on; it is a retailer's most
 * commercially sensitive figure and it was on the wrong operator's screen.
 *
 * **The sweep is DERIVED and every at-risk tab must be covered here or the test fails.** A new tab
 * under a shared owner cannot ship uncovered — that is the whole difference between this and the
 * hand-picked sweep it replaces. `FIXTURES` says how to put a row in a given mall; a tab whose
 * child model has no entry fails loudly rather than being silently skipped, which is how a sweep
 * comes to report on a set it has stopped collecting.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mine = makeAsset(['code' => 'ISOA']);
    $this->theirs = makeAsset(['code' => 'ISOB']);

    // ONE shared tenant trading in BOTH malls — the pivot every one of these tabs hangs off.
    $this->tenant = makeTenant();
    $this->leaseMine = makeLease(makeUnit($this->mine), $this->tenant);
    $this->leaseTheirs = makeLease(makeUnit($this->theirs), $this->tenant);

    // Two more shared owners with property-owned children of their own.
    $this->vendor = Vendor::create([
        'name' => 'Sweep Contracting',
        'email' => 'sweep@vendor.test',
        'status' => 'active',
    ]);

    $this->item = InventoryItem::create(['sku' => 'SKU-SWEEP', 'name' => 'Filter']);

    // An operator holding ONE mall.
    //
    // **NO mall is selected, deliberately** — `TenantScope::visibleAssetIds()` answers
    // `[the selected tenant]` without consulting `AssignedAssets` the moment a tenant is set, so
    // selecting a mall would make every guard here answer correctly because of the SELECTION and
    // never because of the ASSIGNMENT. The file would then pass with the assignment deleted. This
    // is the premise `AdversarialSweepRegressionTest` states for the same reason.
    $this->actingAs(makeUser('manager', [$this->mine->id]));
});

/**
 * How to put one row of a given child model into a given mall.
 *
 * Keyed by MODEL, not by manager: two tabs listing invoices need one recipe, and a tab added over
 * an already-covered model is covered by existing.
 */
function tabFixtures(): array
{
    return [
        Lease::class => fn ($t, $lease, $vendor, $item) => $lease,
        Invoice::class => fn ($t, $lease, $vendor, $item) => makeInvoice($lease),
        TenantRequest::class => fn ($t, $lease, $vendor, $item) => makeTenantRequest([
            'tenant_id' => $t->id,
            'unit_id' => $lease->unit_id,
        ]),
        Violation::class => fn ($t, $lease, $vendor, $item) => Violation::create([
            'asset_id' => $lease->unit->asset_id,
            'tenant_id' => $t->id,
            'description' => 'Shutter left open',
            'violation_date' => '2026-09-01',
            'status' => 'open',
        ]),
        TenantSalesDeclaration::class => fn ($t, $lease, $vendor, $item) => TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'gross_sales' => 100000,
            'declared_at' => '2026-09-01',
            'status' => 'submitted',
        ]),
        VendorContract::class => fn ($t, $lease, $vendor, $item) => VendorContract::create([
            'vendor_id' => $vendor->id,
            'asset_id' => $lease->unit->asset_id,
            'name' => 'Cleaning retainer',
            'status' => 'active',
            'start_date' => '2026-01-01',
        ]),
        StockMovement::class => function ($t, $lease, $vendor, $item) {
            $warehouse = Warehouse::firstOrCreate(
                ['asset_id' => $lease->unit->asset_id, 'code' => 'WH-'.$lease->unit->asset_id],
                ['name' => 'Store '.$lease->unit->asset_id],
            );

            return StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'inventory_item_id' => $item->id,
                'type' => 'receipt',
                'quantity' => 1,
                'moved_on' => '2026-09-01',
            ]);
        },
        Payment::class => function ($t, $lease, $vendor, $item) {
            $payment = Payment::create([
                'tenant_id' => $t->id,
                'payment_date' => '2026-09-02',
                'amount' => 100,
                'method' => 'cash',
                'status' => 'captured',
            ]);
            // A payment reaches its property THROUGH the invoices it settles (`via: 'invoices'`),
            // so an unallocated one belongs to no mall and would prove nothing either way.
            $payment->invoices()->attach(makeInvoice($lease)->id, ['allocated_amount' => 100]);

            return $payment;
        },
    ];
}

it('shows no tab a row from a mall the operator does not hold', function () {
    $atRisk = CrossPropertyTabs::atRisk();
    $fixtures = tabFixtures();

    // **PIN THE COUNT, not "more than nothing".** A floor of one is satisfied by a derivation that
    // has stopped matching almost everything: mutating `atRisk()` to return a single tab left this
    // file green while the two tabs it exists to protect were no longer swept at all. Realistic
    // triggers, not hypothetical — adding `#[PropertyOwned]` to `Tenant` removes seven of the nine
    // in one edit. A tab ADDED must raise this number deliberately.
    expect($atRisk)->toHaveCount(9, 'the at-risk set changed — add the tab and its fixture, or say why it left');

    $uncovered = [];
    $leaks = [];
    $proven = 0;

    foreach ($atRisk as $tab) {
        if (! isset($fixtures[$tab['child']])) {
            $uncovered[] = class_basename($tab['manager']).' lists '.class_basename($tab['child']);

            continue;
        }

        $make = $fixtures[$tab['child']];

        $owner = match ($tab['owner']) {
            Vendor::class => $this->vendor,
            InventoryItem::class => $this->item,
            default => $this->tenant,
        };

        $mine = $make($this->tenant, $this->leaseMine, $this->vendor, $this->item);
        $theirs = $make($this->tenant, $this->leaseTheirs, $this->vendor, $this->item);

        $records = Livewire::test($tab['manager'], [
            'ownerRecord' => $owner,
            'pageClass' => EditRecord::class,
        ])->instance()->getTable()->getRecords();

        // A row is identified by CLASS AND KEY, never by key alone.
        //
        // A tab may be fed from `->records([...])` rather than a relationship — the tenant LEDGER
        // is, so a row is `Model|array` exactly as it is for `RowClickTarget` — and that ledger
        // numbers its array rows by POSITION while carrying the real record under `model`. Reading
        // `$row['id']` there compares an invoice id against 0. The ledger also interleaves invoices
        // and payments, so a bare key would let a payment vouch for an invoice of the same id.
        $seen = $records->map(function ($row) {
            $record = $row instanceof Model ? $row : ($row['model'] ?? null);

            return $record instanceof Model ? $record::class.'#'.$record->getKey() : null;
        })->filter()->all();

        $identify = fn (Model $record): string => $record::class.'#'.$record->getKey();

        // The CONTROL first: a sweep where nothing comes back satisfies every refusal below and
        // reads exactly like a pass.
        if (! in_array($identify($mine), $seen, true)) {
            $leaks[] = class_basename($tab['manager']).' hides the operator\'s OWN mall row';

            continue;
        }

        if (in_array($identify($theirs), $seen, true)) {
            $leaks[] = class_basename($tab['manager']).' shows a row from a mall the operator does not hold';

            continue;
        }

        // **THE BADGE IS THE SAME DISCLOSURE AS A ROW.** `CountsItsRows` counts the plain
        // relationship, so a narrowed tab can hand back as a NUMBER exactly what its scope
        // withholds as rows — "this retailer has 1 violation you are not allowed to read" — and
        // leave an operator a figure they cannot reconcile with the table under it. Asked of every
        // badged tab in the at-risk set, because the trait's own docblock forbids the combination
        // and nothing was checking.
        $badge = $tab['manager']::getBadge($owner, Filament\Resources\Pages\EditRecord::class);

        if ($badge !== null && (string) $badge !== (string) count($seen)) {
            $leaks[] = class_basename($tab['manager']).' badges '.$badge.' over '.count($seen).' rows';

            continue;
        }

        $proven++;
    }

    expect($uncovered)->toBe([], 'these tabs can leak and no fixture proves they do not');
    expect($leaks)->toBe([]);
    expect($proven)->toBeGreaterThan(0, 'nothing was actually proved');
});
