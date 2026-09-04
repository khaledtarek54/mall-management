<?php

/*
|--------------------------------------------------------------------------
| The contractor's job list opened on the archive (SW-070)
|--------------------------------------------------------------------------
| `VendorScope::VISIBLE_STATUSES` shows a contractor their `done` and `cancelled` jobs
| DELIBERATELY — they must be able to read back what they did, which is what makes the thread and
| the evidence worth having after the fact. Nothing narrowed the list, so that archive is all the
| list ever grows, and it buried the work.
|
| Measured on the QA baseline (2026-09-03): the contractor with the most dispatches holds 13 jobs —
| 10 `done`, 3 `open`. Ordered `scheduled_for asc`, rows 1 to 10 were finished work, the top row
| scheduled 2 September 2024, and the three jobs they are expected to turn up to were rows 11, 12
| and 13. The sort was never wrong — this list is registered `TableSortPolicy::WORKLIST`, "soonest
| first: the top row is the next thing to do" — it simply had nothing left to sort.
|
| One MULTIPLE status filter defaulted to the live statuses, not a "live only" toggle beside a
| status picker: two controls arguing over one column produce an empty list with two indicators and
| no way to read which one refused.
*/

use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorContact;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->mine = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->rival = Vendor::create(['name' => 'Rival Mechanical', 'status' => Vendor::STATUS_ACTIVE]);

    $this->contact = VendorContact::create([
        'vendor_id' => $this->mine->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);
});

/** A job dispatched to `$vendor` — defaults to the signed-in contractor's own company. */
function dispatchedJob(array $attrs = [], ?Vendor $vendor = null): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => ($vendor ?? test()->mine)->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down on the second floor',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'status' => 'open',
        'scheduled_for' => '2026-09-05',
    ], $attrs));
}

it('opens on the work the contractor still has to do', function () {
    $live = dispatchedJob(['title' => 'Chiller still down', 'scheduled_for' => '2026-09-05']);
    $finished = dispatchedJob([
        'title' => 'Last September\'s visit',
        'status' => 'done',
        'scheduled_for' => '2024-09-02',
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$finished]);
});

it('still lets the contractor read back what they did', function () {
    // The control, and it guards a DELIBERATE decision rather than a nicety: `VendorScope`'s own
    // docblock says `done` and `cancelled` are visible on purpose. A default that could not be
    // cleared would have closed the archive instead of moving it out of the way.
    $live = dispatchedJob(['title' => 'Chiller still down']);
    $finished = dispatchedJob([
        'title' => 'Last September\'s visit',
        'status' => 'done',
        'scheduled_for' => '2024-09-02',
    ]);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->filterTable('status', ['done'])
        ->assertCanSeeTableRecords([$finished])
        ->assertCanNotSeeTableRecords([$live]);
});

it('orders the work that is left soonest-first', function () {
    // The later job is created FIRST, so insertion order and the intended order disagree — without
    // that this passes on a list that is not sorted at all.
    $later = dispatchedJob(['title' => 'Quarterly service', 'scheduled_for' => '2026-09-20']);
    $sooner = dispatchedJob(['title' => 'Chiller still down', 'scheduled_for' => '2026-09-01']);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->assertCanSeeTableRecords([$sooner, $later], inOrder: true);
});

it('never lets a filter reach another company\'s job', function () {
    // A filter is the one thing this screen now takes from the reader, so the question has to be
    // asked rather than reasoned about: `WorkOrderResource::getEloquentQuery()` is already
    // `VendorScope::jobs()`, and a filter composes INSIDE it — but the same "it obviously cannot
    // widen" reasoning is what put an OR branch outside a property scope elsewhere in this app.
    $mine = dispatchedJob(['title' => 'My finished job', 'status' => 'done', 'scheduled_for' => '2026-08-01']);
    $theirs = dispatchedJob(
        ['title' => 'Their finished job', 'status' => 'done', 'scheduled_for' => '2026-08-01'],
        $this->rival,
    );

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->filterTable('status', ['done'])
        // The control: the filter really did fire, so the refusal below is the SCOPE and not an
        // empty list.
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('offers the contractor a way to narrow the list at all', function () {
    // The other half of the row: the list had no filters. Both are labelled from the portal's own
    // vocabulary, so neither reads in English on the Arabic panel.
    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->assertTableFilterExists('status')
        ->assertTableFilterExists('priority');
});
