<?php

use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Models\TenantRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The operator has to be able to GIVE the answer, not just store one.
 *
 * A field the service demands and no screen can supply is worse than no field: every attempt to
 * resolve a permit would fail with a refusal the operator cannot satisfy. This drives the real
 * Filament action rather than the service, so the form schema and the service guard are proven to
 * agree.
 */
beforeEach(function () {
    // Without this the role exists but holds NO permissions, so the page 403s and every action
    // assertion below would fail for a reason that has nothing to do with the decision field.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->user = makeUser('manager', [$this->asset->id]);
    $this->actingAs($this->user);
    // Both are needed: a resource page resolves its resource through the CURRENT PANEL, and the
    // admin panel is tenanted, so without the panel the component never mounts.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function permitAwaitingAnswer(int $assetId): TenantRequest
{
    $unit = makeUnit(\App\Models\Asset::find($assetId));

    $request = makeTenantRequest([
        'unit_id' => $unit->id,
        'request_type' => 'permit',
        'category' => 'fit_out',
        'status' => 'in_progress',
    ]);

    // Satisfy the separate evidence gate so these cases exercise the decision path.
    $request->addMediaFromString('x')->usingFileName('site.jpg')->toMediaCollection('attachments');

    return $request->refresh();
}

it('records a rejection through the admin action, with its reason', function () {
    $request = permitAwaitingAnswer($this->asset->id);

    Livewire::test(ListTenantRequests::class)
        ->callAction(TestAction::make('changeStatus')->table($request), data: [
            'status' => 'resolved',
            'decision' => 'rejected',
            'decision_reason' => 'The hoarding blocks a fire exit.',
            'resolution_notes' => 'Refused; discussed on site.',
        ])
        ->assertHasNoActionErrors();

    $request->refresh();

    expect($request->status)->toBe('resolved')
        ->and($request->decision)->toBe('rejected')
        ->and($request->decision_reason)->toBe('The hoarding blocks a fire exit.')
        // The operator who answered is stamped from the acting user, not passed by the form.
        ->and($request->decided_by)->toBe($this->user->id);
});

it('records an approval through the admin action', function () {
    $request = permitAwaitingAnswer($this->asset->id);

    Livewire::test(ListTenantRequests::class)
        ->callAction(TestAction::make('changeStatus')->table($request), data: [
            'status' => 'resolved',
            'decision' => 'approved',
            'resolution_notes' => 'Approved for the requested window.',
        ])
        ->assertHasNoActionErrors();

    expect($request->refresh()->decision)->toBe('approved');
});

it('will not let the form resolve a permit with no answer', function () {
    $request = permitAwaitingAnswer($this->asset->id);

    // The form requires it, so this fails validation rather than reaching the service — which is
    // the point: the operator is stopped where they can see why, not by an exception.
    Livewire::test(ListTenantRequests::class)
        ->callAction(TestAction::make('changeStatus')->table($request), data: [
            'status' => 'resolved',
            'resolution_notes' => 'Done.',
        ])
        ->assertHasActionErrors(['decision']);

    expect($request->refresh()->status)->toBe('in_progress');
});

it('does not ask a maintenance request for an answer', function () {
    $unit = makeUnit($this->asset);
    $request = makeTenantRequest([
        'unit_id' => $unit->id,
        'request_type' => 'maintenance',
        'category' => 'plumbing',
        'status' => 'in_progress',
    ]);
    $request->addMediaFromString('x')->usingFileName('fixed.jpg')->toMediaCollection('attachments');

    Livewire::test(ListTenantRequests::class)
        ->callAction(TestAction::make('changeStatus')->table($request->refresh()), data: [
            'status' => 'resolved',
            'resolution_notes' => 'Seal replaced.',
        ])
        ->assertHasNoActionErrors();

    expect($request->refresh()->status)->toBe('resolved')
        ->and($request->decision)->toBeNull();
});
