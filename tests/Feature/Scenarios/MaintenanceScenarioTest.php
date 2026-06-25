<?php

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\Department;
use App\Notifications\MaintenanceSlaBreachedNotification;
use App\Services\MaintenanceRequestService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/*
|--------------------------------------------------------------------------
| Maintenance lifecycle + rules — NET-NEW scenarios.
|--------------------------------------------------------------------------
| Complements MaintenanceImmutabilityTest, MaintenanceDepartmentTest,
| MaintenanceDateValidationTest, MaintenanceSlaOwnerAlertTest and the
| service/console suites. Focus here: the full legal/illegal transition
| matrix, cancel-as-reject, terminal immutability enforced by the service
| (not just the resource), the scheduled-work-window's independence from the
| SLA target, the RBAC matrix, and SLA-scan boundary/idempotency edges that
| the existing happy-path tests don't cover.
*/

function svc(): MaintenanceRequestService
{
    return app(MaintenanceRequestService::class);
}

// ============================================================
// STATE TRANSITIONS — legal path
// ============================================================

it('walks every legal hop submitted → acknowledged → in_progress → resolved → closed', function () {
    $req = makeMaintenanceRequest(['status' => 'submitted']);

    svc()->transition($req, 'acknowledged');
    expect($req->fresh()->status)->toBe('acknowledged');

    svc()->transition($req, 'in_progress');
    expect($req->fresh()->status)->toBe('in_progress');

    svc()->transition($req, 'resolved', ['resolution_notes' => 'Done.']);
    expect($req->fresh()->status)->toBe('resolved');

    svc()->transition($req, 'closed');
    expect($req->fresh()->status)->toBe('closed')
        ->and($req->fresh()->closed_at)->not->toBeNull();
});

it('allows the awaiting_tenant detour: in_progress → awaiting_tenant → in_progress', function () {
    $req = makeMaintenanceRequest(['status' => 'in_progress']);

    svc()->transition($req, 'awaiting_tenant');
    expect($req->fresh()->status)->toBe('awaiting_tenant');

    svc()->transition($req, 'in_progress');
    expect($req->fresh()->status)->toBe('in_progress');
});

it('reopens a resolved request: resolved → in_progress is legal', function () {
    $req = makeMaintenanceRequest(['status' => 'resolved', 'resolved_at' => now()]);

    svc()->transition($req, 'in_progress');

    expect($req->fresh()->status)->toBe('in_progress');
});

// ============================================================
// STATE TRANSITIONS — illegal hops rejected (mirrors TRANSITIONS const)
// ============================================================

it('rejects illegal transition closed → in_progress (terminal is a dead end)', function () {
    $req = makeMaintenanceRequest(['status' => 'closed', 'closed_at' => now()]);

    svc()->transition($req, 'in_progress');
})->throws(InvalidArgumentException::class, 'Illegal transition: closed → in_progress');

