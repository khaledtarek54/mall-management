<?php

/*
|--------------------------------------------------------------------------
| A finished job could show nothing for itself (2026-08-19)
|--------------------------------------------------------------------------
| The gap analysis carried this as "a work order closes on its checklist with no required
| evidence". Reading the code said something stronger: `FacilityWorkOrder` did not implement
| HasMedia at all, so there was no way to attach a photograph to a job in the first place. The
| missing thing was not the gate, it was the capability the gate would have guarded.
|
| That matters for a mall operator specifically, because a work order is the record that settles
| arguments later: with the tenant about what was done in their shop, with the vendor about
| whether the job justified the invoice, and with the insurer about the state of plant before a
| failure. None of those are winnable from a checklist of ticks.
|
| Two halves, deliberately separable:
|
|   1. attachments EXIST, on the private disk, uploadable after the job is closed;
|   2. requiring one is a SETTING, shipped off.
|
| The second ships off because switching it on mid-flight refuses the next completion every
| engineer attempts, on jobs they have already finished — and the reliable outcome of that is a
| photograph of a wall taken to clear the validation. Evidence collected to satisfy a gate is
| worse than none, because it looks like proof.
*/

use App\Models\FacilityWorkOrder;
use App\Services\FacilityWorkOrderService;
use App\Settings\SlaSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Storage::fake('local');
});

function jobReadyToClose($ctx): FacilityWorkOrder
{
    return FacilityWorkOrder::create([
        'asset_id' => $ctx->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'internal',
        'title' => 'Chiller 2 not holding temperature',
        'description' => 'Supply air at 19C against a 12C setpoint since Friday.',
        'category' => 'hvac',
        'status' => 'in_progress',
        'priority' => 'high',
        'scheduled_for' => now(),
    ]);
}

function attachEvidence(FacilityWorkOrder $order): void
{
    $order->addMedia(UploadedFile::fake()->image('after.jpg'))
        ->toMediaCollection('evidence');
}

it('keeps evidence on the private disk, never the webroot', function () {
    $order = jobReadyToClose($this);
    attachEvidence($order);

    $media = $order->fresh()->getMedia('evidence')->first();

    // medialibrary's default disk is env('MEDIA_DISK', 'public') — fail-open. This is the
    // assertion that a forgotten `useDisk()` cannot pass: a work-order photo shows the inside of
    // a tenant's shop and sometimes a person, and it must not be reachable by URL.
    expect($media->disk)->toBe('local');
});

it('closes a job with no evidence while the requirement is off', function () {
    app(SlaSettings::class)->fill(['require_completion_evidence' => false])->save();

    $order = jobReadyToClose($this);

    $closed = app(FacilityWorkOrderService::class)->transition($order, 'done');

    expect($closed->status)->toBe('done')
        ->and($closed->completed_at)->not->toBeNull();
});

it('refuses to close a job with no evidence once the requirement is on', function () {
    app(SlaSettings::class)->fill(['require_completion_evidence' => true])->save();

    $order = jobReadyToClose($this);

    expect(fn () => app(FacilityWorkOrderService::class)->transition($order, 'done'))
        ->toThrow(DomainException::class);

    // And it is a refusal, not a half-write: the job is still open, so an engineer can attach the
    // photo and finish rather than finding a `done` order with no completion stamp.
    expect($order->fresh()->status)->toBe('in_progress')
        ->and($order->fresh()->completed_at)->toBeNull();
});

/**
 * The control. The refusal above would pass just as happily against a guard that refused
 * everything, so the same setting must let the same job through once it carries a photo.
 */
it('closes the job once evidence is attached', function () {
    app(SlaSettings::class)->fill(['require_completion_evidence' => true])->save();

    $order = jobReadyToClose($this);
    attachEvidence($order);

    $closed = app(FacilityWorkOrderService::class)->transition($order->fresh(), 'done');

    expect($closed->status)->toBe('done');
});

/**
 * The guard belongs to the SERVICE, not to a form. `transition()` is the one road to `done` —
 * the Filament action, the console and any future API all arrive through it — whereas a form
 * guard protects one screen. Asserted by driving the service directly, which is the path a
 * screen-level guard would leave open.
 */
it('guards the console path too, not just the screen', function () {
    app(SlaSettings::class)->fill(['require_completion_evidence' => true])->save();

    $order = jobReadyToClose($this);

    // No Filament, no request — the seam a form-level check would miss entirely.
    expect(fn () => app(FacilityWorkOrderService::class)->transition($order, 'done'))
        ->toThrow(DomainException::class);
});

/**
 * Evidence stays uploadable after closure, and this is deliberate rather than an oversight in the
 * freeze. A photograph is the one thing an engineer legitimately adds after the fact: the job is
 * finished, the phone is in their pocket. Refusing it because the order reached a terminal state
 * is how a record ends up with no evidence at all — while the commercial fields stay frozen.
 */
it('still accepts evidence after the job is closed', function () {
    app(SlaSettings::class)->fill(['require_completion_evidence' => false])->save();

    $order = jobReadyToClose($this);
    $closed = app(FacilityWorkOrderService::class)->transition($order, 'done');

    attachEvidence($closed);

    expect($closed->fresh()->hasEvidence())->toBeTrue();
});
