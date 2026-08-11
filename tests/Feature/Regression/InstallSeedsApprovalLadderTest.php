<?php

use App\Models\ApprovalRule;
use App\Models\Department;
use App\Models\User;
use App\Support\ApprovalPolicy;

/**
 * `atriom:install` must leave a database whose spend controls actually work.
 *
 * `ApprovalRulesSeeder` and `DepartmentSeeder` were reachable only from `DatabaseSeeder` — the
 * dev/demo chain. `atriom:install` ran roles and accounting and nothing else, so on every real
 * install `approval_rules` was **empty**, and an empty ladder is fail-open by design:
 * `ApprovalPolicy::permissionFor()` returns null and `canApprove()` returns true for **any**
 * amount. FR-CM-11 (spare-part tiers) and FR-PROC-02 (purchase-request tiers) — contractual
 * controls — simply did not exist in production.
 *
 * Base RBAC still applied, so this was lost *value tiering* rather than open season; the audit
 * trail lost more than the gate did, because `required_permission` froze as `null` and could not
 * even record which tier had been required.
 *
 * **The suite could not see it**: 16 test files seed `ApprovalRulesSeeder` themselves, so every
 * approval test ran against a state production never reached. This test deliberately does the
 * opposite — it asserts the ladder is fail-open when empty (documenting the real risk) and then
 * that a real install is not in that state.
 *
 * Departments had a worse shape: `DepartmentResource::canCreate()` returns false because the set
 * is "seeded", so an install that skipped the seeder had an empty table **forever, with no in-app
 * remedy**, and tenant-request auto-routing silently off.
 */
it('leaves an empty approval ladder fail-open — the risk this install step removes', function () {
    expect(ApprovalRule::count())->toBe(0);

    $user = User::factory()->create();

    // Documented, deliberate behaviour (ApprovalPolicy: "an operator who hasn't set up approval
    // for procurement shouldn't find procurement unusable") — which is exactly why the seeder
    // not running is dangerous rather than merely incomplete.
    expect(ApprovalPolicy::canApprove($user, ApprovalRule::MODULE_PURCHASE_REQUEST, 5_000_000.0))
        ->toBeTrue();
});

it('seeds the approval ladder', function () {
    $this->artisan('atriom:install', ['--force' => true])->assertExitCode(0);

    expect(ApprovalRule::count())->toBeGreaterThan(0);
});

it('seeds departments, which no screen can create', function () {
    $this->artisan('atriom:install', ['--force' => true])->assertExitCode(0);

    expect(Department::count())->toBeGreaterThan(0);
});

it('produces an install where a large spend is actually tiered', function () {
    $this->artisan('atriom:install', ['--force' => true])->assertExitCode(0);

    // The whole point: after a real install, a big number requires a permission — so a user who
    // holds none is refused. Before this, the same call returned true.
    $nobody = User::factory()->create();

    expect(ApprovalPolicy::canApprove($nobody, ApprovalRule::MODULE_PURCHASE_REQUEST, 5_000_000.0))
        ->toBeFalse();
});

it('is idempotent — a re-run does not duplicate the ladder', function () {
    $this->artisan('atriom:install', ['--force' => true])->assertExitCode(0);
    $first = ApprovalRule::count();

    $this->artisan('atriom:install', ['--force' => true])->assertExitCode(0);

    expect(ApprovalRule::count())->toBe($first);
});
