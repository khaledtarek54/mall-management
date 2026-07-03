<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source of truth for the platform's RBAC catalog.
 *
 * - PERMISSIONS — full list of granular permission keys ("module.action"),
 *   grouped by module so it's obvious what each module exposes.
 * - ROLES        — built-in roles and the permission sets they receive.
 *
 * Custom roles created by admins via the UI live in the `roles` table
 * alongside these and can hold any combination of the same permissions.
 *
 * The five DEPARTMENT roles (leasing / operations / accounting / marketing /
 * hr) are **strictly scoped** to their own department's resources — each sees
 * only its own sidebar group. `super_admin` and `manager` are cross-department.
 */
class RolesPermissionsSeeder extends Seeder
{
    /** @var array<string, string> name => description */
    public const ROLES = [
        'super_admin' => 'Full access — create, edit, delete, view everything plus settings + role management.',
        'manager'     => 'General manager — create + edit on every module, no delete, no settings.',
        'viewer'      => 'Read-only access for stakeholders + auditors.',
        'owner'       => 'Jawad owner — read-only oversight of owned properties in the admin app + owner requests.',
        'leasing'     => 'Leasing department — properties, units, tenants, leases, sales.',
        'operations'  => 'Operations department — maintenance, vendor dispatch, meters.',
        'accounting'  => 'Accounting department — invoices, payments, credit notes, CAM, reports.',
        'marketing'   => 'Marketing department — the marketing budget + spend.',
        'hr'          => 'HR department — staff accounts, roles, departments.',
    ];

