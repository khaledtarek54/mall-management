<?php

use App\Filament\Admin\Actions\PurchaseRequestActions;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\CreateFacilityWorkOrder;
use App\Models\PurchaseRequest;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * Two acts the system could perform and no screen could reach.
 *
 * **A DRAFT purchase request was a dead end.** `PurchaseRequestService::submit()` existed, refused a
 * non-draft, refused an EMPTY request and stamped the submitter as the person taking responsibility
 * — and had no caller anywhere but a test. `inventory:scan-low-stock` raises drafts automatically
 * and the lines relation manager locks editing to `requested`, so the whole reorder loop stopped at
 * its first step with nothing on any screen able to move it forward.
 *
 * **A work order could be assigned once and never REASSIGNED.** `assigned_to_user_id` drives
 * `FacilityWorkOrder::notifyAssignee()` and was rendered on the CORRECTIVE form only — i.e. at
 * creation from a tenant request. So the technician who is off sick keeps the job, the one who picks
 * it up is never told, and the model's own assignment notification is unreachable for every job that
 * changes hands. A supervisor's most ordinary act had no screen.
 *
 * Both are the shape `ServiceReachability` catches one level up and cannot see here: the CLASS is
 * reachable while the act is not.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers Submit on a draft purchase request', function () {
    $names = collect(PurchaseRequestActions::all())
        ->map(fn ($action): string => $action->getName())
        ->all();

    // The premise, so a sweep that found nothing cannot read as a pass.
    expect($names)->not->toBeEmpty()
        ->and($names)->toContain('submit')
        // …and the acts that already existed are untouched.
        ->and($names)->toContain('approve', 'reject', 'order', 'receive', 'cancel');
});

it('shows Submit only while the request is still a draft', function () {
    $submit = collect(PurchaseRequestActions::all())
        ->first(fn ($action): bool => $action->getName() === 'submit');

    $draft = new PurchaseRequest(['status' => PurchaseRequest::STATUS_DRAFT]);
    $requested = new PurchaseRequest(['status' => PurchaseRequest::STATUS_REQUESTED]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    expect($submit->record($draft)->isVisible())->toBeTrue()
        // Submitting twice is what `submit()` refuses; the button must not invite it.
        ->and($submit->record($requested)->isVisible())->toBeFalse();
});

it('lets a supervisor reassign a work order after it was created', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    // The mounted page's own schema — `Schema::make()` on a hand-rolled component carries no
    // record and no panel context, which is how a form sweep comes to measure nothing.
    $page = Livewire::test(
        CreateFacilityWorkOrder::class
    )->instance();

    $assignee = null;
    $walk = function ($node) use (&$walk, &$assignee) {
        foreach ($node->getComponents(withHidden: true) as $component) {
            if ($component instanceof Select && $component->getName() === 'assigned_to_user_id') {
                $assignee = $component;
            }
            if (method_exists($component, 'getChildSchemas')) {
                try {
                    foreach ($component->getChildSchemas() as $child) {
                        $walk($child);
                    }
                } catch (Throwable) {
                }
            }
        }
    };
    $walk($page->getSchema('form'));

    expect($assignee)->not->toBeNull('the work-order form offers no way to assign a technician');
});
