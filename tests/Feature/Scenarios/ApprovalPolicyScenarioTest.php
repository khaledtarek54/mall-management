<?php

use App\Models\ApprovalRule;
use App\Support\ApprovalPolicy;
use Database\Seeders\ApprovalRulesSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The approval ladder (FR-CM-11, and FR-PROC-02 later).
 *
 * Nothing in Atriom did amount-based approval before this — the only precedents were two
 * flat single-boolean approve() verbs with no value tiers at all.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ApprovalRulesSeeder::class);
});

const DRAW = ApprovalRule::MODULE_INVENTORY_DRAW;

/* ---- resolving an amount to a tier -------------------------------------- */

it('sends a higher-value request to a higher tier', function () {
    // The entire point of FR-CM-11.
    expect(ApprovalPolicy::permissionFor(DRAW, 500))->toBe(ApprovalRule::TIER_1);
    expect(ApprovalPolicy::permissionFor(DRAW, 5000))->toBe(ApprovalRule::TIER_2);
    expect(ApprovalPolicy::permissionFor(DRAW, 50000))->toBe(ApprovalRule::TIER_3);
});

it('puts a boundary amount in exactly one band', function () {
    // min inclusive, max exclusive — so 1000 belongs to the second band only. Overlapping
    // bands would make "which approval do I need?" depend on row order.
    expect(ApprovalPolicy::permissionFor(DRAW, 999.99))->toBe(ApprovalRule::TIER_1);
    expect(ApprovalPolicy::permissionFor(DRAW, 1000))->toBe(ApprovalRule::TIER_2);
    expect(ApprovalPolicy::permissionFor(DRAW, 9999.99))->toBe(ApprovalRule::TIER_2);
    expect(ApprovalPolicy::permissionFor(DRAW, 10000))->toBe(ApprovalRule::TIER_3);
});

it('treats a zero-value request as the lowest band, not as no approval', function () {
    expect(ApprovalPolicy::permissionFor(DRAW, 0))->toBe(ApprovalRule::TIER_1);
});

it('needs no approval for a module with no ladder configured', function () {
    // An operator who hasn't set procurement up shouldn't find procurement unusable.
    expect(ApprovalPolicy::permissionFor('procurement', 5000))->toBeNull();
    expect(ApprovalPolicy::isRequired('procurement', 5000))->toBeFalse();
});

it('ignores a deactivated band', function () {
    ApprovalRule::where('module', DRAW)->where('min_amount', 0)->update(['is_active' => false]);

    // 500 now falls in no band — and must fail CLOSED, not open.
    expect(ApprovalPolicy::permissionFor(DRAW, 500))->toBe(ApprovalRule::TIER_3);
});

it('fails closed to the STRICTEST tier, not merely the last band', function () {
    // Nothing forces a band's tier to rise with its amount, so "the highest band" is not
    // "the highest authority". Configured out of order, picking the last band handed a gap
    // the WEAKEST tier — failing open, in the one mechanism that exists to fail closed.
    ApprovalRule::query()->delete();
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 0, 'max_amount' => 100, 'required_permission' => ApprovalRule::TIER_3]);
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 5000, 'max_amount' => null, 'required_permission' => ApprovalRule::TIER_1]);

    expect(ApprovalPolicy::permissionFor(DRAW, 1000))->toBe(ApprovalRule::TIER_3);
    expect(ApprovalPolicy::canApprove(makeUser('operations'), DRAW, 1000))->toBeFalse();
});

it('treats an unrecognised permission as the strictest thing there is', function () {
    // An unknown requirement is not a licence to skip it.
    ApprovalRule::query()->delete();
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 0, 'max_amount' => 100, 'required_permission' => 'some.custom.permission']);
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 5000, 'max_amount' => null, 'required_permission' => ApprovalRule::TIER_1]);

    expect(ApprovalPolicy::permissionFor(DRAW, 1000))->toBe('some.custom.permission');
    // And it is checked literally — nobody holds it, so nobody can approve the gap.
    expect(ApprovalPolicy::canApprove(makeUser('super_admin'), DRAW, 1000))->toBeFalse();
});

