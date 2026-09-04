<?php

/*
|--------------------------------------------------------------------------
| A corrective job can be handed to a contractor — SW-065 (2026-09-04)
|--------------------------------------------------------------------------
| `FacilityWorkOrderForm` offered BOTH assignee pickers on every work order and had no control at
| all for `execution_type`, the corrective classification the model enforces as a real XOR. So the
| single most ordinary act on the board — an in-house job that turns out to need a contractor —
| was a dead end in both directions.
|
| MEASURED 2026-09-04 on a fresh sqlite schema: create a `cm` with `execution_type = internal`
| carrying a technician, then `update(['vendor_id' => …])` exactly as this form does, and
| `FacilityWorkOrder::saving()` throws
|   InvalidArgumentException: An internal corrective work order is handled in-house; it cannot
|   also name a vendor.
| An `InvalidArgumentException` is NOT a `DomainException`, so `bootstrap/app.php` renders the 500
| PAGE rather than a toast and the operator loses the form. Sending the classification alongside
| the vendor does not help either — the technician is still on the row, so the mirror refusal
| fires. And there was no way to send the classification, because no screen offered it: measured
| read-only against `mall_management_qa`, 2 of the 5 corrective orders are `internal` and open.
|
| Every refusal below is paired with a control that must SUCCEED, because a form that hid or
| cleared everything would satisfy the "it saves" assertions on its own and be a different bug.
*/

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\SlaPenalty;
use App\Models\Trade;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    $this->trade = Trade::query()->where('code', 'hvac')->firstOrFail();
    $this->tech = makeUser('operations', [$this->asset->id]);
    $this->vendor = Vendor::create(['name' => 'CoolAir', 'status' => 'active']);
});

/**
 * One shape of corrective order, differing only in how it is classified and who holds it.
 *
 * A file-scope helper with a name that appears nowhere else under `tests/` — two files declaring
 * one name is a fatal redeclaration during collection, which exits the whole suite 255 with no
 * output on either stream.
 */
function handoverJob(array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Chiller down',
        'description' => 'No cooling on level 2',
        'trade_id' => test()->trade->id,
        'status' => 'open',
        'priority' => 'high',
        'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => test()->tech->id,
    ], $attrs));
}

