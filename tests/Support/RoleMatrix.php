<?php

namespace Tests\Support;

use App\Models\Asset;
use App\Support\Navigation;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Throwable;

/**
 * Scaffolding for the role × screen matrix — see EveryRoleMeetsEveryScreenTest for what it proves.
 *
 * A CLASS rather than file-scope functions, for the reason {@see FilterSweep} gives and CLAUDE.md
 * records three times: the sweep is sharded across several test files, Pest loads each file into
 * whichever parallel worker owns it, and a helper declared in two of them is a fatal redeclaration
 * that exits the whole suite with no output on either stream.
 */
final class RoleMatrix
{
    /**
     * How many files the matrix is split across. Must match the shard files on disk.
     *
     * Five, not three, and the number was measured rather than picked: at three the heaviest shard
     * ran 110s, which is ABOVE this suite's whole wall-clock (~75–97s) — Pest parallelises per
     * FILE, so that one file would have become the floor under every run. The cost is concentrated
     * in the broad roles, so they are dealt from {@see BY_BREADTH} rather than alphabetically —
     * see that constant for what happened when they were not.
     */
    public const SHARDS = 5;

    /**
     * The roles, ordered BROADEST FIRST so the round-robin below balances.
     *
     * The cost of a shard is dominated by how many screens its roles can OPEN, because each of
     * those is a full page render while a refusal is ~7ms. Four roles carry almost all of it —
     * `super_admin` reaches 99 screens, `manager` 98, `mall_admin` 97, `viewer` 96 — and dealing an
     * ALPHABETICAL list round-robin put three of them in one shard: measured at 122s, worse than
     * the unsharded-by-role arrangement it replaced. Ordering by breadth first deals them to four
     * different shards.
     *
     * The order is stated rather than computed: working it out would mean asking the database how
     * many screens each role can reach before deciding how to split the work, and the shards have
     * to partition identically in every worker without consulting anything.
     */
    public const BY_BREADTH = [
        'super_admin', 'manager', 'mall_admin', 'viewer', 'owner',
        'accounting', 'operations', 'leasing', 'coordinator',
        'technician', 'vendor', 'hr', 'customer_service', 'marketing',
    ];

    /** Every role the seeder ships, from the registry rather than restated. */
    public static function roles(): array
    {
        $roles = array_keys(RolesPermissionsSeeder::ROLES);
        sort($roles);

        return $roles;
    }

    /**
     * One shard's roles, dealt from {@see BY_BREADTH}.
     *
     * `rolesForShard()` is what the partition guard in EveryRoleMeetsEveryScreenTest checks against
     * `roles()`, so a role added to the seeder and forgotten here fails the build rather than going
     * untested — which is the failure this whole split could otherwise introduce.
     */
    public static function rolesForShard(int $shard, int $of = self::SHARDS): array
    {
        return array_values(array_filter(
            self::BY_BREADTH,
            fn ($_, int $i): bool => $i % $of === $shard - 1,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Sign in as a fresh user holding one role, on a CLEAN session.
     *
     * The flush is load-bearing. `AuthenticateSession` stamps the signed-in user's password hash
     * into the session and logs out when it stops matching — so swapping `actingAs()` from one
     * role's user to the next inside one test leaves the second user redirected to `/login`, and
     * every screen answers **302**. That reads as a matrix in which nothing is reachable and
     * nothing is refused, which a less careful set of assertions would have called a pass.
     */
    public static function actAs(object $test, string $role, Asset $asset): void
    {
        $test->flushSession();
        $test->actingAs(makeUser($role, [$asset->id]));
    }

    /** Where a screen lives — a resource answers on its index, a page on itself. */
    public static function url(string $screen, Asset $asset): string
    {
        return is_subclass_of($screen, Resource::class)
            ? $screen::getUrl('index', tenant: $asset)
            : $screen::getUrl(tenant: $asset);
    }

    /** A fresh tally: what each role reached, what it was refused, and what went wrong. */
    public static function report(): array
    {
        return ['reached' => [], 'refused' => [], 'failures' => []];
    }

    /**
     * Hit every screen as one role and classify the answer.
     *
     * 200 where the screen says yes, 403 where it says no, and nothing else. A **500** on the yes
     * side is a page whose own contents assume a permission its gate does not require — the
     * failure `canAccess()` alone can never see, and the reason this goes through the real route
     * rather than asking the class.
     */
    public static function sweepRole(object $test, string $role, Asset $asset, array &$report): void
    {
        self::actAs($test, $role, $asset);

        $report['reached'][$role] = [];
        $report['refused'][$role] = [];

        asTenant($asset, function () use ($test, $role, $asset, &$report) {
            foreach (Navigation::placed() as $screen) {
                $declared = $screen::canAccess();
                $label = $role.' → '.class_basename($screen);

                try {
                    $status = $test->get(self::url($screen, $asset))->status();
                } catch (Throwable $e) {
                    $report['failures'][] = $label.' → threw '.$e::class.': '.$e->getMessage();

                    continue;
                }

                if ($declared) {
                    $status === 200
                        ? $report['reached'][$role][] = $screen
                        : $report['failures'][] = $label.' → canAccess() is TRUE but the route answered '.$status;

                    continue;
                }

                // Refused. 403 is the policy; 200 is a hole; anything else is a crash in the
                // refusal path — still a bug, even though nothing leaked.
                $status === 403
                    ? $report['refused'][$role][] = $screen
                    : $report['failures'][] = $label.' → canAccess() is FALSE but the route answered '.$status;
            }
        });
    }

    /** Sweep one shard's roles and assert the matrix, with controls on both directions. */
    public static function assertShard(object $test, int $shard): void
    {
        $test->seed(RolesPermissionsSeeder::class);
        $test->seed(DemoSeeder::class);
        ensureAllPropertiesAsset();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $asset = Asset::query()->where('code', '!=', Asset::ALL_PROPERTIES_CODE)->firstOrFail();

        $screens = Navigation::placed();
        expect(count($screens))->toBeGreaterThanOrEqual(90, 'Screen discovery collapsed — the shard would report on almost nothing.');

        $report = self::report();

        foreach (self::rolesForShard($shard) as $role) {
            self::sweepRole($test, $role, $asset, $report);
        }

        expect($report['failures'])->toBe([], "Role × screen failures (shard {$shard}):\n".implode("\n", $report['failures']));

        // Controls. A matrix that refused everything satisfies every refusal assertion, and one
        // that allowed everything satisfies every 200 — so each shard proves it saw both answers,
        // and that at least one role in it is genuinely narrower than the panel.
        $reachedAny = array_sum(array_map('count', $report['reached']));
        $refusedAny = array_sum(array_map('count', $report['refused']));

        expect($reachedAny)->toBeGreaterThan(0, "Shard {$shard} reached no screen at all.");
        expect($refusedAny)->toBeGreaterThan(0, "Shard {$shard} was refused nothing — isolation is not being exercised.");

        foreach ($report['reached'] as $role => $reached) {
            $role === 'super_admin'
                ? expect($reached)->toHaveCount(count($screens), 'super_admin cannot reach every screen.')
                : expect(count($reached))->toBeLessThan(count($screens), "{$role} reaches every screen — it is a super admin in all but name.");
        }
    }
}
