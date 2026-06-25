<?php

use App\Models\Department;
use App\Notifications\DepartmentMessageNotification;
use App\Services\DepartmentMessageService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

it('sends a department message to the target department members', function () {
    Notification::fake();

    $hr = Department::create(['name' => 'HR']);
    $member = makeUser('viewer');
    $hr->members()->attach($member->id);
    $sender = makeUser('manager');

    $count = app(DepartmentMessageService::class)->send($hr, $sender, 'Please review payroll.');

    expect($count)->toBe(1);
    Notification::assertSentTo($member, DepartmentMessageNotification::class);
});

it('does not notify the sender even if they belong to the target department', function () {
    Notification::fake();

    $dept = Department::create(['name' => 'Marketing']);
    $sender = makeUser('manager');
    $dept->members()->attach($sender->id);

    $count = app(DepartmentMessageService::class)->send($dept, $sender, 'hi');

    expect($count)->toBe(0);
    Notification::assertNotSentTo($sender, DepartmentMessageNotification::class);
});
