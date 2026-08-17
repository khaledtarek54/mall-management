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
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Filament\Admin\Resources\UtilityMeters\UtilityMeterResource;
use App\Models\Department;
use App\Notifications\DepartmentMessageNotification;
use App\Services\DepartmentMessageService;
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
| Navigation grouping — the sidebar reads the way money moves
|--------------------------------------------------------------------------
| The 2026-07-31 reorg (feat(nav): reorganise the sidebar the way an accountant reads a
| system) deliberately RETIRED the old department-aligned grouping: a resource no longer
| groups under its owning department's name but under the money-flow stage it belongs to
| (leasing → receivables → payables → general ledger). Grouping is presentation now;
| department slugs still drive RBAC — see the slug-translation test below, which is why
| that one is unchanged. What these pin is that each resource still declares its INTENDED
| group, and that the group is one the panel actually declares — the bug the reorg fixed
| was resources rendering wherever Filament happened to encounter them.
*/

it('files each resource under its intended money-flow navigation group', function () {
    // resource class => the sidebar group it belongs to (money-flow stage, not department)
    $map = [
        InvoiceResource::class => 'Receivables',
        PaymentResource::class => 'Receivables',
        CreditNoteResource::class => 'Receivables',
        CamExpensePoolResource::class => 'Receivables',
        UtilityMeterResource::class => 'Receivables',   // recharges bill the tenant → AR
        LeaseResource::class => 'Leasing',
        TenantResource::class => 'Leasing',
        UnitResource::class => 'Leasing',
        TenantRequestResource::class => 'Operations',
        MarketingBudgetResource::class => 'Marketing',
        // The Departments admin (org-structure management) sits with HR & Payroll.
        DepartmentResource::class => 'HR & Payroll',
    ];

    foreach ($map as $resource => $group) {
        expect($resource::getNavigationGroup())
            ->toBe($group, "{$resource} should group under {$group}");
    }
});

it('files every resource under a navigation group the panel declares (no scatter)', function () {
    // The actual regression the reorg fixed: ten groups existed but only six were declared in
    // the panel, so five resources rendered wherever Filament happened to encounter them. The
    // allowed set is derived from the panel, so this holds as groups are added or renamed —
    // a resource assigned to an undeclared group turns this red rather than silently scattering.
    $panel = Filament::getPanel('admin');

    $declared = collect($panel->getNavigationGroups())
        ->map(fn ($group) => is_string($group) ? $group : $group->getLabel())
        ->filter()
        ->values()
        ->all();

    $scattered = [];

    foreach ($panel->getResources() as $resource) {
        $group = $resource::getNavigationGroup();

        if ($group !== null && ! in_array($group, $declared, true)) {
            $scattered[] = class_basename($resource)." → {$group}";
        }
    }

    expect($scattered)->toBe([], "these resources sit in a group the panel never declares:\n  ".implode("\n  ", $scattered));
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
