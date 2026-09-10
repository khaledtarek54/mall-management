<?php

/*
|--------------------------------------------------------------------------
| Evidence is owed by WORK, not by every request (SW-246)
|--------------------------------------------------------------------------
| Found on the staging soak, 2026-09-10, by doing the operator's job rather than testing it: a noise
| COMPLAINT and a parking-permit ACCESS request could not be resolved AT ALL. Both answered
| *"Attach a photo of the completed work, or raise a work order for it"* — and there is no photograph
| of having spoken to the neighbours, nor any sense in raising a work order to issue a parking permit.
| They sat `in_progress` with no legal way forward.
|
| The gate was wrong in BOTH directions, which is what makes it worth a test rather than a one-liner.
|
|  1. **Too wide.** It applied to all EIGHT `TenantRequestType` cases. FR-USR-06 asks for evidence
|     *"before a request can be marked complete"*, and that was written when this module was
|     maintenance-only; module 11 later generalised Maintenance into typed requests and the rule was
|     never generalised with it. The gate immediately below it — `requiresDecision()` — is type-aware,
|     so the vocabulary to say this properly already existed.
|
|  2. **Vacuous where it mattered.** It read `hasMedia('attachments')` — the collection the TENANT
|     uploads to from the portal's own submission form, i.e. a photo of the PROBLEM. So a maintenance
|     request was "evidenced" by the tenant's own photo of the fault and could be resolved with
|     nothing whatever to show for the work. The one type the rule was written for was the one type
|     it did not hold for.
|
| The split — evidence for WORK, an ANSWER for a question, a DECISION for a request — is an ATRIOM
| decision rather than a citation: `docs/benchmarks/` has ServiceChannel requiring a RESOLUTION at
| check-out (configuration-sensitive, never type-sensitive) and no Yardi service-request material at
| all. Nothing closes on nothing — every resolve requires `resolution_notes`, refused in the SERVICE
| so the claim holds on the portal and the mobile API too, and three types still owe approve/reject.
|
| Every exemption is paired with the refusal it must not weaken: if this only proved that complaints
| now resolve, deleting the gate entirely would satisfy it.
*/

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Models\TenantRequest;
use App\Services\RaiseCorrectiveWorkOrderService;
use App\Services\TenantRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->manager = makeUser('manager', [$this->asset->id]);
    $this->actingAs($this->manager);
    Notification::fake();

    $this->svc = app(TenantRequestService::class);
});

/** A request of `$type`, walked as far as `in_progress` — the state a resolve is attempted from. */
function sw246Request($ctx, TenantRequestType $type, ?string $category = null): TenantRequest
{
    $lease = makeLease(makeUnit($ctx->asset));

    $request = TenantRequest::create([
        'reference' => 'TR-'.uniqid(),
        'tenant_id' => $lease->tenant_id,
        'unit_id' => $lease->unit_id,
        'lease_id' => $lease->id,
        'request_type' => $type->value,
        'status' => 'submitted',
        'priority' => 'medium',
        'category' => $category,
        'channel' => 'portal',
        'title' => 'Soak fixture',
        'description' => 'Raised by the SW-246 regression test.',
        'submitted_at' => now(),
    ]);

    $ctx->svc->transition($request, 'acknowledged');
    $ctx->svc->transition($request->fresh(), 'in_progress');

    return $request->fresh();
}

/** Resolving states the outcome: a note always, plus a decision for the types that asked. */
function sw246Resolve($ctx, TenantRequest $request, array $extra = []): TenantRequest
{
    return $ctx->svc->transition($request->fresh(), 'resolved', $extra + [
        'resolution_notes' => 'Spoke to the food court operators; music off at 22:00 from tonight.',
    ]);
}

it('resolves a complaint on its resolution note, with no photograph of a conversation', function () {
    $complaint = sw246Request($this, TenantRequestType::Complaint, 'noise');

    expect(sw246Resolve($this, $complaint)->status)->toBe('resolved');
});

it('resolves an access request on its decision, with no work order to raise', function () {
    $access = sw246Request($this, TenantRequestType::Access, 'parking');

    $resolved = sw246Resolve($this, $access, ['decision' => 'approved']);

    expect($resolved->status)->toBe('resolved')
        ->and($resolved->decision)->toBe('approved');
});

it('STILL refuses to resolve a maintenance request with nothing to show for the work', function () {
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');

    expect(fn () => sw246Resolve($this, $maintenance))->toThrow(DomainException::class);

    expect($maintenance->fresh()->status)->toBe('in_progress');
});

