<?php

use App\Filament\Admin\Resources\WorkPermits\Pages\EditWorkPermit;
use App\Models\Vendor;
use App\Models\WorkPermit;
use App\Services\WorkPermitService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-066 — an issued work permit was fully editable through its own form.
 *
 * The register hides its Edit shortcut the moment a permit is issued, under a comment stating the
 * rule in as many words: *"a live authorisation is not a draft"*. That is a RENDERING decision.
 * `EditWorkPermit` is the record hub, it is reached by URL, `canEdit()` went on answering true, and
 * nothing at the model refused the save — so what the permit authorises could be rewritten after
 * people were already working under it.
 *
 * What a permit authorises — the work, the place, the window, the conditions, who is doing it — is
 * what the guard at the door reads, and what a manager acts on when the overdue-closure alert fires
 * at the top of the hour. A hot-works permit quietly re-pointed at another unit, or its window
 * extended, is the failure this module exists to prevent.
 *
 * **A denylist of substance, not an allowlist of what the acts write.** `getDirty()` is read after
 * every `saving` hook, so an allowlist refuses any save carrying a column another hook derived — the
 * trap the lease holdover carve-out records. And `canEdit()` is deliberately not the lever: the acts
 * live on the record page and gate on `canIssue()`, so refusing the page would strand *close* and
 * *cancel* for exactly the permits that need them.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'WPM']);

    $this->permit = WorkPermit::create([
        'asset_id' => $this->asset->id,
        'type' => 'hot_work',
        'contractor_name' => 'Nile Welding',
        'location' => 'Roof plant room',
        'description' => 'Weld the chilled-water bracket',
        'conditions' => 'Fire watch for 60 minutes after work stops.',
        'valid_from' => now()->toDateTimeString(),
        'valid_to' => now()->addHours(6)->toDateTimeString(),
        'status' => WorkPermit::STATUS_DRAFT,
    ]);
});

it('lets a DRAFT be corrected freely', function () {
    // The control, and the reason this is a freeze rather than a lock: a permit being prepared is
    // exactly what the form is for.
    $this->permit->update(['location' => 'Basement plant room', 'conditions' => 'Two extinguishers.']);

    expect($this->permit->fresh()->location)->toBe('Basement plant room');
});

it('refuses to change what an ISSUED permit authorises', function () {
    app(WorkPermitService::class)->issue($this->permit, makeUser());

    expect($this->permit->fresh()->status)->toBe(WorkPermit::STATUS_ISSUED);

    // Measured before: every one of these saved. The guard at the door would have been reading a
    // permit for different work, in a different place, under different conditions.
    // EVERY column of the denylist, because a per-column mutation run found SEVEN of the thirteen
    // unproved by the first version of this test — including `vendor_id`, which is the only door
    // around `isDispatchable()` (a blacklisted contractor is refused at ISSUE and could be swapped
    // in afterwards), and `asset_id`, the property-isolation column.
    foreach ([
        ['type' => 'confined_space'],
        ['vendor_id' => Vendor::create(['name' => 'Another Contractor', 'type' => 'contractor', 'status' => 'active'])->id],
        ['contractor_name' => 'Someone else'],
        ['contractor_phone' => '+201000000000'],
        ['unit_id' => makeUnit($this->asset)->id],
        ['location' => 'Somewhere else'],
        ['description' => 'Different work entirely'],
        ['conditions' => 'No fire watch needed.'],
        ['valid_from' => now()->subDays(2)->toDateTimeString()],
        ['valid_to' => now()->addDays(3)->toDateTimeString()],
        ['asset_id' => makeAsset(['code' => 'OTH'])->id],
        // WHO authorised the hazardous work, and WHEN. `issue()` is their only writer and runs from
        // `draft`, where this guard has already returned — so freezing them costs nothing.
        ['issued_at' => now()->subDays(30)->toDateTimeString()],
        ['issued_by_user_id' => makeUser()->id],
    ] as $change) {
        expect(fn () => $this->permit->fresh()->update($change))
            ->toThrow(DomainException::class, __('admin.refusals.work_permit_issued_is_fixed'));
    }

    $fresh = $this->permit->fresh();

    expect($fresh->location)->toBe('Roof plant room')
        ->and($fresh->conditions)->toBe('Fire watch for 60 minutes after work stops.');
});

it('still lets the ACTS write their own columns', function () {
    // The freeze must not lock the permit's own workflow — which is what an allowlist of "what the
    // form sends" would have done, and what refusing `canEdit()` would have done to the record page
    // the acts live on.
    app(WorkPermitService::class)->issue($this->permit, makeUser());

    app(WorkPermitService::class)->close($this->permit->fresh(), 'Area made safe, fire watch complete.', makeUser());

    expect($this->permit->fresh()->status)->toBe(WorkPermit::STATUS_CLOSED)
        ->and($this->permit->fresh()->closure_notes)->toContain('made safe');
});

