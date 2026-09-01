<?php

use App\Filament\Actions\EvidenceUpload;
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
        'trade_id' => tradeId('hvac'),
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

/*
|--------------------------------------------------------------------------
| A technician could be blocked from finishing and unable to unblock themselves
|--------------------------------------------------------------------------
| The operator's decision (2026-08-20) is that there is NO technician app — a technician signs into
| the admin panel under the `technician` role. That makes the role's own experience a requirement
| rather than a fallback, and checking it against that standard found a deadlock built from two
| features that are each correct alone:
|
|   `SlaSettings::$require_completion_evidence` refuses a technician's completion until a photograph
|   is attached — and the evidence field lives on the work-order form, which needs `facility.edit`,
|   a permission the role deliberately does not hold.
|
| Fixed with an **Attach a photo** action gated on `facility.complete` — the same right that lets
| them finish — rather than by widening `facility.edit`, which would also let a technician re-home a
| job, change its vendor and edit its commercial fields.
*/

it('lets a technician attach the evidence their own completion requires', function () {
    $tech = makeUser('technician', [$this->asset->id]);

    // The gate that blocks them…
    expect($tech->can('facility.complete'))->toBeTrue()
        // …and the one they do NOT hold, which is why the form is unreachable.
        ->and($tech->can('facility.edit'))->toBeFalse();

    // The attach action is gated on the right they DO hold, so the deadlock cannot re-form.
    $source = file_get_contents(base_path(
        'app/Filament/Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable.php'
    ));

    expect($source)->toContain("Action::make('attachEvidence')");

    $action = substr($source, strpos($source, "Action::make('attachEvidence')"));
    $action = substr($action, 0, strpos($action, "Action::make('complete')"));

    // The GATE is a fact about this action, so it is read here…
    expect($action)->toContain('self::canComplete()')
        // …and the FIELD is not: `collection('evidence')` moved into `App\Filament\Actions\EvidenceUpload`
        // when the append-only fix gave the operator's door and the contractor's door one definition
        // (42e21d0b). This assertion went red on that commit and stayed red, because CI is paused and
        // a red push here is silent rather than a red check.
        //
        // Rather than chase the string into its new file, the two halves are now asserted where each
        // is actually true: that this action composes the shared definition, and that the shared
        // definition targets the evidence collection. That is stronger than the original — a source
        // match on `collection('evidence')` would be satisfied by a second, private upload component
        // beside the shared one, which is precisely the drift the extraction removed.
        ->and($action)->toContain('EvidenceUpload::make()');

    expect(EvidenceUpload::make()->getCollection())->toBe('evidence')
        ->and(EvidenceUpload::make()->isMultiple())->toBeTrue();
});