it('does NOT accept the tenant\'s own photo of the problem as evidence of the fix', function () {
    // The vacuous half, and the sharpest tooth here: before this change the gate read exactly this
    // collection, so uploading the fault photo made the request resolvable with no work evidenced.
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');

    $maintenance->addMedia(UploadedFile::fake()->image('the-leak-as-reported.jpg'))
        ->toMediaCollection('attachments');

    expect($maintenance->fresh()->hasMedia('attachments'))->toBeTrue();

    expect(fn () => sw246Resolve($this, $maintenance))->toThrow(DomainException::class);
});

it('accepts the operator\'s evidence of the completed work', function () {
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');

    $maintenance->addMedia(UploadedFile::fake()->image('condenser-recharged.jpg'))
        ->toMediaCollection('resolution_evidence');

    expect(sw246Resolve($this, $maintenance->fresh())->status)->toBe('resolved');
});

it('accepts a linked work order instead — the other half of the rule, unchanged', function () {
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');

    app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($maintenance, [
        'execution_type' => 'internal',
        'priority' => 'high',
        'title' => 'A-04 — AC blowing warm air',
        'description' => 'Raised from the tenant request.',
        'scheduled_for' => now()->toDateString(),
        'assigned_to_user_id' => $this->manager->id,
    ]);

    expect($maintenance->fresh()->hasLinkedWorkOrder())->toBeTrue()
        ->and(sw246Resolve($this, $maintenance)->status)->toBe('resolved');
});

it('refuses to resolve ANYTHING without saying what was done', function () {
    // The exemption for the other seven types rests on every resolve owing an answer instead. That
    // was a rule on the admin FORM only until SW-246 — i.e. one the portal and the mobile client
    // skip — so it is guarded in the service, where the claim can actually be true.
    $complaint = sw246Request($this, TenantRequestType::Complaint, 'noise');

    expect(fn () => $this->svc->transition($complaint, 'resolved', ['resolution_notes' => '  ']))
        ->toThrow(DomainException::class);

    expect($complaint->fresh()->status)->toBe('in_progress');
});

it('keeps the evidence PRIVATE — the collection declares its disk', function () {
    // Drop the `addMediaCollection('resolution_evidence')->useDisk('local')` registration and
    // everything still works: uploads land, the gate passes, and `MediaPrivacyConformanceTest`
    // stays green because it iterates REGISTERED collections and this one just left the list.
    // The media would fall to medialibrary's default disk, which is `public`. Fail-open, through
    // the one door that gate cannot see — so the disk is pinned here.
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');

    $maintenance->addMedia(UploadedFile::fake()->image('condenser.jpg'))
        ->toMediaCollection('resolution_evidence');

    expect($maintenance->fresh()->getFirstMedia('resolution_evidence')->disk)->toBe('local');
});

it('saves what the Attach evidence action uploads, and appends rather than replaces', function () {
    // The subtle case `EvidenceUpload` exists for: a SpatieMediaLibraryFileUpload inside an ACTION
    // modal saves through `saveRelationshipsUsing()`, and a plain upload REPLACES the collection.
    // The work-order door has two tests for exactly this; this one had none.
    $maintenance = sw246Request($this, TenantRequestType::Maintenance, 'hvac');
    $maintenance->addMedia(UploadedFile::fake()->image('first-visit.jpg'))
        ->toMediaCollection('resolution_evidence');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset, isQuiet: true);

    Livewire::test(ListTenantRequests::class)
        ->callAction(
            TestAction::make('attachEvidence')->table($maintenance),
            data: ['resolution_evidence' => [UploadedFile::fake()->image('second-visit.jpg')]],
        );

    $names = $maintenance->fresh()->getMedia('resolution_evidence')->pluck('file_name')->all();

    // TWO files: the modal's upload SAVED (the `saveRelationshipsUsing` half) and the earlier visit
    // SURVIVED (the `appendFiles` half — a plain upload replaces the collection, which is what the
    // shared `EvidenceUpload` exists to prevent).
    //
    // Asserted by COUNT plus the surviving name, not by the uploaded one: Livewire's temporary-upload
    // handling renames the incoming file to a ULID, so `second-visit.jpg` is never the stored
    // `file_name` and asserting it fails for a reason that has nothing to do with this rule.
    expect($names)->toHaveCount(2)
        ->and($names)->toContain('first-visit.jpg');
});

it('keeps every other type answerable — the sweep, so a ninth type is a decision and not an accident', function () {
    // `requiresCompletionEvidence()` is a match() over the enum: this asserts the WHOLE split rather
    // than the two cases the soak happened to hit.
    $owed = collect(TenantRequestType::cases())
        ->filter(fn (TenantRequestType $t) => $t->requiresCompletionEvidence())
        ->map(fn (TenantRequestType $t) => $t->value)
        ->values()
        ->all();

    expect($owed)->toBe(['maintenance']);
});
