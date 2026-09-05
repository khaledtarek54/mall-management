<?php

use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorContact;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SW-126 — one collection, three doors, three different answers to "may I file this?".
 *
 * `FacilityWorkOrder`'s `evidence` collection is written from three places: the work-order FORM, the
 * operator's `attachEvidence` modal on the admin table, and the contractor's own `evidence` verb on
 * the vendor portal. The form accepted `image/*` and `application/pdf`, capped each file at 10 MB and
 * the batch at 10. `EvidenceUpload` — the shared factory behind the other two — said `->image()` and
 * capped nothing.
 *
 * So the completion certificate, the signed hot-work permit and the supplier's report — the
 * documents evidence actually arrives as — were takeable on the edit form and refused at the button
 * labelled *Attach evidence*, and refused again at the contractor's door, which is the whole point of
 * the vendor portal ("the photograph stops living in somebody's WhatsApp"). `mimetypes:image/*` is a
 * server-side rule, so this was a refusal and not a hint. And the shared field was the one with no
 * size cap at all, on a private disk written to by an external contractor, leaving Livewire's 12 MB
 * temporary-upload default as the entire bound.
 *
 * Driven through the real modals rather than read off the factory: a call site can override anything
 * the factory sets, so asking the factory would be asking the wrong object.
 */
beforeEach(function () {
    Storage::fake('local');
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

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Sign in as the contractor whose job this is, on their own panel. */
function asTheContractor(): void
{
    test()->actingAs(test()->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));
}

/** Sign in as the operator, on the admin panel, standing in the job's mall. */
function asTheOperator(): void
{
    test()->actingAs(makeUser('super_admin', [test()->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(test()->asset, isQuiet: true);
}

/**
 * What one door will accept, read off a MOUNTED schema.
 *
 * @return array{types: array<int, string>, max_kb: ?int, max_files: ?int}
 */
function evidenceFieldOn(object $component, string $schema): array
{
    $field = $component->instance()->{$schema}->getFlatFields(withHidden: true)['evidence'] ?? null;

    expect($field)->toBeInstanceOf(SpatieMediaLibraryFileUpload::class);

    return [
        'types' => $field->getAcceptedFileTypes() ?? [],
        'max_kb' => $field->getMaxSize(),
        'max_files' => $field->getMaxFiles(),
    ];
}

it('takes the signed permit a contractor sends as a PDF', function () {
    asTheContractor();

    Livewire::test(ListWorkOrders::class)
        ->callAction(
            TestAction::make('evidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->createWithContent('permit.pdf', '%PDF-1.4 signed hot-work permit')]],
        );

    // The shared field does not `preserveFilenames()` — only the form door does — so the file
    // lands under a generated name. What the row is about is the KIND: a PDF got in at all.
    $media = $this->job->fresh()->getMedia('evidence');

    expect($media)->toHaveCount(1)
        ->and($media->first()->mime_type)->toBe('application/pdf');
});

it('takes the same PDF at the operator button labelled Attach evidence', function () {
    asTheOperator();

    Livewire::test(ListFacilityWorkOrders::class)
        ->callAction(
            TestAction::make('attachEvidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->createWithContent('certificate.pdf', '%PDF-1.4 completion certificate')]],
        );

    $media = $this->job->fresh()->getMedia('evidence');

    expect($media)->toHaveCount(1)
        ->and($media->first()->mime_type)->toBe('application/pdf');
});

it('still takes the photograph it always took', function () {
    // The control. Widening what evidence may BE must not stop it being a photograph, which is what
    // most of it is and what `require_completion_evidence` was written for.
    asTheContractor();

    Livewire::test(ListWorkOrders::class)
        ->callAction(
            TestAction::make('evidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->image('after.jpg')]],
        );

    $media = $this->job->fresh()->getMedia('evidence');

    expect($media)->toHaveCount(1)
        ->and($media->first()->mime_type)->toStartWith('image/');
});

it('refuses a file above the cap the work-order form already applied', function () {
    asTheContractor();

    Livewire::test(ListWorkOrders::class)
        ->callAction(
            TestAction::make('evidence')->table($this->job),
            data: ['evidence' => [UploadedFile::fake()->create('walkthrough.jpg', 11 * 1024)]],
        )
        ->assertHasActionErrors();

    // The refusal is the whole assertion only if nothing was written anyway.
    expect($this->job->fresh()->getMedia('evidence'))->toHaveCount(0);
});

it('accepts at every door exactly what the work-order form accepts', function () {
    asTheOperator();

    // 'form' rather than `getDefaultTestingSchemaName()`, which is typed `?string`: Filament's own
    // helpers fall back to exactly this name, and a null here would address a schema called ''.
    $form = evidenceFieldOn(
        Livewire::test(EditFacilityWorkOrder::class, ['record' => $this->job->getRouteKey()]),
        'form',
    );

    $modal = Livewire::test(ListFacilityWorkOrders::class)
        ->mountAction(TestAction::make('attachEvidence')->table($this->job));
    $attach = evidenceFieldOn($modal, $modal->instance()->getMountedActionSchemaName());

    // Both halves matter. The FORM is the door that was already right, so pinning its bounds as
    // non-null stops the asymmetry being "fixed" by narrowing it to the shared field; the equality
    // is what makes the three doors one answer rather than two that currently agree.
    expect($form['types'])->toContain('application/pdf')
        ->and($form['max_kb'])->not->toBeNull()
        ->and($form['max_files'])->not->toBeNull()
        ->and($attach)->toBe($form);
});
