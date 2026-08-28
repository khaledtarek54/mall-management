<?php

/**
 * **A work order has a conversation, and until now it had one `notes` field.**
 *
 * `TenantRequest` has carried a comment thread with an `is_internal` flag since module 11. The work
 * order — the record that generates *more* conversation, because a third party executes it — had
 * `facility_work_orders.notes`: one field, no author, no time, last writer wins. The things people
 * actually need to record ("access arranged for Sunday", "part on back-order", "the tenant refused
 * entry") either overwrote each other or lived in somebody's WhatsApp.
 *
 * This is **step 1 of `docs/modules/12b-VENDOR-PORTAL-DESIGN.md`** — the one new domain object that
 * design needs, built first because it is useful on its own even if the portal never ships.
 *
 * The tests drive the **relation manager**, not just the service: a Filament action's schema and
 * `using()` closures only run when an operator opens the modal, so building one in a test proves
 * nothing (CLAUDE.md — `mount()` is the seam).
 */

use App\Filament\Admin\RelationManagers\WorkOrderCommentsRelationManager;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderComment;
use App\Services\CommentOnWorkOrderService;
use App\Services\FacilityWorkOrderService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->user = makeUser('super_admin');
    $this->actingAs($this->user);
    Filament::setTenant($this->asset);

    $this->order = FacilityWorkOrder::create([
        'asset_id' => $this->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'title' => 'Fix chiller',
        'description' => 'Chiller down on level 2',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
    ]);
});

it('records who said what, and when', function () {
    $comment = app(CommentOnWorkOrderService::class)
        ->comment($this->order, $this->user, '  Access arranged for Sunday 09:00  ');

    expect($comment->body)->toBe('Access arranged for Sunday 09:00')  // trimmed
        ->and($comment->is_internal)->toBeFalse()                      // a conversation by default
        ->and($comment->author->is($this->user))->toBeTrue()
        ->and($comment->author_type)->toBe($this->user->getMorphClass())
        ->and($this->order->fresh()->comments)->toHaveCount(1);
});

it('stores the author as a morph ALIAS, never a class name', function () {
    // Polymorphic columns store an alias — `MorphMap` enforces it and throws on an unmapped class.
    // Asserted because a service writing `$author::class` looks identical and strands every row the
    // day the class moves.
    $comment = app(CommentOnWorkOrderService::class)->comment($this->order, $this->user, 'Noted');

    expect($comment->author_type)->not->toContain('\\');
});

it('defaults a comment to VISIBLE, not internal', function () {
    // The direction of the default is load-bearing: defaulting to internal would make the vendor
    // portal silent by accident, and nothing would error — the contractor would simply never hear
    // anything, which is the hardest kind of failure to notice.
    expect(app(CommentOnWorkOrderService::class)->comment($this->order, $this->user, 'x')->is_internal)
        ->toBeFalse();
});

it('refuses to add to a closed job', function () {
    // A done or cancelled work order is immutable, so its thread is too — otherwise the thread is
    // the one mutable part of a record an auditor reads as settled. Mirrors the tenant thread.
    app(FacilityWorkOrderService::class)->transition($this->order, 'in_progress');
    app(FacilityWorkOrderService::class)->transition($this->order->fresh(), 'done');

    expect(fn () => app(CommentOnWorkOrderService::class)
        ->comment($this->order->fresh(), $this->user, 'Late note'))
        ->toThrow(ValidationException::class);

    expect(FacilityWorkOrderComment::count())->toBe(0);
});

it('refuses an empty comment', function () {
    expect(fn () => app(CommentOnWorkOrderService::class)->comment($this->order, $this->user, '   '))
        ->toThrow(ValidationException::class);
});

it('adds a comment through the real relation manager', function () {
    // mountTableAction, not a constructed action: the `using()` closure runs only on open.
    Livewire::test(WorkOrderCommentsRelationManager::class, [
        'ownerRecord' => $this->order,
        'pageClass' => EditFacilityWorkOrder::class,
    ])
        // `callTableAction('create', data: …)` is the form this codebase uses for a relation
        // manager's HEADER create — the mount/setActionData/callMountedAction chain renders the
        // modal outside its parent page and Livewire fails on a missing root tag. Row actions take
        // the TestAction form (below); the two differ, which is worth knowing before copying either.
        ->callTableAction('create', data: ['body' => 'Part is on back-order', 'is_internal' => true])
        ->assertHasNoTableActionErrors();

    $comment = FacilityWorkOrderComment::sole();

    expect($comment->body)->toBe('Part is on back-order')
        ->and($comment->is_internal)->toBeTrue()
        ->and($comment->author->is($this->user))->toBeTrue();
});

it('lets the operator publish an internal note, and says so', function () {
    // Flipping `is_internal` PUBLISHES a staff note to the contractor once the portal ships — a
    // disclosure, not a cosmetic edit, which is why the action confirms.
    $comment = app(CommentOnWorkOrderService::class)
        ->comment($this->order, $this->user, 'Quote looks high', isInternal: true);

    Livewire::test(WorkOrderCommentsRelationManager::class, [
        'ownerRecord' => $this->order,
        'pageClass' => EditFacilityWorkOrder::class,
    ])
        ->mountAction(TestAction::make('toggleVisibility')->table($comment))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($comment->fresh()->is_internal)->toBeFalse();
});

it('cascades with the job it belongs to', function () {
    app(CommentOnWorkOrderService::class)->comment($this->order, $this->user, 'Noted');

    $this->order->forceDelete();

    expect(FacilityWorkOrderComment::count())->toBe(0);
});
