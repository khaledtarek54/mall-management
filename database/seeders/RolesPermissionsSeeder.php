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
        'manager' => 'General manager — create + edit on every module, no delete, no settings.',
        'viewer' => 'Read-only access for stakeholders + auditors.',
        'owner' => 'Jawad owner — read-only oversight of owned properties in the admin app + owner requests.',
        'leasing' => 'Leasing department — properties, units, tenants, leases, sales.',
        'operations' => 'Operations department — maintenance, vendor dispatch, meters.',
        'accounting' => 'Accounting department — invoices, payments, credit notes, CAM, reports.',
        'marketing' => 'Marketing department — the marketing budget + spend.',
        'hr' => 'HR department — staff accounts, roles, departments.',
        // FR-USR-04 — the FRD's "In-house Technician: normal employee; sees only work assigned to
        // them". The one role that deliberately LACKS `*.view_all`, which is what makes the
        // assignment scope bite (App\Support\AssignmentScope).
        'technician' => 'In-house technician — sees only the requests + work orders assigned to them.',
        // FR-USR — the FRD's "Coordinator: manages assignment and oversight of requests/work
        // orders". Holds `*.view_all` (sees the whole board, unlike the technician) plus
        // `maintenance.assign` — you cannot hand out work you cannot see. The maintenance-queue
        // supervisor; deliberately narrower than `operations` (no meters/inventory/procurement).
        'coordinator' => 'Maintenance coordinator — sees the whole request/work-order board and assigns technicians.',
        // FR-USR — the FRD's front-desk "Customer Service": logs incoming requests (intake) and
        // fields any tenant's call, so it sees every request (`view_all`) but has no work authority
        // (no assign / change_status / complete). It hands the request to a coordinator.
        'customer_service' => 'Customer service — logs and tracks tenant requests at the front desk; no work authority.',
        // FR-USR-03 — the FRD's external "Vendor": a maintenance-contractor login that is VIEW-ONLY
        // on the work it does, with ONE exception — CSV upload. FR-USR-02 restricts import to admins;
        // the vendor is the documented exception. No create/edit/delete/status-change, and no access
        // to tenant financials / leases / HR / GL (an external party). Scoped to its assigned malls
        // like every role. (Finer "only my own jobs" scoping needs a vendor-user→company link that
        // does not exist yet — today it sees its assigned malls' maintenance board, via view_all.)
        'vendor' => 'External vendor — view-only on maintenance work, plus CSV upload; no other edits.',
        // FR-USR-01 — the FRD's "Admin (per mall): full access for their assigned mall; the only
        // role that can import/upload data". Property scoping already delivers "their assigned
        // mall" (AssignedAssets); what distinguishes this role from `manager` is `imports.execute`
        // — the FRD's own table gives no other difference.
        //
        // NOT given delete: the FRD's "full access" is ambiguous and deletion is super_admin-only
        // project-wide. Raised as client question 23 rather than assumed away.
        'mall_admin' => 'Mall admin — a manager for their assigned properties, plus the right to import data.',
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
            'assets.view' => 'View properties',
            'assets.create' => 'Create properties',
            'assets.edit' => 'Edit properties',
            'assets.delete' => 'Delete properties',
        ],
        'units' => [
            'units.view' => 'View units',
            'units.create' => 'Create units',
            'units.edit' => 'Edit units',
            'units.delete' => 'Delete units',
        ],
        'tenants' => [
            'tenants.view' => 'View tenants',
            'tenants.create' => 'Create tenants',
            'tenants.edit' => 'Edit tenants',
            'tenants.delete' => 'Delete tenants',
        ],
        'leases' => [
            'leases.view' => 'View leases',
            'leases.create' => 'Create leases',
            'leases.edit' => 'Edit leases',
            'leases.delete' => 'Delete leases',
            'leases.terminate' => 'Terminate leases',
            'leases.renew' => 'Renew leases',
            'leases.generate_invoice' => 'Generate invoices from a lease',
        ],
        'invoices' => [
            'invoices.view' => 'View invoices',
            'invoices.create' => 'Create invoices',
            'invoices.edit' => 'Edit invoices',
            'invoices.void' => 'Void (cancel) an issued invoice',
            'invoices.delete' => 'Delete invoices',
            'invoices.run_monthly_billing' => 'Run monthly billing for all active leases',
            'invoices.submit_to_eta' => 'Submit invoices to the Egyptian Tax Authority',
            'invoices.send_whatsapp' => 'Send invoice via WhatsApp',
        ],
        'payments' => [
            'payments.view' => 'View payments',
            'payments.create' => 'Record payments',
            'payments.edit' => 'Edit payments',
            'payments.void' => 'Void / refund a captured payment',
            'payments.delete' => 'Delete payments',
        ],
        'credit_notes' => [
            'credit_notes.view' => 'View credit notes',
            'credit_notes.create' => 'Create credit notes',
            'credit_notes.edit' => 'Edit credit notes',
            'credit_notes.delete' => 'Delete credit notes',
            'credit_notes.issue' => 'Issue a draft credit note',
            'credit_notes.apply' => 'Apply a credit note to an invoice',
            'credit_notes.void' => 'Void a credit note',
        ],
        'maintenance' => [
            'maintenance.view' => 'View maintenance requests',
            'maintenance.create' => 'Create maintenance requests',
            'maintenance.edit' => 'Edit maintenance requests',
            'maintenance.delete' => 'Delete maintenance requests',
            'maintenance.assign' => 'Assign maintenance requests to staff or vendors',
            // FR-USR-04 — "sees only work assigned to them". Holding this means you OVERSEE the
            // module; lacking it means you see your own work. A permission rather than a role list
            // so the operator can invent a role without a deploy. Granted to every existing role,
            // so nothing that worked yesterday narrows today — only the new `technician` lacks it.
            'maintenance.view_all' => 'See every request, not only your own assignments (FR-USR-04)',
            'maintenance.change_status' => 'Move requests across status transitions',
        ],
        'tenant_sales' => [
            'tenant_sales.view' => 'View tenant sales declarations',
            'tenant_sales.create' => 'Create tenant sales declarations',
            'tenant_sales.edit' => 'Edit tenant sales declarations',
            'tenant_sales.delete' => 'Delete tenant sales declarations',
            'tenant_sales.lock' => 'Lock a declaration + generate percentage-rent charge',
            'tenant_sales.dispute' => 'Mark a declaration as disputed',
        ],
        'cam' => [
            'cam.view' => 'View CAM pools and allocations',
            'cam.create' => 'Create CAM expense pools',
            'cam.edit' => 'Edit CAM expense pools',
            'cam.delete' => 'Delete CAM expense pools',
            'cam.generate_allocations' => 'Generate per-lease allocations from a pool',
            'cam.bill_allocation' => 'Bill a CAM allocation (creates a true-up charge)',
            'cam.mark_reconciled' => 'Close a pool as fully reconciled',
        ],
        'ledger_accounts' => [
            'ledger_accounts.view' => 'View the chart of accounts',
            'ledger_accounts.create' => 'Create ledger accounts',
            'ledger_accounts.edit' => 'Edit ledger accounts',
            'ledger_accounts.delete' => 'Delete ledger accounts',
        ],
        'journal_entries' => [
            'journal_entries.view' => 'View journal entries',
            'journal_entries.create' => 'Create manual journal entries',
            'journal_entries.edit' => 'Edit draft journal entries',
            'journal_entries.delete' => 'Delete journal entries',
            'journal_entries.post' => 'Post a journal entry to the ledger',
            'journal_entries.void' => 'Void (reverse) a posted journal entry',
        ],
        'accounting_periods' => [
            'accounting_periods.view' => 'View fiscal years and accounting periods',
            'accounting_periods.manage' => 'Open / close accounting periods',
        ],
        'general_ledger' => [
            'general_ledger.view' => 'View the trial balance, general ledger, and financial statements',
        ],
        'owner_statements' => [
            'owner_statements.view' => 'View owner statements & runs (operator)',
            'owner_statements.generate' => 'Generate an owner statement run',
            'owner_statements.finalise' => 'Finalise an owner statement run (posts the distribution accrual)',
            'owner_statements.revise' => 'Revise a finalised owner statement run',
            'owner_statements.send' => 'Send a finalised statement to its owner',
            'owner_statements.view_own' => 'An owner views their own statements',
        ],
        'disbursements' => [
            'disbursements.view' => 'View owner disbursements',
            'disbursements.schedule' => 'Schedule an owner disbursement (payout)',
            'disbursements.approve' => 'Approve a scheduled disbursement',
            'disbursements.pay' => 'Mark a disbursement paid (clears Due to Owner)',
            'disbursements.cancel' => 'Cancel a not-yet-paid disbursement',
        ],
        'post_dated_cheques' => [
            'post_dated_cheques.view' => 'View the post-dated cheque register',
            'post_dated_cheques.create' => 'Lodge a post-dated cheque',
            'post_dated_cheques.edit' => 'Edit / deposit / bounce / cancel a post-dated cheque',
            'post_dated_cheques.delete' => 'Delete a post-dated cheque',
        ],
        'vendor_bills' => [
            'vendor_bills.view' => 'View vendor bills (accounts payable)',
            'vendor_bills.create' => 'Create vendor bills',
            'vendor_bills.edit' => 'Edit vendor bills',
            'vendor_bills.delete' => 'Delete vendor bills',
            'vendor_bills.approve' => 'Approve a vendor bill (makes it postable)',
            'vendor_bills.pay' => 'Record a payment against a vendor bill',
        ],
        'expenses' => [
            'expenses.view' => 'View direct / petty-cash expenses',
            'expenses.create' => 'Record direct expenses',
            'expenses.edit' => 'Edit direct expenses',
            'expenses.delete' => 'Delete direct expenses',
        ],
        'payrolls' => [
            'payrolls.view' => 'View payroll runs',
            'payrolls.create' => 'Create payroll runs',
            'payrolls.edit' => 'Edit payroll runs',
            'payrolls.delete' => 'Delete payroll runs',
            'payrolls.approve' => 'Approve a payroll run (makes it postable)',
        ],
        'employees' => [
            'employees.view' => 'View employees',
            'employees.create' => 'Add employees',
            'employees.edit' => 'Edit employees',
            'employees.delete' => 'Delete employees',
            'employees.grant_advance' => 'Grant an advance / loan to an employee',
            'employees.record_repayment' => 'Record a repayment of an employee advance',
        ],
        'custodies' => [
            'custodies.view' => 'View custodies (عهدة)',
            'custodies.create' => 'Grant a custody',
            'custodies.edit' => 'Edit a custody',
            'custodies.delete' => 'Delete a custody',
            'custodies.settle' => 'Settle a custody (record an expense / return)',
        ],
        // FR-CM-11 / FR-PROC-02 — the approval ladder. Tiers rather than named roles, so
        // authority composes with the existing RBAC instead of a parallel one. Which amount
        // needs which tier is DATA (approval_rules), not code.
        'approvals' => [
            'approvals.tier_1' => 'Approve low-value requests (supervisor)',
            'approvals.tier_2' => 'Approve mid-value requests (manager)',
            'approvals.tier_3' => 'Approve high-value requests (senior)',
            'approvals.manage_rules' => 'Configure the approval bands',
        ],
        'procurement' => [
            'procurement.view' => 'View procurement requests',
            'procurement.create' => 'Raise a procurement request (FR-PROC-01)',
            'procurement.edit' => 'Edit a draft procurement request',
            'procurement.delete' => 'Delete a procurement request',
            // Deciding and ordering are the same authority: FR-PROC-02 puts approval BEFORE order
            // placement, so whoever may place the order is exactly whoever may approve it.
            'procurement.decide' => 'Approve / reject / order / cancel a procurement request (FR-PROC-02)',
            'procurement.receive' => 'Receive goods against a procurement request (FR-PROC-04)',
        ],

        // FR-USR-02 — "restrict data import/upload functionality to Admin users only; all other
        // roles may export/download but not import". Import is not just another create: one bad CSV
        // rewrites hundreds of rows at once, which is why the FRD singles it out. Gating the
        // ImportActions on canCreate() made every manager and the whole leasing team an importer.
        'imports' => [
            'imports.execute' => 'Import/upload data from a CSV (FR-USR-02 — admins only)',
        ],

        'areas' => [
            'areas.view' => 'View facility zones (areas)',
            'areas.create' => 'Create facility zones',
            'areas.edit' => 'Edit facility zones + assign supervisors',
            'areas.delete' => 'Delete facility zones',
        ],
        // FR-REQ-15/16/17 — tenant violations register. `notify` is a dedicated
        // permission (not `.edit`): sending the tenant a formal notice is a distinct
        // authority from editing the record. Managers inherit it via the blanket
        // non-delete grant; viewer/owner get only `.view` (no notify).
        'violations' => [
            'violations.view' => 'View tenant violations',
            'violations.create' => 'Record tenant violations',
            'violations.edit' => 'Edit tenant violations',
            'violations.delete' => 'Delete tenant violations',
            'violations.notify' => 'Send a violation notice to the tenant (FR-REQ-17)',
        ],
        'preventive_maintenance' => [
            'preventive_maintenance.view' => 'View preventive-maintenance plans & work orders',
            'preventive_maintenance.create' => 'Create preventive-maintenance plans / work orders',
            'preventive_maintenance.edit' => 'Edit preventive-maintenance plans / work orders',
            'preventive_maintenance.delete' => 'Delete preventive-maintenance plans / work orders',
            'preventive_maintenance.complete' => 'Complete a work order (tick checklist items, mark done)',
            // FR-CM-12/13. Deliberately NOT granted to operations: recording what you found is
            // engineering, but ruling that a TENANT is financially responsible is a commercial
            // claim. Manager inherits it via the blanket non-delete grant.
            'preventive_maintenance.view_all' => 'See every work order, not only your own assignments (FR-USR-04)',
            'preventive_maintenance.attribute_fault' => 'Rule on who caused a failure and who bears the cost (FR-CM-12/13)',
        ],
        'deposit_transactions' => [
            'deposit_transactions.view' => 'View security-deposit transactions',
            'deposit_transactions.create' => 'Record security-deposit transactions',
            'deposit_transactions.edit' => 'Edit security-deposit transactions',
            'deposit_transactions.delete' => 'Delete security-deposit transactions',
        ],
        'utility_meters' => [
            'utility_meters.view' => 'View utility meters',
            'utility_meters.create' => 'Create utility meters',
            'utility_meters.edit' => 'Edit utility meters',
            'utility_meters.delete' => 'Delete utility meters',
        ],
        'vendors' => [
            'vendors.view' => 'View vendors',
            'vendors.create' => 'Create vendors',
            'vendors.edit' => 'Edit vendors',
            'vendors.delete' => 'Delete vendors',
        ],
        'departments' => [
            'departments.view' => 'View departments',
            'departments.create' => 'Create departments',
            'departments.edit' => 'Edit departments',
            'departments.delete' => 'Delete departments',
        ],
        'marketing' => [
            'marketing.view' => 'View marketing budgets and spend',
            'marketing.create' => 'Create marketing budgets and spend',
            'marketing.edit' => 'Edit marketing budgets and spend',
            'marketing.delete' => 'Delete marketing budgets and spend',
        ],
        'owner_requests' => [
            'owner_requests.view' => 'View owner requests',
            'owner_requests.create' => 'Create owner requests',
            'owner_requests.edit' => 'Respond to / edit owner requests',
            'owner_requests.delete' => 'Delete owner requests',
        ],
        'notes' => [
            'notes.view' => 'View communications log entries',
            'notes.create' => 'Log communications',
            'notes.edit' => 'Edit communications log entries',
            'notes.delete' => 'Delete communications log entries',
        ],
        'users' => [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            'users.delete' => 'Delete users',
        ],
        'roles' => [
            'roles.view' => 'View roles',
            'roles.create' => 'Create custom roles',
            'roles.edit' => 'Edit roles + their permissions',
            'roles.delete' => 'Delete custom roles',
        ],
        'reports' => [
            'reports.view' => 'View reports',
            'reports.download' => 'Download monthly close PDF',
        ],
        'activity_log' => [
            'activity_log.view' => 'View the activity log',
        ],
        'inventory' => [
            'inventory.view' => 'View warehouses, items & stock movements',
            'inventory.create' => 'Create warehouses / items & receive stock',
            'inventory.edit' => 'Edit warehouses / items & record stock movements',
            'inventory.delete' => 'Delete inventory records',
        ],
        'fixed_assets' => [
            'fixed_assets.view' => 'View the fixed-asset register & depreciation',
            'fixed_assets.create' => 'Register fixed assets',
            'fixed_assets.edit' => 'Edit fixed assets, dispose & post depreciation',
            'fixed_assets.delete' => 'Delete fixed-asset records',
        ],
        'announcements' => [
            'announcements.view' => 'View sent announcements',
            'announcements.create' => 'Compose & broadcast announcements to tenants',
        ],
        'settings' => [
            'settings.view' => 'View settings',
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
        // approvals.tier_3 is withheld deliberately: with the blanket grant a manager would
        // hold every tier, and a ladder whose top rung everyone can reach isn't a ladder.
        // Large spend escalates — which is the whole point of FR-CM-11. Configuring the
        // bands themselves is likewise a policy act, not a management one.
        $managerPerms = collect($all)
            ->reject(fn ($p) => str_ends_with($p, '.delete'))
            ->reject(fn ($p) => in_array($p, ['settings.manage', 'roles.create', 'roles.edit', 'roles.delete']))
            ->reject(fn ($p) => in_array($p, ['approvals.tier_3', 'approvals.manage_rules']))
            // FR-USR-02: import is an ADMIN right. A manager may create records one at a time;
            // rewriting hundreds from a CSV is the thing the FRD reserves for admins.
            ->reject(fn ($p) => $p === 'imports.execute')
            ->values()
            ->all();
        Role::findByName('manager', 'web')->syncPermissions($managerPerms);

        // FR-USR-01/02 — mall_admin is a manager PLUS the import right, scoped to their properties
        // by the same AssignedAssets mechanism as every other role. `imports.execute` is withheld
        // from $managerPerms above (it is not a `.delete`, so the blanket grant would otherwise
        // hand it to every manager and defeat FR-USR-02's whole point).
        Role::findByName('mall_admin', 'web')->syncPermissions(
            collect($managerPerms)->push('imports.execute')->unique()->values()->all()
        );

        // viewer: every .view + reports.download.
        $viewerPerms = collect($all)
            // `.view_all` as well as `.view` (FR-USR-04): these are OVERSIGHT roles — an auditor
            // or an owner who saw "only work assigned to them" would see an empty screen, because
            // nobody assigns work orders to auditors. AssignmentScope restricts whoever lacks
            // `.view_all`, so omitting it here silently narrows them to nothing.
            ->filter(fn ($p) => str_ends_with($p, '.view') || str_ends_with($p, '.view_all') || $p === 'reports.download')
            ->values()
            ->all();
        Role::findByName('viewer', 'web')->syncPermissions($viewerPerms);

        // owner: Jawad owners SEE EVERYTHING read-only (every module / department),
        // but only for the properties they OWN (scoped via User::accessibleAssets),
        // plus the right to raise + track owner requests.
        $ownerPerms = collect($all)
            // `.view_all` as well as `.view` (FR-USR-04): these are OVERSIGHT roles — an auditor
            // or an owner who saw "only work assigned to them" would see an empty screen, because
            // nobody assigns work orders to auditors. AssignmentScope restricts whoever lacks
            // `.view_all`, so omitting it here silently narrows them to nothing.
            ->filter(fn ($p) => str_ends_with($p, '.view') || str_ends_with($p, '.view_all') || $p === 'reports.download')
            ->push('owner_requests.create')
            // The owner deliverable: see their OWN statements (`.view_own` isn't caught by the
            // `.view` filter above). They already get `owner_statements.view`/`disbursements.view`
            // read-only via the filter; view_own is what the owner-facing page gates on.
            ->push('owner_statements.view_own')
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
            // Dispatch IS oversight — you cannot assign work you cannot see (FR-USR-04).
            'maintenance.view_all', 'preventive_maintenance.view_all',
            'maintenance.assign', 'maintenance.change_status',
            'preventive_maintenance.view', 'preventive_maintenance.create',
            'preventive_maintenance.edit', 'preventive_maintenance.complete',
            // Facility zones — operations owns the mall's operational layout.
            'areas.view', 'areas.create', 'areas.edit',
            // Tenant violations (FR-REQ-15/16/17) — operations records + notices them.
            'violations.view', 'violations.create', 'violations.edit', 'violations.notify',
            'vendors.view', 'vendors.create', 'vendors.edit',
            'utility_meters.view', 'utility_meters.create', 'utility_meters.edit',
            'inventory.view', 'inventory.create', 'inventory.edit',
            // Procurement: operations raises the need and receives the goods. Deciding it is
            // withheld — manager inherits that via the blanket non-delete grant.
            'procurement.view', 'procurement.create', 'procurement.edit', 'procurement.receive',
            // The bottom rung: a supervisor signs off routine, low-value part draws.
            'approvals.tier_1',
            'notes.view', 'notes.create',
        ]);

        // FR-USR-04 — the technician: does the work, sees only their own.
        //
        // Note what is ABSENT: `maintenance.view_all` and `preventive_maintenance.view_all`. That
        // absence is the entire feature — AssignmentScope restricts anyone lacking them. They can
        // complete the job they are holding and nothing else; assigning work is a coordinator's
        // job, and `.assign` is withheld for the same reason.
        Role::findByName('technician', 'web')->syncPermissions([
            'maintenance.view', 'maintenance.change_status',
            'preventive_maintenance.view', 'preventive_maintenance.complete',
            'notes.view', 'notes.create',
        ]);

        // FR-USR — the coordinator: the maintenance-queue supervisor. Sees the whole board
        // (`*.view_all`, so AssignmentScope does NOT restrict them) and assigns technicians
        // (`maintenance.assign`) — assignment IS oversight, you cannot hand out work you cannot
        // see. Narrower than `operations`: no meters, inventory or procurement.
        Role::findByName('coordinator', 'web')->syncPermissions([
            'maintenance.view', 'maintenance.create', 'maintenance.edit',
            'maintenance.view_all', 'maintenance.assign', 'maintenance.change_status',
            'preventive_maintenance.view', 'preventive_maintenance.view_all',
            'preventive_maintenance.create', 'preventive_maintenance.edit', 'preventive_maintenance.complete',
            // Facility zones — the coordinator routes work by zone (FR routing, later slice).
            'areas.view', 'areas.create', 'areas.edit',
            // Tenant violations (FR-REQ-15/16/17) — the coordinator records + notices them.
            'violations.view', 'violations.create', 'violations.edit', 'violations.notify',
            'vendors.view',
            'notes.view', 'notes.create',
        ]);

        // FR-USR — customer service (front desk / intake): logs requests and fields any tenant's
        // call, so it sees EVERY request (`maintenance.view_all`) but has NO work authority — no
        // assign, no change_status, no complete, no edit. It captures the request and hands it to
        // a coordinator. `tenants.view` to identify the caller.
        Role::findByName('customer_service', 'web')->syncPermissions([
            'maintenance.view', 'maintenance.create', 'maintenance.view_all',
            'tenants.view',
            'notes.view', 'notes.create',
        ]);

        // FR-USR-03 — the external vendor/contractor: VIEW-ONLY on the maintenance work it does.
        // view_all so it can see the board of its assigned malls (the finer "only my own jobs"
        // filter needs a vendor-user→company link that doesn't exist yet). NO create/edit/delete/
        // change_status, and NO tenants/leases/financials/HR/GL — it must not read another party's
        // commercial data.
        //
        // The FRD's "specific exception — CSV upload" is DEFERRED, deliberately NOT `imports.execute`:
        // that permission is ADMIN data import (tenants/leases/units — surfaces a vendor can't even
        // reach, having no view on them), and granting an external party the blanket admin-import
        // right widens a tightly-held gate (ImportIsAdminOnlyTest / FR-USR-02) for no function. A
        // real vendor CSV upload needs its OWN vendor-facing import surface + permission — a
        // follow-up, not the admin import right.
        Role::findByName('vendor', 'web')->syncPermissions([
            'maintenance.view', 'maintenance.view_all',
            'preventive_maintenance.view', 'preventive_maintenance.view_all',
            'notes.view',
        ]);

        // accounting: Invoices, Payments, Credit Notes, CAM, Reports.
        Role::findByName('accounting', 'web')->syncPermissions([
            'invoices.view', 'invoices.create', 'invoices.edit', 'invoices.void',
            'invoices.run_monthly_billing', 'invoices.submit_to_eta', 'invoices.send_whatsapp',
            'payments.view', 'payments.create', 'payments.edit', 'payments.void',
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
            'fixed_assets.view', 'fixed_assets.create', 'fixed_assets.edit',
            'employees.view', 'employees.grant_advance', 'employees.record_repayment',
            'custodies.view', 'custodies.create', 'custodies.edit', 'custodies.settle',
            // Owner statements + disbursements (module 27) — accounting runs the operator side.
            'owner_statements.view', 'owner_statements.generate', 'owner_statements.finalise',
            'owner_statements.revise', 'owner_statements.send',
            'disbursements.view', 'disbursements.schedule', 'disbursements.approve',
            'disbursements.pay', 'disbursements.cancel',
            'post_dated_cheques.view', 'post_dated_cheques.create', 'post_dated_cheques.edit',
            'reports.view', 'reports.download',
        ]);

        // marketing: Marketing Budgets + spend, plus tenant announcements.
        Role::findByName('marketing', 'web')->syncPermissions([
            'marketing.view', 'marketing.create', 'marketing.edit',
            'announcements.view', 'announcements.create',
        ]);

        // hr: Users, Roles, Departments, Employees.
        Role::findByName('hr', 'web')->syncPermissions([
            'users.view', 'users.create', 'users.edit',
            'roles.view',
            'departments.view',
            'employees.view', 'employees.create', 'employees.edit',
            'employees.grant_advance', 'employees.record_repayment',
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