it('keeps a CLOSED permit fixed too', function () {
    // A closed permit is the record of what was authorised and how it ended. It is evidence.
    app(WorkPermitService::class)->issue($this->permit, makeUser());
    app(WorkPermitService::class)->close($this->permit->fresh(), 'Done.', makeUser());

    expect(fn () => $this->permit->fresh()->update(['description' => 'Something else entirely']))
        ->toThrow(DomainException::class);
});

it('refuses the reference itself — the number quoted at the gate', function () {
    // Not fillable, so no form reaches it; `update()` cannot either, which is why the assertion
    // writes the attribute directly. It is still the number on the permit taped to the hoarding.
    app(WorkPermitService::class)->issue($this->permit, makeUser());

    $live = $this->permit->fresh();
    $live->reference = 'PTW-9999-0001';

    expect(fn () => $live->save())->toThrow(DomainException::class);
});

it('does not let a permit be sent back to DRAFT', function () {
    // The route around the whole freeze: closed → draft → rewrite everything → issue again, a
    // second authorisation on one reference with the previous closure still on the row. Nothing in
    // the panel offers it; the guard holds anyway, because an import or an API write is the standard
    // the window guard three lines above it already sets for itself.
    app(WorkPermitService::class)->issue($this->permit, makeUser());
    app(WorkPermitService::class)->close($this->permit->fresh(), 'Done.', makeUser());

    expect(fn () => $this->permit->fresh()->update(['status' => WorkPermit::STATUS_DRAFT]))
        ->toThrow(DomainException::class);
});

it('still lets an issued permit be CANCELLED, and a lapsed one CLOSED', function () {
    // Two workflows the first version of this test never exercised. Cancelling is the correction
    // path the refusal itself points at, so a freeze that blocked it would leave an operator with a
    // wrong live permit and nothing to do about it. And closing LATE is documented as supported —
    // refusing it would leave the register permanently wrong about a job that did finish safely.
    $cancelled = $this->permit;
    app(WorkPermitService::class)->issue($cancelled, makeUser());
    app(WorkPermitService::class)->cancel($cancelled->fresh(), 'Superseded by a corrected permit.', makeUser());
    expect($cancelled->fresh()->status)->toBe(WorkPermit::STATUS_CANCELLED);

    $lapsed = WorkPermit::create([
        'asset_id' => $this->asset->id,
        'type' => 'hot_work',
        'contractor_name' => 'Nile Welding',
        'location' => 'Roof plant room',
        'description' => 'Weld the bracket',
        'conditions' => 'Fire watch.',
        'valid_from' => now()->subDays(3)->toDateTimeString(),
        'valid_to' => now()->subDays(2)->toDateTimeString(),
        'status' => WorkPermit::STATUS_DRAFT,
    ]);
    app(WorkPermitService::class)->issue($lapsed, makeUser());
    app(WorkPermitService::class)->close($lapsed->fresh(), 'Closed late — area was made safe on the day.', makeUser());

    expect($lapsed->fresh()->status)->toBe(WorkPermit::STATUS_CLOSED);
});

it('does not refuse a save the operator never made', function () {
    // The defect the FORM half exists for, and the one nothing could have avoided. The window
    // pickers carry `->seconds(false)`, so filling the record from an untouched form truncated
    // `valid_from`/`valid_to` to the minute and made them dirty against a stored value carrying
    // seconds — pressing **Save without touching anything** was refused. Every permit `DemoSeeder`
    // writes comes from `Carbon::now()`, so that is the ordinary state of a real row; the fixtures
    // that missed it all parse a zero-second literal, which is why this one does not.
    $this->permit->update([
        'valid_from' => now()->setTime(9, 15, 37)->toDateTimeString(),
        'valid_to' => now()->setTime(17, 45, 12)->toDateTimeString(),
    ]);
    app(WorkPermitService::class)->issue($this->permit, makeUser());

    // `setTenant()` reads the signed-in user, so sign in FIRST or it throws on a null actor; and
    // the panel has to be current or the page resolves no resource.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(EditWorkPermit::class, ['record' => $this->permit->getKey()])
        ->call('save')
        ->assertHasNoErrors();

    Filament::setTenant(null, isQuiet: true);

    // …and it really was a no-op: the seconds the operator never touched are still there.
    expect($this->permit->fresh()->valid_from->format('s'))->toBe('37');
});
