<?php

/*
|--------------------------------------------------------------------------
| Department scenarios — end-to-end behaviour of the operator's fixed
| department set: membership↔role coupling, the fixed-set lock, inter-
| department messaging fan-out, and the navigation-group → department
| alignment of every department-owned resource.
|
| NET-NEW relative to DepartmentRolesTest / DepartmentMembershipTest /
| DepartmentMessageTest — these exercise multi-member, multi-role and
| cross-resource paths those single-case suites don't cover.
|--------------------------------------------------------------------------
*/

use App\Filament\Admin\Resources\CamExpensePools\CamExpensePoolResource;
use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\Department;
use App\Notifications\DepartmentMessageNotification;
use App\Services\DepartmentMessageService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

/*
|--------------------------------------------------------------------------
| Membership ⇄ role coupling (registerMember / unregisterMember)
|--------------------------------------------------------------------------
*/

it('registering a user grants exactly the department-named role and keeps prior roles', function () {
    // viewer starts with only the viewer role.
    $user = makeUser('viewer');
    $dept = Department::create(['name' => 'Operations']); // slug: operations

    $dept->registerMember($user);
    $user->refresh();

    expect($user->hasRole('operations'))->toBeTrue()
        ->and($user->hasRole('viewer'))->toBeTrue()          // original role untouched
        ->and($user->hasRole('accounting'))->toBeFalse();    // no unrelated role leaks in
});

it('registering the same user twice is idempotent — one membership, role still held', function () {
    $user = makeUser('viewer');
    $dept = Department::create(['name' => 'Marketing']); // slug: marketing

    $dept->registerMember($user, ['role' => 'Coordinator']);
    $dept->registerMember($user, ['role' => 'Coordinator']);

    expect($dept->members()->whereKey($user->id)->count())->toBe(1)
        ->and($user->fresh()->hasRole('marketing'))->toBeTrue();
});

it('persists pivot data supplied at registration time', function () {
    $user = makeUser('viewer');
    $dept = Department::create(['name' => 'Leasing']);

    $dept->registerMember($user, ['role' => 'Leasing Officer', 'notes' => 'temp cover']);

    $member = $dept->members()->whereKey($user->id)->first();
    expect($member->pivot->role)->toBe('Leasing Officer')
        ->and($member->pivot->notes)->toBe('temp cover');
});

it('unregistering removes only the department role, leaving other department roles intact', function () {
    $user = makeUser('viewer');
    $accounting = Department::create(['name' => 'Accounting']);
    $hr = Department::create(['name' => 'HR']);

    $accounting->registerMember($user);
    $hr->registerMember($user);
    expect($user->fresh()->hasRole('accounting'))->toBeTrue()
        ->and($user->fresh()->hasRole('hr'))->toBeTrue();

    $accounting->unregisterMember($user);
    $user->refresh();

    expect($user->hasRole('accounting'))->toBeFalse()    // dropped
        ->and($user->hasRole('hr'))->toBeTrue()           // sibling membership survives
        ->and($user->hasRole('viewer'))->toBeTrue()       // baseline role survives
        ->and($accounting->members()->whereKey($user->id)->exists())->toBeFalse()
        ->and($hr->members()->whereKey($user->id)->exists())->toBeTrue();
});

it('granting the role via registration confers the department permission set', function () {
    // accounting permissions are asserted in DepartmentRolesTest for makeUser('accounting');
    // here we prove the grant flows through registerMember on a plain viewer.
    $user = makeUser('viewer');
    expect($user->can('invoices.create'))->toBeFalse();

    Department::create(['name' => 'Accounting'])->registerMember($user);

    expect($user->fresh()->can('invoices.create'))->toBeTrue()
        ->and($user->fresh()->can('payments.view'))->toBeTrue();
});