it('hands an in-house corrective job to a contractor', function () {
    $cm = handoverJob();

    asTenant($this->asset, function () use ($cm) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $cm->getRouteKey()])
            // The field that did not exist: the classification had no screen anywhere in the panel
            // outside the three RAISE modals.
            ->assertFormFieldVisible('execution_type')
            // …and while the job is in-house the contractor picker is not offered at all, because
            // choosing one is exactly what the model refuses.
            ->assertFormFieldHidden('vendor_id')
            ->assertFormFieldVisible('assigned_to_user_id')
            ->fillForm([
                'execution_type' => 'external',
                'vendor_id' => $this->vendor->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $cm->refresh();

    expect($cm->execution_type)->toBe('external')
        ->and($cm->vendor_id)->toBe($this->vendor->id)
        // The technician came OFF the row. Without this the save is refused outright — an
        // InvalidArgumentException, i.e. the 500 page — because a hidden Filament field is not
        // dehydrated and the old assignee would still be standing there.
        ->and($cm->assigned_to_user_id)->toBeNull();
});

it('takes an external corrective job back in-house', function () {
    $cm = handoverJob([
        'execution_type' => 'external',
        'assigned_to_user_id' => null,
        'vendor_id' => $this->vendor->id,
    ]);

    asTenant($this->asset, function () use ($cm) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $cm->getRouteKey()])
            ->assertFormFieldHidden('assigned_to_user_id')
            ->assertFormFieldVisible('vendor_id')
            ->fillForm([
                'execution_type' => 'internal',
                'assigned_to_user_id' => $this->tech->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $cm->refresh();

    expect($cm->execution_type)->toBe('internal')
        ->and($cm->assigned_to_user_id)->toBe($this->tech->id)
        ->and($cm->vendor_id)->toBeNull();
});

it('discards a contractor sent for a job classified in-house', function () {
    // Past the picker AND past the browser hook. `afterStateUpdated` is a round trip, never a
    // gate: the Livewire payload still carries whatever the client puts in it, exactly as the
    // property field's own docblock says of a disabled input.
    $cm = handoverJob([
        'execution_type' => 'external',
        'assigned_to_user_id' => null,
        'vendor_id' => $this->vendor->id,
    ]);

    asTenant($this->asset, function () use ($cm) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $cm->getRouteKey()])
            ->fillForm([
                'execution_type' => 'internal',
                'assigned_to_user_id' => $this->tech->id,
            ])
            ->set('data.vendor_id', $this->vendor->id)
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($cm->fresh()->vendor_id)->toBeNull()
        // The control: the save really did happen, so the assertion above is not passing because
        // nothing was written.
        ->and($cm->fresh()->assigned_to_user_id)->toBe($this->tech->id);
});

it('saves a job whose technician has since been moved to another mall', function () {
    // An ordinary consequence of the user form's property-assignment field: the technician still
    // holds this job, and `technicianOptions()` no longer offers them. The excluded side has to be
    // CLEARED as well as hidden — a hidden field that is still dehydrated is still VALIDATED, so a
    // stale assignee would fail an `in:` rule on a field the operator can no longer see.
    $movedTech = makeUser('operations', [makeAsset()->id]);
    $cm = handoverJob(['assigned_to_user_id' => $movedTech->id]);

    asTenant($this->asset, function () use ($cm) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $cm->getRouteKey()])
            ->fillForm([
                'execution_type' => 'external',
                'vendor_id' => $this->vendor->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    expect($cm->fresh()->assigned_to_user_id)->toBeNull()
        ->and($cm->fresh()->vendor_id)->toBe($this->vendor->id);
});

it('leaves a preventive order carrying both a technician and a vendor', function () {
    // The control for the whole change. A PPM order is NOT classified — `FacilityWorkOrder
    // ::saving()` returns before the XOR for it — and a plan legitimately splits between the
    // in-house team and a contractor. Both pickers must stay, and the classification must not be
    // offered at all.
    $ppm = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'ppm',
        'title' => 'Quarterly chiller service',
        'trade_id' => $this->trade->id,
        'status' => 'open',
        'priority' => 'medium',
        'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => $this->tech->id,
        'vendor_id' => $this->vendor->id,
    ]);

    asTenant($this->asset, function () use ($ppm) {
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $ppm->getRouteKey()])
            ->assertFormFieldHidden('execution_type')
            ->assertFormFieldVisible('assigned_to_user_id')
            ->assertFormFieldVisible('vendor_id')
            ->fillForm(['title' => 'Quarterly chiller service (revised)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });

    $ppm->refresh();

    expect($ppm->title)->toBe('Quarterly chiller service (revised)')
        ->and($ppm->execution_type)->toBeNull()
        ->and($ppm->assigned_to_user_id)->toBe($this->tech->id)
        ->and($ppm->vendor_id)->toBe($this->vendor->id);
});

it('still offers the contractor a penalty was assessed against after the job goes in-house', function () {
    // The consequence of the change above, and the reason the deduction modal now asks the
    // PENALTY's vendor rather than the ORDER's. `ApplySlaPenaltyService::assertBillEligible()`
    // compares `$bill->vendor_id` against `$penalty->vendor_id`, so a list keyed on the order was
    // already asking a different question from its own gate — harmless only while the two could
    // never differ, which taking a job back in-house is precisely what makes possible.
    $cm = handoverJob([
        'execution_type' => 'external',
        'assigned_to_user_id' => null,
        'vendor_id' => $this->vendor->id,
    ]);

    SlaPenalty::create([
        'facility_work_order_id' => $cm->id,
        'asset_id' => $this->asset->id,
        'vendor_id' => $this->vendor->id,
        'basis' => 'flat',
        'rate' => 500,
        'hours_over_sla' => 5,
        'amount' => 500,
        'status' => SlaPenalty::STATUS_FINAL,
        'finalised_at' => now(),
    ]);

    $bill = VendorBill::create([
        'vendor_id' => $this->vendor->id,
        'asset_id' => $this->asset->id,
        'category' => 'maintenance',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'description' => 'The job as actually invoiced',
        'subtotal' => 12000,
        'vat_amount' => 0,
    ]);

    $cm->update([
        'execution_type' => 'internal',
        'vendor_id' => null,
        'assigned_to_user_id' => $this->tech->id,
    ]);

    asTenant($this->asset, function () use ($cm, $bill) {
        Livewire::test(ListFacilityWorkOrders::class)
            ->mountTableAction('charge_penalty', $cm->getKey())
            ->assertFormFieldExists('vendor_bill_id', function ($field) use ($bill): bool {
                $ids = array_map('intval', array_keys($field->getOptions() ?? []));

                return in_array((int) $bill->getKey(), $ids, true);
            });
    });
});