it('answers only the approval question — it is not an action gate', function () {
    // Documented contract, pinned so it can't drift into a silent hole: with no ladder,
    // nothing needs approving, so this is true even for a viewer. Callers must gate the
    // ACTION separately (inventory.create, etc.).
    expect(ApprovalPolicy::canApprove(makeUser('viewer'), 'procurement', 99999))->toBeTrue();
    expect(ApprovalPolicy::isRequired('procurement', 99999))->toBeFalse();
});

it('fails closed when the ladder has a gap', function () {
    // A misconfiguration must make approval HARDER, never wave the spend through. This is
    // the difference between a safe default and a silent hole in spending control.
    ApprovalRule::query()->delete();
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 0, 'max_amount' => 100, 'required_permission' => ApprovalRule::TIER_1]);
    ApprovalRule::create(['module' => DRAW, 'min_amount' => 5000, 'max_amount' => null, 'required_permission' => ApprovalRule::TIER_3]);

    // 1000 is in the hole between the bands.
    expect(ApprovalPolicy::permissionFor(DRAW, 1000))->toBe(ApprovalRule::TIER_3);
});

/* ---- who may sign it off ------------------------------------------------ */

it('lets a supervisor approve a small draw but not a large one', function () {
    $ops = makeUser('operations');

    expect(ApprovalPolicy::canApprove($ops, DRAW, 500))->toBeTrue();
    expect(ApprovalPolicy::canApprove($ops, DRAW, 5000))->toBeFalse();
    expect(ApprovalPolicy::canApprove($ops, DRAW, 50000))->toBeFalse();
});

it('lets a manager approve up to the mid band, and escalates above it', function () {
    // The manager blanket grant would otherwise hand over every tier, and a ladder whose
    // top rung everyone reaches is not a ladder. Large spend escalates — FR-CM-11's point.
    $manager = makeUser('manager');

    expect(ApprovalPolicy::canApprove($manager, DRAW, 500))->toBeTrue();
    expect(ApprovalPolicy::canApprove($manager, DRAW, 5000))->toBeTrue();
    expect(ApprovalPolicy::canApprove($manager, DRAW, 50000))->toBeFalse();
});

it('lets a super_admin approve anything', function () {
    $admin = makeUser('super_admin');

    expect(ApprovalPolicy::canApprove($admin, DRAW, 50000))->toBeTrue();
});

it('treats authority as cumulative — a higher tier can approve a lower band', function () {
    // Otherwise a manager would be blocked from approving a small draw a supervisor could
    // handle: four disconnected locks rather than a ladder.
    $manager = makeUser('manager');

    expect($manager->can(ApprovalRule::TIER_1))->toBeTrue();
    expect(ApprovalPolicy::canApprove($manager, DRAW, 10))->toBeTrue();
});

it('refuses a user with no approval permission at all', function () {
    expect(ApprovalPolicy::canApprove(makeUser('viewer'), DRAW, 10))->toBeFalse();
});

it('refuses a guest', function () {
    expect(ApprovalPolicy::canApprove(null, DRAW, 10))->toBeFalse();
});

/* ---- the bands are data, and must stay coherent ------------------------- */

it('refuses an inverted band', function () {
    // Would match nothing, silently disabling approval for its range.
    expect(fn () => ApprovalRule::create([
        'module' => DRAW, 'min_amount' => 5000, 'max_amount' => 100,
        'required_permission' => ApprovalRule::TIER_1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a negative band', function () {
    expect(fn () => ApprovalRule::create([
        'module' => DRAW, 'min_amount' => -5, 'max_amount' => 100,
        'required_permission' => ApprovalRule::TIER_1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses an unknown module', function () {
    expect(fn () => ApprovalRule::create([
        'module' => 'nonsense', 'min_amount' => 0, 'max_amount' => 100,
        'required_permission' => ApprovalRule::TIER_1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('seeds a ladder that tiles the whole line with no gap or overlap', function () {
    // ApprovalPolicy fails closed on a gap, but a ladder that needs its safety net to be
    // correct is one bad edit from surprising someone.
    $bands = ApprovalRule::forModule(DRAW)->orderBy('min_amount')->get();

    expect($bands)->toHaveCount(3);
    expect((float) $bands[0]->min_amount)->toBe(0.0);
    expect($bands->last()->max_amount)->toBeNull();

    foreach ($bands as $i => $band) {
        if ($i === 0) {
            continue;
        }
        // Each band starts exactly where the previous one ended.
        expect((float) $band->min_amount)->toBe((float) $bands[$i - 1]->max_amount);
    }
});