it('unregistering revokes the permission set the role carried', function () {
    $user = makeUser('viewer');
    $dept = Department::create(['name' => 'Accounting']);
    $dept->registerMember($user);
    expect($user->fresh()->can('invoices.create'))->toBeTrue();

    $dept->unregisterMember($user);

    expect($user->fresh()->can('invoices.create'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Fixed department set — no create, no delete (resource-level lock)
|--------------------------------------------------------------------------
*/

it('locks the fixed department set for every role including super_admin', function () {
    foreach (['super_admin', 'manager', 'viewer'] as $role) {
        $this->actingAs(makeUser($role));
        $dept = Department::create(['name' => "X-{$role}"]);

        expect(DepartmentResource::canCreate())->toBeFalse()
            ->and(DepartmentResource::canDelete($dept))->toBeFalse()
            ->and(DepartmentResource::canDeleteAny())->toBeFalse();
    }
});

it('still permits editing a department for a permitted role despite the create/delete lock', function () {
    $this->actingAs(makeUser('manager'));
    $dept = Department::create(['name' => 'Operations']);

    expect(DepartmentResource::canViewAny())->toBeTrue()
        ->and(DepartmentResource::canEdit($dept))->toBeTrue()   // membership is managed via edit
        ->and(DepartmentResource::canCreate())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Inter-department messaging (DEPT-2) — fan-out, sender exclusion, label
|--------------------------------------------------------------------------
*/

it('fans a department message out to every target member but never the sender', function () {
    Notification::fake();

    $ops = Department::create(['name' => 'Operations']);
    $m1 = makeUser('viewer');
    $m2 = makeUser('viewer');
    $m3 = makeUser('viewer');
    $ops->members()->attach([$m1->id, $m2->id, $m3->id]);

    // Sender belongs to a *different* department.
    $accounting = Department::create(['name' => 'Accounting']);
    $sender = makeUser('manager');
    $accounting->members()->attach($sender->id);

    $count = app(DepartmentMessageService::class)->send($ops, $sender, 'Lift #3 is down.');

    expect($count)->toBe(3);
    Notification::assertSentTo($m1, DepartmentMessageNotification::class);
    Notification::assertSentTo($m2, DepartmentMessageNotification::class);
    Notification::assertSentTo($m3, DepartmentMessageNotification::class);
    Notification::assertNotSentTo($sender, DepartmentMessageNotification::class);
});

it('excludes only the sender when the sender is also a target member', function () {
    Notification::fake();

    $hr = Department::create(['name' => 'HR']);
    $other = makeUser('viewer');
    $sender = makeUser('manager');
    $hr->members()->attach([$other->id, $sender->id]);

    $count = app(DepartmentMessageService::class)->send($hr, $sender, 'Town hall at 4pm.');

    expect($count)->toBe(1);                 // sender filtered, other kept
    Notification::assertSentTo($other, DepartmentMessageNotification::class);
    Notification::assertNotSentTo($sender, DepartmentMessageNotification::class);
});

it('sends nothing and reports zero when the target department is empty', function () {
    Notification::fake();

    $empty = Department::create(['name' => 'Leasing']);
    $sender = makeUser('manager');

    $count = app(DepartmentMessageService::class)->send($empty, $sender, 'Anyone home?');

    expect($count)->toBe(0);
    Notification::assertNothingSent();
});

it('labels the message with the senders name and originating department', function () {
    Notification::fake();

    $target = Department::create(['name' => 'Operations']);
    $member = makeUser('viewer');
    $target->members()->attach($member->id);

    $marketing = Department::create(['name' => 'Marketing']);
    $sender = makeUser('manager');
    $sender->update(['name' => 'Dana Sender']);
    $marketing->members()->attach($sender->id);

    app(DepartmentMessageService::class)->send($target, $sender, 'Need a banner.');

    Notification::assertSentTo($member, DepartmentMessageNotification::class, function ($notification) {
        $payload = $notification->toDatabase($notification);

        return $notification->fromLabel === 'Dana Sender (Marketing)'
            && $notification->body === 'Need a banner.'
            && $payload['type'] === 'department_message'
            && $payload['title'] === 'Message from Dana Sender (Marketing)';
    });
});

it('persists a department message as a database bell notification', function () {
    $hr = Department::create(['name' => 'HR']);
    $member = makeUser('viewer');
    $hr->members()->attach($member->id);
    $sender = makeUser('manager');

    app(DepartmentMessageService::class)->send($hr, $sender, 'Submit timesheets.');

    expect($member->fresh()->notifications()->count())->toBe(1)
        ->and($member->fresh()->notifications->first()->data['body'])->toBe('Submit timesheets.');
});

/*
|--------------------------------------------------------------------------
| Navigation-group ⇄ department alignment
| __('admin.groups.{slug}') resolves to the department's display name, so a
| resource owned by department D groups under D's name.
|--------------------------------------------------------------------------
*/

it('aligns each department-owned resource navigation group with its department name', function () {
    // resource class => owning department display name (also the group label)
    $map = [
        InvoiceResource::class => 'Accounting',
        PaymentResource::class => 'Accounting',
        CreditNoteResource::class => 'Accounting',
        CamExpensePoolResource::class => 'Accounting',
        LeaseResource::class => 'Leasing',
        TenantResource::class => 'Leasing',
        UnitResource::class => 'Leasing',
        TenantRequestResource::class => 'Operations',
        UtilityMeterResource::class => 'Operations',
        MarketingBudgetResource::class => 'Marketing',
    ];

    foreach ($map as $resource => $department) {
        expect($resource::getNavigationGroup())
            ->toBe($department, "{$resource} should group under {$department}");
    }
});

it('groups the department registry itself under HR', function () {
    // The Departments admin (org-structure management) lives in HR's group.
    expect(DepartmentResource::getNavigationGroup())->toBe('HR');
});

it('resolves the group label from the department slug translation key', function () {
    // Proves the alignment is keyed on the slug, not a hand-typed string:
    // every seeded department's slug has a matching groups translation.
    foreach (['operations', 'leasing', 'accounting', 'hr', 'marketing'] as $slug) {
        $dept = new Department(['slug' => $slug]);
        expect(__("admin.groups.{$dept->roleName()}"))->toBeString()
            ->not->toBe("admin.groups.{$slug}"); // key actually translated, not echoed back
    }
});
