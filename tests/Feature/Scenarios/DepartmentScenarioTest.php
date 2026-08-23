<?php

/*
|--------------------------------------------------------------------------
| Department scenarios — end-to-end behaviour of the operator's
| department register: membership↔role coupling, the deletion lock, inter-
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
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\Department;
use App\Notifications\DepartmentMessageNotification;
use App\Services\DepartmentMessageService;
use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
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

it('lets a permitted role add a department, and refuses deletion to everyone', function () {
    // CREATE was a hard `return false` on the theory that HR / Marketing / Accounting / Leasing /
    // Operations is a fixed reference set. It is not: a mall with its own Security or Tenant
    // Relations team had nowhere to put it, and tenant requests ROUTE to a department — so the
    // freeze reached the routing, not only the org chart (D-6).
    //
    // DELETE is still refused for everyone including super_admin, and that has not changed: a
    // department that routed a request or held a member is referenced by rows an auditor reads.
    foreach (['manager', 'mall_admin'] as $role) {
        $this->actingAs(makeUser($role));
        $dept = Department::create(['name' => "X-{$role}"]);

        expect(DepartmentResource::canCreate())->toBeTrue("{$role} holds departments.create and should be able to add one")
            ->and(DepartmentResource::canDelete($dept))->toBeFalse()
            ->and(DepartmentResource::canDeleteAny())->toBeFalse();
    }

    // The control: a role WITHOUT the permission still cannot create, so the unfreeze did not open
    // the screen to everybody.
    $this->actingAs(makeUser('viewer'));

    expect(DepartmentResource::canCreate())->toBeFalse();
});

it('still permits editing a department for a permitted role', function () {
    $this->actingAs(makeUser('manager'));
    $dept = Department::create(['name' => 'Operations']);

    expect(DepartmentResource::canViewAny())->toBeTrue()
        ->and(DepartmentResource::canEdit($dept))->toBeTrue();   // membership is managed via edit
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
| Navigation grouping — the sidebar reads the way money moves
|--------------------------------------------------------------------------
| The 2026-07-31 reorg deliberately RETIRED the old department-aligned grouping: a resource no
| longer groups under its owning department's name but under the money-flow stage it belongs to.
| Grouping is presentation; department slugs still drive RBAC — which is why the slug-translation
| test below is unchanged.
|
| **Where the group is DECLARED moved on 2026-08-23.** It was a `getNavigationGroup()` on each of
| the 99 screen classes; it is now `App\Support\Navigation`, one ordered registry rendered through
| Filament's own navigation builder. So the "no scatter" test that used to live here is gone: a
| group a screen names but the panel never declares cannot exist any more — there is one list of
| groups and screens are placed INTO it, not labelled with it. `NavigationConformanceTest` owns
| that property now, along with the failure the new shape can have (a screen the registry omits is
| invisible rather than mis-sorted). What is kept here is the INTENT: these particular resources
| belong to these particular stages, and an accidental re-shuffle should be argued for.
*/

it('files each resource under its intended money-flow navigation group', function () {
    // resource class => the sidebar group key it belongs to (money-flow stage, not department)
    $map = [
        InvoiceResource::class => 'receivables',
        PaymentResource::class => 'receivables',
        CreditNoteResource::class => 'receivables',
        // CAM and metering moved to their own `recoveries` group on 2026-08-23. They still bill the
        // tenant, so Receivables was not wrong — but they are one shape of work (measure a period,
        // apportion it, bill the difference) that Receivables had grown to ten items hiding.
        CamExpensePoolResource::class => 'recoveries',
        UtilityMeterResource::class => 'recoveries',
        LeaseResource::class => 'leasing',
        TenantResource::class => 'leasing',
        UnitResource::class => 'leasing',
        TenantRequestResource::class => 'operations',
        MarketingBudgetResource::class => 'marketing',
        // The Departments admin (org-structure management) sits with HR & Payroll.
        DepartmentResource::class => 'hr_payroll',
    ];

    foreach ($map as $resource => $group) {
        expect(Navigation::groupOf($resource))
            ->toBe($group, "{$resource} should group under {$group}");
    }
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
