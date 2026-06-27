<?php

use App\Models\Department;
use App\Services\MaintenanceRequestService;

// Regression: MaintenanceRequestService::redirectToDepartment() and assign()
// once mutated terminal (closed/cancelled) work-orders, violating the
// FR REQ-3 immutability rule. The fix makes both methods a no-op when
// $request->isTerminal(). These tests pin the fixed behavior: terminal
// requests cannot be re-routed or re-assigned, while open ones still can.

it('does not re-route or re-assign a CLOSED maintenance request', function () {
    $service = app(MaintenanceRequestService::class);

    $originalDept = Department::create(['name' => 'Operations']);
    $otherDept = Department::create(['name' => 'Leasing']);

    $originalAssignee = makeUser('operations');
    $otherAssignee = makeUser('manager');

    $req = makeMaintenanceRequest([
        'status' => 'closed',
        'department_id' => $originalDept->id,
        'assigned_to' => $originalAssignee->id,
        'closed_at' => now(),
    ]);

    // Re-route attempt → department_id stays put.
    $service->redirectToDepartment($req, $otherDept->id);
    expect($req->refresh()->department_id)->toBe($originalDept->id);

    // Re-assign attempt → assigned_to stays put.
    $service->assign($req, $otherAssignee->id);
    expect($req->refresh()->assigned_to)->toBe($originalAssignee->id);
});

it('does not re-route or re-assign a CANCELLED maintenance request', function () {
    $service = app(MaintenanceRequestService::class);

    $originalDept = Department::create(['name' => 'Operations']);
    $otherDept = Department::create(['name' => 'Leasing']);

    $originalAssignee = makeUser('operations');
    $otherAssignee = makeUser('manager');

    $req = makeMaintenanceRequest([
        'status' => 'cancelled',
        'department_id' => $originalDept->id,
        'assigned_to' => $originalAssignee->id,
    ]);

    $service->redirectToDepartment($req, $otherDept->id);
    $service->assign($req, $otherAssignee->id);

    expect($req->refresh()->department_id)->toBe($originalDept->id)
        ->and($req->refresh()->assigned_to)->toBe($originalAssignee->id);
});

it('still re-routes and re-assigns an OPEN maintenance request', function () {
    $service = app(MaintenanceRequestService::class);

    $originalDept = Department::create(['name' => 'Operations']);
    $otherDept = Department::create(['name' => 'Leasing']);

    $assignee = makeUser('operations');

    $req = makeMaintenanceRequest([
        'status' => 'in_progress',
        'department_id' => $originalDept->id,
    ]);

    $service->redirectToDepartment($req, $otherDept->id);
    expect($req->refresh()->department_id)->toBe($otherDept->id);

    $service->assign($req, $assignee->id);
    expect($req->refresh()->assigned_to)->toBe($assignee->id);
});