it('rejects illegal transition cancelled → in_progress', function () {
    $req = makeMaintenanceRequest(['status' => 'cancelled']);

    svc()->transition($req, 'in_progress');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('rejects illegal transition submitted → resolved (cannot skip in_progress)', function () {
    $req = makeMaintenanceRequest(['status' => 'submitted']);

    svc()->transition($req, 'resolved');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('rejects illegal transition acknowledged → closed (must resolve first)', function () {
    $req = makeMaintenanceRequest(['status' => 'acknowledged']);

    svc()->transition($req, 'closed');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('rejects illegal transition resolved → submitted (no rewind to intake)', function () {
    $req = makeMaintenanceRequest(['status' => 'resolved', 'resolved_at' => now()]);

    svc()->transition($req, 'submitted');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('rejects a no-op self-transition in_progress → in_progress', function () {
    $req = makeMaintenanceRequest(['status' => 'in_progress']);

    svc()->transition($req, 'in_progress');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('rejects an unknown target status', function () {
    $req = makeMaintenanceRequest(['status' => 'submitted']);

    svc()->transition($req, 'banana');
})->throws(InvalidArgumentException::class, 'Illegal transition');

// ============================================================
// CANCEL == REJECT  (status -> cancelled from any open state)
// ============================================================

it('cancels (rejects) a request from each open state', function (string $from) {
    $req = makeMaintenanceRequest(['status' => $from]);

    svc()->transition($req, 'cancelled');

    expect($req->fresh()->status)->toBe('cancelled')
        ->and($req->fresh()->isTerminal())->toBeTrue();
})->with(['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant']);

it('cannot cancel an already-resolved request (only close or reopen)', function () {
    $req = makeMaintenanceRequest(['status' => 'resolved', 'resolved_at' => now()]);

    svc()->transition($req, 'cancelled');
})->throws(InvalidArgumentException::class, 'Illegal transition');

it('does not notify the tenant on cancellation but the request still lands cancelled', function () {
    Notification::fake();
    $req = makeMaintenanceRequest(['status' => 'submitted']);

    svc()->transition($req, 'cancelled');

    expect($req->fresh()->status)->toBe('cancelled');
    Notification::assertNothingSent();
});

// ============================================================
// TERMINAL IMMUTABILITY — enforced beyond canEdit
// ============================================================

it('treats closed and cancelled as terminal; open states are not', function () {
    expect(makeMaintenanceRequest(['status' => 'closed'])->isTerminal())->toBeTrue()
        ->and(makeMaintenanceRequest(['status' => 'cancelled'])->isTerminal())->toBeTrue()
        ->and(makeMaintenanceRequest(['status' => 'resolved'])->isTerminal())->toBeFalse()
        ->and(makeMaintenanceRequest(['status' => 'awaiting_tenant'])->isTerminal())->toBeFalse();
});

it('forbids ANY status move out of a terminal state via the service', function (string $terminal) {
    $req = makeMaintenanceRequest(['status' => $terminal]);

    // Every other status must be rejected — terminal has no legal successors.
    foreach (\App\Models\MaintenanceRequest::STATUSES as $target) {
        if ($target === $terminal) {
            continue;
        }
        expect(fn () => svc()->transition($req, $target))
            ->toThrow(InvalidArgumentException::class);
    }

    expect($req->fresh()->status)->toBe($terminal);
})->with(['closed', 'cancelled']);

it('hides Edit / Redirect / Assign on a closed record (canEdit gates all three)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    expect(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'closed'])))->toBeFalse()
        ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'cancelled'])))->toBeFalse()
        ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'in_progress'])))->toBeTrue();
});

it('blocks a super_admin from editing a terminal record even though they hold maintenance.edit', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $admin = makeUser('super_admin');
    $this->actingAs($admin);

    // The permission is held...
    expect($admin->can('maintenance.edit'))->toBeTrue()
        // ...yet the terminal guard wins.
        ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'closed'])))->toBeFalse();
});

// ============================================================
// REDIRECT — department reassignment (independent of status)
// ============================================================

it('redirect changes department_id without touching status', function () {
    $ops = Department::create(['name' => 'Operations']);
    $facilities = Department::create(['name' => 'Facilities']);
    $req = makeMaintenanceRequest(['status' => 'in_progress', 'department_id' => $ops->id]);

    svc()->redirectToDepartment($req, $facilities->id);

    expect($req->fresh()->department_id)->toBe($facilities->id)
        ->and($req->fresh()->status)->toBe('in_progress');
});

// ============================================================
// SCHEDULED WORK WINDOW — independent of the SLA target
// ============================================================

it('keeps the scheduled work window independent of target_resolution_at', function () {
    // Use second-precision values — the datetime column round-trips at second
    // resolution, so comparing back the exact instant must be microsecond-free.
    $target = now()->addDays(2)->startOfSecond();
    $from = now()->addDays(5)->startOfSecond();
    $to = now()->addDays(5)->addHours(4)->startOfSecond();

    $req = makeMaintenanceRequest([
        'status' => 'in_progress',
        'target_resolution_at' => $target,
        'scheduled_from' => $from,
        'scheduled_to' => $to,
    ]);

    $fresh = $req->fresh();

    // Scheduled window can sit AFTER the SLA target — they are not coupled.
    expect($fresh->scheduled_from->gt($fresh->target_resolution_at))->toBeTrue()
        ->and($fresh->scheduled_to->gt($fresh->scheduled_from))->toBeTrue()
        ->and($fresh->target_resolution_at->equalTo($target))->toBeTrue();
});

it('does not require a scheduled window to be set at all (nullable, decoupled)', function () {
    $req = makeMaintenanceRequest([
        'status' => 'acknowledged',
        'target_resolution_at' => now()->addDay(),
        'scheduled_from' => null,
        'scheduled_to' => null,
    ]);

    expect($req->fresh()->scheduled_from)->toBeNull()
        ->and($req->fresh()->scheduled_to)->toBeNull()
        ->and($req->fresh()->target_resolution_at)->not->toBeNull();
});

// ============================================================
// RBAC MATRIX — each role does exactly what it should
// ============================================================

