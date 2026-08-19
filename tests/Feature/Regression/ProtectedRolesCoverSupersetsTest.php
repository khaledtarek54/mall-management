<?php

use App\Filament\Admin\Resources\Users\UserResource;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Permission\Models\Role;

/**
 * A role that confers everything a protected role confers must itself be protected.
 *
 * `UserResource::PROTECTED_ROLES` is a hand-written list, and `mall_admin` was missing from it —
 * a live privilege escalation. `mall_admin` is seeded as `$managerPerms + imports.execute`, a
 * strict superset of the protected `manager`, so protecting `manager` alone protected nothing:
 * any `users.edit` holder (`hr` has it, with no `roles.edit`) could grant themselves the superset
 * and obtain manager power plus the import right `$managerPerms` deliberately withholds.
 *
 * The one-line fix was adding `mall_admin`. This test is the part that lasts: it derives the
 * answer from the seeded permission sets instead of trusting the list, so the next role added
 * above `manager` fails the build rather than quietly reopening the hole.
 *
 * It deliberately does NOT assert the list's exact contents — a role may be protected for reasons
 * that have nothing to do with permission counts (`super_admin` is protected by definition, and
 * holds every permission). The rule is one-directional: covering a protected role ⇒ protected.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('protects every role whose permissions cover a protected role', function () {
    $permissionsOf = fn (string $role): array => Role::where('name', $role)
        ->firstOrFail()
        ->permissions
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    $protected = UserResource::PROTECTED_ROLES;
    $offenders = [];

    foreach (Role::pluck('name') as $candidate) {
        if (in_array($candidate, $protected, true)) {
            continue;
        }

        $candidatePerms = $permissionsOf($candidate);

        foreach ($protected as $protectedRole) {
            $protectedPerms = $permissionsOf($protectedRole);

            // Empty protected sets would make "covers" vacuously true for everyone.
            if ($protectedPerms === []) {
                continue;
            }

            if (array_diff($protectedPerms, $candidatePerms) === []) {
                $offenders[] = sprintf(
                    '%s covers protected role %s (and adds %d more) but is grantable by any users.edit holder',
                    $candidate,
                    $protectedRole,
                    count(array_diff($candidatePerms, $protectedPerms)),
                );
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('finds mall_admin to cover manager but for one withheld key — the fact the gate rests on', function () {
    // A control: if the seeder ever stops making mall_admin cover manager, the test above goes
    // vacuously green, and this is what says so out loud.
    //
    // It is a superset SAVE FOR `RolesPermissionsSeeder::MALL_ADMIN_WITHHELD` (2026-08-19). The
    // activity feed spans every property and carries no `asset_id`, so a property-restricted admin
    // must not hold it. That does not weaken anything here: withholding a right REDUCES mall_admin,
    // and it stays in `PROTECTED_ROLES` either way. What would weaken it is a second exclusion
    // nobody reviewed, so the gap is asserted EXACTLY — and read from the seeder's own constant, so
    // this test cannot drift into carrying a stale copy of the answer.
    $manager = Role::where('name', 'manager')->firstOrFail()->permissions->pluck('name')->all();
    $mallAdmin = Role::where('name', 'mall_admin')->firstOrFail()->permissions->pluck('name')->all();

    expect(array_values(array_diff($manager, $mallAdmin)))
        ->toBe(array_values(RolesPermissionsSeeder::MALL_ADMIN_WITHHELD))
        ->and(array_diff($mallAdmin, $manager))->not->toBe([]);
});

it('keeps mall_admin in the protected list', function () {
    expect(UserResource::PROTECTED_ROLES)->toContain('mall_admin');
});
