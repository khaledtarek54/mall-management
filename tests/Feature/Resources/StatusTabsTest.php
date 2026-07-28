<?php

/**
 * Status tabs on the workflow list pages.
 *
 * Two properties matter and neither is visible from "the page renders":
 *
 *  1. A tab must actually narrow the list to its statuses.
 *  2. The badge count must agree with the tab's contents AND stay inside the
 *     property scope. A badge is a number rendered before you click anything,
 *     so a leak there is a silent cross-property disclosure — exactly the class
 *     of bug PropertyIsolation exists to stop.
 */

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Support\StatusTabs;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** A property with one leased unit — the minimum an invoice needs to exist. */
function stLeasedProperty(): array
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));

    return [$asset, $lease];
}

function stInvoice(Asset $asset, Lease $lease, string $status): Invoice
{
    return makeInvoice($lease, ['asset_id' => $asset->id, 'status' => $status]);
}

it('narrows the invoice list to the tab it is on', function () {
    [$asset, $lease] = stLeasedProperty();

    $draft = stInvoice($asset, $lease, 'draft');
    $issued = stInvoice($asset, $lease, 'issued');
    $overdue = stInvoice($asset, $lease, 'overdue');
    $paid = stInvoice($asset, $lease, 'paid');

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($draft, $issued, $overdue, $paid) {
        // Outstanding spans three statuses — issued + partially_paid + overdue —
        // so it must include the overdue one and exclude draft and paid.
        Livewire::test(ListInvoices::class, ['activeTab' => 'outstanding'])
            ->assertCanSeeTableRecords([$issued, $overdue])
            ->assertCanNotSeeTableRecords([$draft, $paid]);

        Livewire::test(ListInvoices::class, ['activeTab' => 'overdue'])
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$issued, $draft, $paid]);

        Livewire::test(ListInvoices::class, ['activeTab' => 'draft'])
            ->assertCanSeeTableRecords([$draft])
            ->assertCanNotSeeTableRecords([$issued, $overdue, $paid]);

        // The "all" tab narrows by nothing.
        Livewire::test(ListInvoices::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$draft, $issued, $overdue, $paid]);
    });
});

it('counts a tab badge over exactly the rows that tab shows', function () {
    [$asset, $lease] = stLeasedProperty();

    stInvoice($asset, $lease, 'issued');
    stInvoice($asset, $lease, 'partially_paid');
    stInvoice($asset, $lease, 'overdue');
    stInvoice($asset, $lease, 'draft');
    stInvoice($asset, $lease, 'paid');

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $tabs = StatusTabs::build(InvoiceResource::class, [
            'outstanding' => ['label' => 'Outstanding', 'statuses' => ['issued', 'partially_paid', 'overdue'], 'badge' => true],
            'draft' => ['label' => 'Draft', 'statuses' => ['draft'], 'badge' => true],
        ]);

        expect((int) $tabs['outstanding']->getBadge())->toBe(3)
            ->and((int) $tabs['draft']->getBadge())->toBe(1);
    });
});

it('keeps a tab badge inside the property scope', function () {
    // The regression this guards: a badge built off the bare model instead of
    // the resource's scoped query would count another mall's invoices and
    // display that number to someone with no access to them.
    [$assetA, $leaseA] = stLeasedProperty();
    [$assetB, $leaseB] = stLeasedProperty();

    stInvoice($assetA, $leaseA, 'overdue');
    stInvoice($assetB, $leaseB, 'overdue');
    stInvoice($assetB, $leaseB, 'overdue');

    $this->actingAs(makeUser('super_admin', [$assetA->id, $assetB->id]));

    $badgeIn = fn ($asset) => asTenant($asset, fn () => (int) StatusTabs::build(InvoiceResource::class, [
        'overdue' => ['label' => 'Overdue', 'statuses' => ['overdue'], 'badge' => true],
    ])['overdue']->getBadge());

    // 1 in A, 2 in B — never 3 in either.
    expect($badgeIn($assetA))->toBe(1)
        ->and($badgeIn($assetB))->toBe(2);
});