describe('maintenance RBAC matrix', function () {
    beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

    it('lets operations view, create and edit open requests but never delete', function () {
        $this->actingAs(makeUser('operations'));

        expect(MaintenanceRequestResource::canViewAny())->toBeTrue()
            ->and(MaintenanceRequestResource::canCreate())->toBeTrue()
            ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'in_progress'])))->toBeTrue()
            ->and(MaintenanceRequestResource::canDelete(makeMaintenanceRequest()))->toBeFalse();
    });

    it('gives viewer read-only access (view yes, create/edit/delete no)', function () {
        $this->actingAs(makeUser('viewer'));

        expect(MaintenanceRequestResource::canViewAny())->toBeTrue()
            ->and(MaintenanceRequestResource::canCreate())->toBeFalse()
            ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'in_progress'])))->toBeFalse()
            ->and(MaintenanceRequestResource::canDelete(makeMaintenanceRequest()))->toBeFalse();
    });

    it('denies leasing any maintenance access (out of department)', function () {
        $this->actingAs(makeUser('leasing'));

        expect(MaintenanceRequestResource::canViewAny())->toBeFalse()
            ->and(MaintenanceRequestResource::canCreate())->toBeFalse()
            ->and(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'in_progress'])))->toBeFalse();
    });

    it('reserves delete for super_admin only', function () {
        $req = makeMaintenanceRequest(['status' => 'in_progress']);

        $this->actingAs(makeUser('manager'));
        expect(MaintenanceRequestResource::canDelete($req))->toBeFalse();

        $this->actingAs(makeUser('super_admin'));
        expect(MaintenanceRequestResource::canDelete($req))->toBeTrue();
    });

    it('keeps bulk delete off for maintenance even for super_admin', function () {
        $this->actingAs(makeUser('super_admin'));

        expect(MaintenanceRequestResource::canDeleteAny())->toBeFalse();
    });

    it('lets a manager edit open requests but not delete', function () {
        $this->actingAs(makeUser('manager'));

        expect(MaintenanceRequestResource::canEdit(makeMaintenanceRequest(['status' => 'acknowledged'])))->toBeTrue()
            ->and(MaintenanceRequestResource::canDelete(makeMaintenanceRequest()))->toBeFalse();
    });
});

// ============================================================
// SLA SCAN — boundary + idempotency edges (net-new)
// ============================================================

describe('SLA breach scan boundaries', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        $this->asset = makeAsset();
        $this->owner = makeUser('owner');
        $this->owner->ownedAssets()->attach($this->asset->id, ['ownership_percentage' => 100]);
        $this->unit = makeUnit($this->asset);
    });

    it('does NOT notify a not-yet-due open request (target in the future)', function () {
        Notification::fake();

        $req = makeMaintenanceRequest([
            'unit_id' => $this->unit->id,
            'status' => 'in_progress',
            'target_resolution_at' => now()->addHours(6),
        ]);

        $this->artisan('maintenance:scan-sla-breaches')->assertSuccessful();

        Notification::assertNotSentTo($this->owner, MaintenanceSlaBreachedNotification::class);
        expect($req->fresh()->sla_breach_notified_at)->toBeNull();
    });

    it('does NOT notify an open request with no target_resolution_at set', function () {
        Notification::fake();

        $req = makeMaintenanceRequest([
            'unit_id' => $this->unit->id,
            'status' => 'in_progress',
            'target_resolution_at' => null,
        ]);

        $this->artisan('maintenance:scan-sla-breaches')->assertSuccessful();

        Notification::assertNotSentTo($this->owner, MaintenanceSlaBreachedNotification::class);
        expect($req->fresh()->sla_breach_notified_at)->toBeNull();
    });

    it('notifies the owner once and is idempotent across re-runs', function () {
        Notification::fake();

        $req = makeMaintenanceRequest([
            'unit_id' => $this->unit->id,
            'status' => 'in_progress',
            'target_resolution_at' => now()->subDay(),
        ]);

        $this->artisan('maintenance:scan-sla-breaches')->assertSuccessful();
        $firstStamp = $req->fresh()->sla_breach_notified_at;
        expect($firstStamp)->not->toBeNull();

        // Re-run: the WHERE sla_breach_notified_at IS NULL guard skips it.
        $this->artisan('maintenance:scan-sla-breaches')
            ->expectsOutputToContain('No new SLA breaches.')
            ->assertSuccessful();

        // Exactly one notification across both runs, and the stamp is unchanged.
        Notification::assertSentToTimes($this->owner, MaintenanceSlaBreachedNotification::class, 1);
        expect($req->fresh()->sla_breach_notified_at->equalTo($firstStamp))->toBeTrue();
    });
});
