<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Regenerates tests/e2e/rbac-matrix.json — for each non-super-admin role, whether
 * it can view a curated set of "probe" modules. The Playwright RBAC spec
 * (21-rbac-smoke) drives each role's saved session at every probe URL and asserts
 * allowed (canView) vs forbidden (403), so the access matrix is derived from the
 * real permission set rather than hand-maintained guesses.
 *
 * Run this after changing role permissions in RolesPermissionsSeeder.
 */
class DumpRbacMatrix extends Command
{
    protected $signature = 'atriom:dump-rbac-matrix {--check : Fail if the committed matrix is stale}';

    protected $description = 'Regenerate the per-role RBAC access matrix used by the Playwright RBAC spec';

    public const PATH = 'tests/e2e/rbac-matrix.json';

    /** Probe module => admin route slug. Chosen to differentiate the roles cleanly. */
    public const PROBES = [
        'assets' => 'assets',
        'units' => 'units',
        'leases' => 'leases',
        'invoices' => 'invoices',
        'payments' => 'payments',
        'credit_notes' => 'credit-notes',
        'journal_entries' => 'journal-entries',
        'ledger_accounts' => 'ledger-accounts',
        'vendors' => 'vendors',
        'maintenance' => 'requests',
        'tenant_sales' => 'tenant-sales-declarations',
        'employees' => 'employees',
        'marketing' => 'marketing-budgets',
        'roles' => 'roles',
        'utility_meters' => 'utility-meters',
    ];

    /** Roles driven by the spec (super_admin sees all, so it isn't probed here). */
    public const ROLES = ['manager', 'viewer', 'leasing', 'operations', 'accounting', 'marketing', 'hr'];

    public function handle(): int
    {
        $live = static::matrix();
        $json = json_encode($live, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = base_path(self::PATH);

        if ($this->option('check')) {
            $current = is_file($path) ? file_get_contents($path) : '';
            if (trim($current) !== trim($json)) {
                $this->error(self::PATH.' is stale — run `php artisan atriom:dump-rbac-matrix`.');

                return self::FAILURE;
            }
            $this->info(self::PATH.' is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->info('Wrote RBAC matrix for '.count($live['roles']).' roles to '.self::PATH);

        return self::SUCCESS;
    }

    /** Probe slugs that expose a Create route (marketing-budgets has none). */
    public const NO_CREATE = ['marketing-budgets'];

    public static function matrix(): array
    {
        $roles = Role::with('permissions')->get()->keyBy('name');

        $out = [];
        foreach (self::ROLES as $roleName) {
            $role = $roles->get($roleName);
            $perms = $role ? $role->permissions->pluck('name')->all() : [];
            $access = [];
            foreach (self::PROBES as $module => $slug) {
                $access[$slug] = [
                    'view' => in_array("{$module}.view", $perms, true),
                    'create' => in_array("{$module}.create", $perms, true),
                    'hasCreate' => ! in_array($slug, self::NO_CREATE, true),
                ];
            }
            $out[$roleName] = $access;
        }

        return ['probes' => self::PROBES, 'roles' => $out];
    }
}
