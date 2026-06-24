<?php

use App\Models\Department;
use App\Services\MaintenanceRequestService;

// makeMaintenanceRequest() is a shared global helper defined in
// tests/Feature/Models/MaintenanceRequestTest.php.

it('assigns a maintenance request to a department', function () {
    $dept = Department::create(['name' => 'Operations']);
    $req = makeMaintenanceRequest();

    app(MaintenanceRequestService::class)->redirectToDepartment($req, $dept->id);

    expect($req->refresh()->department_id)->toBe($dept->id)
        ->and($req->department->name)->toBe('Operations');
});

it('redirects a request from one department to another', function () {
    $ops = Department::create(['name' => 'Operations']);
    $leasing = Department::create(['name' => 'Leasing']);
    $req = makeMaintenanceRequest(['department_id' => $ops->id]);

    app(MaintenanceRequestService::class)->redirectToDepartment($req, $leasing->id);

    expect($req->refresh()->department_id)->toBe($leasing->id);
});

it('clears the department when redirected to null', function () {
    $dept = Department::create(['name' => 'Leasing']);
    $req = makeMaintenanceRequest(['department_id' => $dept->id]);

    app(MaintenanceRequestService::class)->redirectToDepartment($req, null);

    expect($req->refresh()->department_id)->toBeNull();
});

it('records the department change in the activity log', function () {
    $dept = Department::create(['name' => 'Operations']);
    $req = makeMaintenanceRequest();

    app(MaintenanceRequestService::class)->redirectToDepartment($req, $dept->id);

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', $req->getMorphClass())
        ->where('subject_id', $req->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes['attributes'] ?? [])->toHaveKey('department_id');
});