    /**
     * Full permission catalog, grouped by module. The seeder iterates this
     * to create rows in the `permissions` table. The Filament Role resource
     * uses the same grouping to render its permission picker.
     *
     * @var array<string, array<string, string>>
     */
    public const PERMISSIONS = [
        'assets' => [
            'assets.view'   => 'View properties',
            'assets.create' => 'Create properties',
            'assets.edit'   => 'Edit properties',
            'assets.delete' => 'Delete properties',
        ],
        'units' => [
            'units.view'   => 'View units',
            'units.create' => 'Create units',
            'units.edit'   => 'Edit units',
            'units.delete' => 'Delete units',
        ],
        'tenants' => [
            'tenants.view'   => 'View tenants',
            'tenants.create' => 'Create tenants',
            'tenants.edit'   => 'Edit tenants',
            'tenants.delete' => 'Delete tenants',
        ],
        'leases' => [
            'leases.view'             => 'View leases',
            'leases.create'           => 'Create leases',
            'leases.edit'             => 'Edit leases',
            'leases.delete'           => 'Delete leases',
            'leases.terminate'        => 'Terminate leases',
            'leases.renew'            => 'Renew leases',
            'leases.generate_invoice' => 'Generate invoices from a lease',
        ],
        'invoices' => [
            'invoices.view'                => 'View invoices',
            'invoices.create'              => 'Create invoices',
            'invoices.edit'                => 'Edit invoices',
            'invoices.delete'              => 'Delete invoices',
            'invoices.run_monthly_billing' => 'Run monthly billing for all active leases',
            'invoices.submit_to_eta'       => 'Submit invoices to the Egyptian Tax Authority',
            'invoices.send_whatsapp'       => 'Send invoice via WhatsApp',
        ],
        'payments' => [
            'payments.view'   => 'View payments',
            'payments.create' => 'Record payments',
            'payments.edit'   => 'Edit payments',
            'payments.delete' => 'Delete payments',
        ],
        'credit_notes' => [
            'credit_notes.view'   => 'View credit notes',
            'credit_notes.create' => 'Create credit notes',
            'credit_notes.edit'   => 'Edit credit notes',
            'credit_notes.delete' => 'Delete credit notes',
            'credit_notes.issue'  => 'Issue a draft credit note',
            'credit_notes.apply'  => 'Apply a credit note to an invoice',
            'credit_notes.void'   => 'Void a credit note',
        ],
        'maintenance' => [
            'maintenance.view'          => 'View maintenance requests',
            'maintenance.create'        => 'Create maintenance requests',
            'maintenance.edit'          => 'Edit maintenance requests',
            'maintenance.delete'        => 'Delete maintenance requests',
            'maintenance.assign'        => 'Assign maintenance requests to staff or vendors',
            'maintenance.change_status' => 'Move requests across status transitions',
        ],
        'tenant_sales' => [
            'tenant_sales.view'    => 'View tenant sales declarations',
            'tenant_sales.create'  => 'Create tenant sales declarations',
            'tenant_sales.edit'    => 'Edit tenant sales declarations',
            'tenant_sales.delete'  => 'Delete tenant sales declarations',
            'tenant_sales.lock'    => 'Lock a declaration + generate percentage-rent charge',
            'tenant_sales.dispute' => 'Mark a declaration as disputed',
        ],
        'cam' => [
            'cam.view'                 => 'View CAM pools and allocations',
            'cam.create'               => 'Create CAM expense pools',
            'cam.edit'                 => 'Edit CAM expense pools',
            'cam.delete'               => 'Delete CAM expense pools',
            'cam.generate_allocations' => 'Generate per-lease allocations from a pool',
            'cam.bill_allocation'      => 'Bill a CAM allocation (creates a true-up charge)',
            'cam.mark_reconciled'      => 'Close a pool as fully reconciled',
        ],
        'ledger_accounts' => [
            'ledger_accounts.view'   => 'View the chart of accounts',
            'ledger_accounts.create' => 'Create ledger accounts',
            'ledger_accounts.edit'   => 'Edit ledger accounts',
            'ledger_accounts.delete' => 'Delete ledger accounts',
        ],
        'journal_entries' => [
            'journal_entries.view'   => 'View journal entries',
            'journal_entries.create' => 'Create manual journal entries',
            'journal_entries.edit'   => 'Edit draft journal entries',
            'journal_entries.delete' => 'Delete journal entries',
            'journal_entries.post'   => 'Post a journal entry to the ledger',
            'journal_entries.void'   => 'Void (reverse) a posted journal entry',
        ],
        'accounting_periods' => [
            'accounting_periods.view'   => 'View fiscal years and accounting periods',
            'accounting_periods.manage' => 'Open / close accounting periods',
        ],
        'general_ledger' => [
            'general_ledger.view' => 'View the trial balance, general ledger, and financial statements',
        ],
        'vendor_bills' => [
            'vendor_bills.view'    => 'View vendor bills (accounts payable)',
            'vendor_bills.create'  => 'Create vendor bills',
            'vendor_bills.edit'    => 'Edit vendor bills',
            'vendor_bills.delete'  => 'Delete vendor bills',
            'vendor_bills.approve' => 'Approve a vendor bill (makes it postable)',
            'vendor_bills.pay'     => 'Record a payment against a vendor bill',
        ],
        'expenses' => [
            'expenses.view'   => 'View direct / petty-cash expenses',
            'expenses.create' => 'Record direct expenses',
            'expenses.edit'   => 'Edit direct expenses',
            'expenses.delete' => 'Delete direct expenses',
        ],
        'payrolls' => [
            'payrolls.view'    => 'View payroll runs',
            'payrolls.create'  => 'Create payroll runs',
            'payrolls.edit'    => 'Edit payroll runs',
            'payrolls.delete'  => 'Delete payroll runs',
            'payrolls.approve' => 'Approve a payroll run (makes it postable)',
        ],
        'deposit_transactions' => [
            'deposit_transactions.view'   => 'View security-deposit transactions',
            'deposit_transactions.create' => 'Record security-deposit transactions',
            'deposit_transactions.edit'   => 'Edit security-deposit transactions',
            'deposit_transactions.delete' => 'Delete security-deposit transactions',
        ],
        'utility_meters' => [
            'utility_meters.view'   => 'View utility meters',
            'utility_meters.create' => 'Create utility meters',
            'utility_meters.edit'   => 'Edit utility meters',
            'utility_meters.delete' => 'Delete utility meters',
        ],
        'vendors' => [
            'vendors.view'   => 'View vendors',
            'vendors.create' => 'Create vendors',
            'vendors.edit'   => 'Edit vendors',
            'vendors.delete' => 'Delete vendors',
        ],
        'departments' => [
            'departments.view'   => 'View departments',
            'departments.create' => 'Create departments',
            'departments.edit'   => 'Edit departments',
            'departments.delete' => 'Delete departments',
        ],
        'marketing' => [
            'marketing.view'   => 'View marketing budgets and spend',
            'marketing.create' => 'Create marketing budgets and spend',
            'marketing.edit'   => 'Edit marketing budgets and spend',
            'marketing.delete' => 'Delete marketing budgets and spend',
        ],
        'owner_requests' => [
            'owner_requests.view'   => 'View owner requests',
            'owner_requests.create' => 'Create owner requests',
            'owner_requests.edit'   => 'Respond to / edit owner requests',
            'owner_requests.delete' => 'Delete owner requests',
        ],
        'notes' => [
            'notes.view'   => 'View communications log entries',
            'notes.create' => 'Log communications',
            'notes.edit'   => 'Edit communications log entries',
            'notes.delete' => 'Delete communications log entries',
        ],
        'users' => [
            'users.view'   => 'View users',
            'users.create' => 'Create users',
            'users.edit'   => 'Edit users',
            'users.delete' => 'Delete users',
        ],
        'roles' => [
            'roles.view'   => 'View roles',
            'roles.create' => 'Create custom roles',
            'roles.edit'   => 'Edit roles + their permissions',
            'roles.delete' => 'Delete custom roles',
        ],
        'reports' => [
            'reports.view'     => 'View reports',
            'reports.download' => 'Download monthly close PDF',
        ],
        'activity_log' => [
            'activity_log.view' => 'View the activity log',
        ],
        'inventory' => [
            'inventory.view'   => 'View warehouses, items & stock movements',
            'inventory.create' => 'Create warehouses / items & receive stock',
            'inventory.edit'   => 'Edit warehouses / items & record stock movements',
            'inventory.delete' => 'Delete inventory records',
        ],
        'settings' => [
            'settings.view'   => 'View settings',
            'settings.manage' => 'Edit system settings (billing rules, SLA, integrations)',
        ],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 1) Create every permission row.
        foreach (self::PERMISSIONS as $module => $perms) {
            foreach (array_keys($perms) as $name) {
                Permission::findOrCreate($name, 'web');
            }
        }

