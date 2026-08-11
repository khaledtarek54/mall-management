<?php

use App\Filament\Admin\Resources\ApprovalRules\ApprovalRuleResource;
use App\Filament\Admin\Resources\ApprovalRules\Pages\ListApprovalRules;
use App\Models\ApprovalRule;
use App\Support\ApprovalPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The approval ladder is editable by an operator (FRD phase 3, the row that stayed open).
 *
 * The bands were enforced from the first day and could only be changed by a seeder and a deploy —
 * so the ladder the FRD calls company policy was in practice a developer's constant. This is the
 * screen; these tests are about who may open it and whether editing it actually moves the gate.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    // Authenticate BEFORE setting the tenant — Filament's TenantSet event carries the user, so the
    // order is not cosmetic. The admin panel is property-tenanted, so its routes carry a {tenant}
    // segment even for a SHARED resource like this one: the resource opts out of the auto-scope,
    // not out of the URL.
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('is open to the permission that exists for it, and closed to a manager', function () {
    // `approvals.manage_rules` is withheld from `manager` on purpose, for the same reason
    // `approvals.tier_3` is: a ladder whose rungs the people climbing it can rewrite is not a
    // ladder. Paired with a positive case, or this would pass just as well if the gate were broken
    // shut for everyone.
    $this->actingAs(makeUser('super_admin'));
    expect(ApprovalRuleResource::canViewAny())->toBeTrue()
        ->and(ApprovalRuleResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('manager'));
    expect(ApprovalRuleResource::canViewAny())->toBeFalse()
        ->and(ApprovalRuleResource::canCreate())->toBeFalse();

    $this->actingAs(makeUser('viewer'));
    expect(ApprovalRuleResource::canViewAny())->toBeFalse();
});

it('renders the ladder', function () {
    $this->actingAs(makeUser('super_admin'));

    $rule = ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 0,
        'max_amount' => 5000,
        'required_permission' => ApprovalRule::TIER_1,
    ]);

    Livewire::test(ListApprovalRules::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$rule]);
});

it('changes who has to sign off when a band is edited — the point of the screen', function () {
    $this->actingAs(makeUser('super_admin'));

    ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 0, 'max_amount' => 5000,
        'required_permission' => ApprovalRule::TIER_1,
    ]);
    $upper = ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 5000, 'max_amount' => null,
        'required_permission' => ApprovalRule::TIER_2,
    ]);

    expect(ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 9000))
        ->toBe(ApprovalRule::TIER_2);

    // The operator decides large purchases need senior sign-off. One row, no deploy.
    $upper->update(['required_permission' => ApprovalRule::TIER_3]);

    expect(ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 9000))
        ->toBe(ApprovalRule::TIER_3)
        // …and the band below is untouched, so raising the ceiling did not raise the floor.
        ->and(ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 100))
        ->toBe(ApprovalRule::TIER_1);
});

it('refuses an inverted band at the model, not only in the form', function () {
    // The form carries `->gt('min_amount')` for the inline error; the model is the actual guard,
    // because an inverted band matches nothing and would silently disable approval for its range
    // rather than fail loudly.
    $this->actingAs(makeUser('super_admin'));

    expect(fn () => ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 5000, 'max_amount' => 1000,
        'required_permission' => ApprovalRule::TIER_1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('makes the gate STRICTER when a band is deactivated, never looser', function () {
    // The safety property behind classifying these rows as deletable configuration: with no band
    // covering an amount, ApprovalPolicy falls back to the strictest tier configured. Removing a
    // rung cannot open the gate.
    $this->actingAs(makeUser('super_admin'));

    $low = ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 0, 'max_amount' => 5000,
        'required_permission' => ApprovalRule::TIER_1,
    ]);
    ApprovalRule::create([
        'module' => ApprovalRule::MODULE_PURCHASE_REQUEST,
        'min_amount' => 5000, 'max_amount' => null,
        'required_permission' => ApprovalRule::TIER_3,
    ]);

    expect(ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 100))
        ->toBe(ApprovalRule::TIER_1);

    $low->update(['is_active' => false]);

    expect(ApprovalPolicy::permissionFor(ApprovalRule::MODULE_PURCHASE_REQUEST, 100))
        ->toBe(ApprovalRule::TIER_3);
});
