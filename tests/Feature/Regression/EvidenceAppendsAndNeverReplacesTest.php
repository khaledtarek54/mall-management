<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorContact;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Evidence APPENDS. A second upload must never delete the first.
 *
 * This is a stated invariant of the vendor portal (modules/12b): "evidence appends and never
 * replaces — the completion gate reads that collection, and a replace would erase what an earlier
 * decision rested on". `SlaSettings::require_completion_evidence` makes it load-bearing: the
 * operator's decision to complete a job rests on photographs that must still be there.
 *
 * Why it needs a test rather than a reading. The action's schema carries `->appendFiles()`, which
 * is a FileUpload option about how the browser widget behaves when you drop a second file — it is
 * not a promise about what the server does on save. The server side is
 * `SpatieMediaLibraryFileUpload::deleteAbandonedFiles()`, which deletes every medium in the
 * collection whose uuid is absent from the component's state. So everything turns on whether the
 * modal's state was HYDRATED from the existing collection when it opened. A modal that opens empty
 * makes every existing photograph "abandoned", and the delete is silent.
 *
 * Two doors on one collection, so both are pinned: the contractor's, on the vendor portal, and the
 * operator's `attachEvidence` on the admin work-order table. A regression in either erases the
 * other's evidence.
 */
beforeEach(function () {
    Storage::fake('local');
    // The admin door is reached through the real list page, whose canAccess() needs the catalogue.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->vendor = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->contact = VendorContact::create([
        'vendor_id' => $this->vendor->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);

    $this->job = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $this->vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
        'status' => 'in_progress',
    ]);
});

/** Attach one photograph the way the model does, so the fixture is the real collection. */
function existingEvidence(FacilityWorkOrder $job, string $name): void
{
    // From a STRING, not from UploadedFile::fake(): the fake's temporary file is reclaimed before
    // medialibrary opens it, which fails as "file does not exist" and looks like a bug in the code
    // under test rather than in the fixture.
    $job->addMediaFromString('evidence-bytes')
        ->usingFileName($name)
        ->toMediaCollection('evidence');
}

it('keeps the first photograph when a contractor uploads a second', function () {
    existingEvidence($this->job, 'before.jpg');
    expect($this->job->fresh()->getMedia('evidence'))->toHaveCount(1);   // the control

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->callAction(
            TestAction::make('evidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->image('after.jpg')]],
        );

    $names = $this->job->fresh()->getMedia('evidence')->pluck('file_name')->all();

    expect($names)->toContain('before.jpg')
        ->and($names)->toHaveCount(2);
});

it('keeps the contractor photograph when the operator attaches one from the admin table', function () {
    existingEvidence($this->job, 'contractor.jpg');

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset, isQuiet: true);

    Livewire::test(ListFacilityWorkOrders::class)
        ->callAction(
            TestAction::make('attachEvidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->image('operator.jpg')]],
        );

    $names = $this->job->fresh()->getMedia('evidence')->pluck('file_name')->all();

    expect($names)->toContain('contractor.jpg')
        ->and($names)->toHaveCount(2);
});