        // 2) Create every role row.
        foreach (array_keys(self::ROLES) as $name) {
            Role::findOrCreate($name, 'web');
        }

        // 3) Wire role => permission set.
        $this->syncRolePermissions();
    }

    private function syncRolePermissions(): void
    {
        $all = $this->flatPermissionList();

        // super_admin gets EVERYTHING.
        Role::findByName('super_admin', 'web')->syncPermissions($all);

        // manager: every view/create/edit + workflow actions; no delete; no settings.manage; no roles edit.
        $managerPerms = collect($all)
            ->reject(fn ($p) => str_ends_with($p, '.delete'))
            ->reject(fn ($p) => in_array($p, ['settings.manage', 'roles.create', 'roles.edit', 'roles.delete']))
            ->values()
            ->all();
        Role::findByName('manager', 'web')->syncPermissions($managerPerms);

        // viewer: every .view + reports.download.
        $viewerPerms = collect($all)
            ->filter(fn ($p) => str_ends_with($p, '.view') || $p === 'reports.download')
            ->values()
            ->all();
        Role::findByName('viewer', 'web')->syncPermissions($viewerPerms);

        // owner: Jawad owners SEE EVERYTHING read-only (every module / department),
        // but only for the properties they OWN (scoped via User::accessibleAssets),
        // plus the right to raise + track owner requests.
        $ownerPerms = collect($all)
            ->filter(fn ($p) => str_ends_with($p, '.view') || $p === 'reports.download')
            ->push('owner_requests.create')
            ->unique()
            ->values()
            ->all();
        Role::findByName('owner', 'web')->syncPermissions($ownerPerms);

        // ---- DEPARTMENT roles: strictly scoped to their own sidebar group ----

        // leasing: Properties, Units, Tenants, Leases, Tenant Sales.
        Role::findByName('leasing', 'web')->syncPermissions([
            'assets.view',
            'units.view', 'units.create', 'units.edit',
            'tenants.view', 'tenants.create', 'tenants.edit',
            'leases.view', 'leases.create', 'leases.edit',
            'leases.terminate', 'leases.renew', 'leases.generate_invoice',
            'tenant_sales.view', 'tenant_sales.lock', 'tenant_sales.dispute',
            'notes.view', 'notes.create',
        ]);

        // operations: Maintenance, Vendors, Utility Meters, Inventory.
        Role::findByName('operations', 'web')->syncPermissions([
            'maintenance.view', 'maintenance.create', 'maintenance.edit',
            'maintenance.assign', 'maintenance.change_status',
            'vendors.view', 'vendors.create', 'vendors.edit',
            'utility_meters.view', 'utility_meters.create', 'utility_meters.edit',
            'inventory.view', 'inventory.create', 'inventory.edit',
            'notes.view', 'notes.create',
        ]);

        // accounting: Invoices, Payments, Credit Notes, CAM, Reports.
        Role::findByName('accounting', 'web')->syncPermissions([
            'invoices.view', 'invoices.create', 'invoices.edit',
            'invoices.run_monthly_billing', 'invoices.submit_to_eta', 'invoices.send_whatsapp',
            'payments.view', 'payments.create', 'payments.edit',
            'credit_notes.view', 'credit_notes.create', 'credit_notes.edit',
            'credit_notes.issue', 'credit_notes.apply', 'credit_notes.void',
            'cam.view', 'cam.create', 'cam.edit',
            'cam.generate_allocations', 'cam.bill_allocation', 'cam.mark_reconciled',
            'ledger_accounts.view', 'ledger_accounts.create', 'ledger_accounts.edit',
            'journal_entries.view', 'journal_entries.create', 'journal_entries.edit',
            'journal_entries.post', 'journal_entries.void',
            'accounting_periods.view', 'accounting_periods.manage',
            'general_ledger.view',
            'vendor_bills.view', 'vendor_bills.create', 'vendor_bills.edit',
            'vendor_bills.approve', 'vendor_bills.pay',
            'expenses.view', 'expenses.create', 'expenses.edit',
            'payrolls.view', 'payrolls.create', 'payrolls.edit', 'payrolls.approve',
            'deposit_transactions.view', 'deposit_transactions.create', 'deposit_transactions.edit',
            'reports.view', 'reports.download',
        ]);

        // marketing: Marketing Budgets + spend.
        Role::findByName('marketing', 'web')->syncPermissions([
            'marketing.view', 'marketing.create', 'marketing.edit',
        ]);

        // hr: Users, Roles, Departments.
        Role::findByName('hr', 'web')->syncPermissions([
            'users.view', 'users.create', 'users.edit',
            'roles.view',
            'departments.view',
        ]);
    }

    /** @return string[] */
    private function flatPermissionList(): array
    {
        $out = [];
        foreach (self::PERMISSIONS as $module => $perms) {
            foreach (array_keys($perms) as $name) {
                $out[] = $name;
            }
        }
        return $out;
    }
}
