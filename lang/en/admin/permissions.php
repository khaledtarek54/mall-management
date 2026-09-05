<?php

/**
 * What each permission and each shipped role MEANS — the English half.
 *
 * **GENERATED from `RolesPermissionsSeeder::PERMISSIONS` and `::ROLES`, then written out as DATA.**
 * A first version derived it at runtime by `use`-ing the seeder from inside this file. That reads as
 * "derive, never re-list" and is the wrong home: a lang file is DATA, read by tooling that does not
 * boot the application, so requiring an app class here is a FATAL rather than a missing translation
 * — it broke `TranslationKeyConformanceTest` outright, and `TranslationKeyConformanceTest` test B
 * then failed the other way when the file was simply deleted, because the Arabic half was orphaned.
 *
 * Regenerate after adding a permission. `App\Support\PermissionVocabulary` floors on the registry
 * either way, so a key added to the seeder renders its registry sentence rather than a raw key while
 * this file catches up — and `lang/ar/admin/permissions.php` is the Arabic half, written by hand
 * because there is nothing to derive it from.
 *
 * The keys are NESTED because `__()` reads a dot as a path: a flat `charge_codes.view` entry is
 * looked up as ['charge_codes']['view'], never found, and the operator reads the literal key.
 */
return [
    'permissions' => [
        'assets' => [
            'view' => 'View properties',
            'create' => 'Create properties',
            'edit' => 'Edit properties',
        ],
        'units' => [
            'view' => 'View units',
            'create' => 'Create units',
            'edit' => 'Edit units',
        ],
        'tenants' => [
            'view' => 'View tenants',
            'create' => 'Create tenants',
            'edit' => 'Edit tenants',
        ],
        'leases' => [
            'view' => 'View leases',
            'create' => 'Create leases',
            'edit' => 'Edit leases',
            'terminate' => 'Terminate leases',
            'renew' => 'Renew leases',
            'generate_invoice' => 'Generate invoices from a lease',
        ],
        'invoices' => [
            'view' => 'View invoices',
            'create' => 'Create invoices',
            'edit' => 'Edit invoices',
            'issue' => 'Issue a draft invoice',
            'void' => 'Void (cancel) an issued invoice',
            'run_monthly_billing' => 'Run the monthly billing and owner-assessment runs',
        ],
        'payments' => [
            'view' => 'View payments',
            'create' => 'Record payments',
            'edit' => 'Edit payments',
            'void' => 'Void / refund a captured payment',
        ],
        'credit_notes' => [
            'view' => 'View credit notes',
            'create' => 'Create credit notes',
            'edit' => 'Edit credit notes',
            'issue' => 'Issue a draft credit note',
            'apply' => 'Apply a credit note to an invoice',
            'void' => 'Void a credit note',
        ],
        'requests' => [
            'view' => 'View tenant requests',
            'create' => 'Create tenant requests',
            'edit' => 'Edit tenant requests',
            'assign' => 'Assign tenant requests to staff or vendors',
            'view_all' => 'See every request, not only your own assignments (FR-USR-04)',
            'change_status' => 'Move requests across status transitions',
        ],
        'tenant_sales' => [
            'view' => 'View tenant sales declarations',
            'create' => 'Create tenant sales declarations',
            'edit' => 'Edit tenant sales declarations',
            'lock' => 'Lock a declaration + generate percentage-rent charge',
            'dispute' => 'Mark a declaration as disputed',
        ],
        'cam' => [
            'view' => 'View CAM pools and allocations',
            'create' => 'Create CAM expense pools',
            'edit' => 'Edit CAM expense pools',
            'generate_allocations' => 'Generate per-lease allocations from a pool',
            'bill_allocation' => 'Bill a CAM allocation (creates a true-up charge)',
            'mark_reconciled' => 'Close a pool as fully reconciled',
        ],
        'ledger_accounts' => [
            'view' => 'View the chart of accounts',
            'create' => 'Create ledger accounts',
            'edit' => 'Edit ledger accounts',
        ],
        'charge_codes' => [
            'view' => 'View the charge-code catalogue',
            'create' => 'Add a charge code',
            'edit' => 'Edit a charge code’s label or posting account',
        ],
        'rent_indices' => [
            'view' => 'View the published rent-index register',
            'create' => 'Record a published index figure',
            'edit' => 'Correct a published index figure',
        ],
        'retail_categories' => [
            'view' => 'View the merchandising mix',
            'create' => 'Add a retail category',
            'edit' => 'Edit a retail category',
        ],
        'document_templates' => [
            'view' => 'View the standing wording on tenant-facing documents',
            'create' => 'Add a document wording block',
            'edit' => 'Edit a document wording block',
        ],
        'payroll_rates' => [
            'view' => 'View the dated ladder of statutory payroll rates',
            'create' => 'Add a payroll-rate rung (a new year\'s decree)',
            'edit' => 'Correct a payroll-rate rung',
        ],
        'recurring_expenses' => [
            'view' => 'View the recurring cost schedules',
            'create' => 'Add a recurring cost schedule',
            'edit' => 'Change or switch off a recurring cost schedule',
        ],
        'custom_fields' => [
            'view' => 'View the fields your organisation added to a record type',
            'create' => 'Add a field to a record type',
            'edit' => 'Rename or retire a custom field',
        ],
        'violation_categories' => [
            'view' => 'View the house rules and their standard fines',
            'create' => 'Add a house rule',
            'edit' => 'Edit a house rule or its standard fine',
        ],
        'vendor_document_types' => [
            'view' => 'View the supplier document types',
            'create' => 'Add a supplier document type',
            'edit' => 'Edit a document type, including whether a lapse blocks site work',
        ],
        'tenant_request_subcategories' => [
            'view' => 'View what a tenant may report',
            'create' => 'Add a reportable problem',
            'edit' => 'Edit a reportable problem, including the trade it routes to',
        ],
        'expense_categories' => [
            'view' => 'View the expense-category catalogue',
            'create' => 'Add an expense category',
            'edit' => 'Edit an expense category, including the account its costs book to',
        ],
        'payment_methods' => [
            'view' => 'View the payment-rail catalogue',
            'create' => 'Add a payment rail',
            'edit' => 'Edit a payment rail, including the account its money lands in',
        ],
        'holidays' => [
            'view' => 'View the working-calendar holidays',
            'create' => 'Add a holiday or a short day',
            'edit' => 'Edit a holiday',
        ],
        'failure_codes' => [
            'view' => 'View the failure-code library',
            'create' => 'Add a failure code',
            'edit' => 'Edit a failure code',
        ],
        'trades' => [
            'view' => 'View the trade register',
            'create' => 'Add a trade',
            'edit' => 'Edit a trade, including its hourly rate',
        ],
        'work_permits' => [
            'view' => 'View the permit-to-work register',
            'create' => 'Draft a permit to work',
            'edit' => 'Edit a draft permit',
            'issue' => 'Issue, close or cancel a permit to work',
        ],
        'tax_codes' => [
            'view' => 'View the tax catalogue (rates and the dates they came into force)',
            'create' => 'Add a tax code',
            'edit' => 'Edit a tax code, and add or change a rate on its ladder',
            'override' => 'Type a tax rate by hand on an invoice or credit-note line, departing from the catalogue',
        ],
        'utility_tariffs' => [
            'view' => 'View the utility price list (rates and the dates they came into force)',
            'create' => 'Add a utility tariff',
            'edit' => 'Edit a tariff, and add or change a price on its ladder',
        ],
        'account_mappings' => [
            'view' => 'View the posting map (which account each role posts to)',
            'create' => 'Add a posting-map row or a per-property override',
            'edit' => 'Re-point a posting role at a different account',
        ],
        'journal_entries' => [
            'view' => 'View journal entries',
            'create' => 'Create manual journal entries',
            'edit' => 'Edit draft journal entries',
            'post' => 'Post a journal entry to the ledger',
            'void' => 'Void (reverse) a posted journal entry',
        ],
        'accounting_periods' => [
            'view' => 'View fiscal years and accounting periods',
            'manage' => 'Open / close accounting periods',
        ],
        'general_ledger' => [
            'view' => 'View the trial balance, general ledger, and financial statements',
        ],
        'owner_statements' => [
            'view' => 'View owner statements & runs (operator)',
            'generate' => 'Generate an owner statement run',
            'finalise' => 'Finalise an owner statement run (posts the distribution accrual)',
            'revise' => 'Revise a finalised owner statement run',
            'send' => 'Send a finalised statement to its owner',
            'view_own' => 'An owner views their own statements',
        ],
        'disbursements' => [
            'view' => 'View owner disbursements',
            'schedule' => 'Schedule an owner disbursement (payout)',
            'approve' => 'Approve a scheduled disbursement',
            'pay' => 'Mark a disbursement paid (clears Due to Owner)',
            'cancel' => 'Cancel a not-yet-paid disbursement',
        ],
        'post_dated_cheques' => [
            'view' => 'View the post-dated cheque register',
            'create' => 'Lodge a post-dated cheque',
            'edit' => 'Edit / deposit / bounce / cancel a post-dated cheque',
        ],
        'bank_accounts' => [
            'view' => 'View the operator\'s bank accounts',
            'create' => 'Register a bank account',
            'edit' => 'Edit a bank account',
        ],
        'vendor_bills' => [
            'view' => 'View vendor bills (accounts payable)',
            'create' => 'Create vendor bills',
            'edit' => 'Edit vendor bills',
            'approve' => 'Approve a vendor bill (makes it postable)',
            'pay' => 'Record a payment against a vendor bill',
            'void_payment' => 'Void a recorded vendor payment (reverses it on the ledger)',
        ],
        'expenses' => [
            'view' => 'View direct / petty-cash expenses',
            'create' => 'Record direct expenses',
            'edit' => 'Edit direct expenses',
        ],
        'payrolls' => [
            'view' => 'View payroll runs',
            'create' => 'Create payroll runs',
            'edit' => 'Edit payroll runs',
            'approve' => 'Approve a payroll run (makes it postable)',
        ],
        'employees' => [
            'view' => 'View employees',
            'create' => 'Add employees',
            'edit' => 'Edit employees',
            'grant_advance' => 'Grant an advance / loan to an employee',
            'record_repayment' => 'Record a repayment of an employee advance',
        ],
        'custodies' => [
            'view' => 'View custodies (عهدة)',
            'create' => 'Grant a custody',
            'edit' => 'Edit a custody',
            'settle' => 'Settle a custody (record an expense / return)',
        ],
        'approvals' => [
            'tier_1' => 'Approve low-value requests (supervisor)',
            'tier_2' => 'Approve mid-value requests (manager)',
            'tier_3' => 'Approve high-value requests (senior)',
            'manage_rules' => 'Configure the approval bands',
        ],
        'procurement' => [
            'view' => 'View procurement requests',
            'create' => 'Raise a procurement request (FR-PROC-01)',
            'edit' => 'Edit a draft procurement request',
            'decide' => 'Approve / reject / order / cancel a procurement request (FR-PROC-02)',
            'receive' => 'Receive goods against a procurement request (FR-PROC-04)',
        ],
        'imports' => [
            'execute' => 'Import/upload data from a CSV (FR-USR-02 — admins only)',
        ],
        'rentable_items' => [
            'view' => 'View parking bays, storage and signage',
            'create' => 'Add a parking bay, store or signage face',
            'edit' => 'Edit a rentable item, or take it out of service',
        ],
        'unit_ownerships' => [
            'view' => 'View the unit-ownership register (unit buyers)',
            'create' => 'Record a unit sale',
            'edit' => 'Edit a unit ownership',
        ],
        'areas' => [
            'view' => 'View facility zones (areas)',
            'create' => 'Create facility zones',
            'edit' => 'Edit facility zones + assign supervisors',
        ],
        'violations' => [
            'view' => 'View tenant violations',
            'create' => 'Record tenant violations',
            'edit' => 'Edit tenant violations',
            'notify' => 'Send a violation notice to the tenant (FR-REQ-17)',
        ],
        'facility' => [
            'view' => 'View service plans & work orders',
            'create' => 'Create service plans / work orders',
            'edit' => 'Edit service plans / work orders',
            'complete' => 'Complete a work order (tick checklist items, mark done)',
            'view_all' => 'See every work order, not only your own assignments (FR-USR-04)',
            'attribute_fault' => 'Rule on who caused a failure and who bears the cost (FR-CM-12/13)',
        ],
        'deposit_transactions' => [
            'view' => 'View security-deposit transactions',
            'create' => 'Record security-deposit transactions',
            'edit' => 'Edit security-deposit transactions',
        ],
        'utility_meters' => [
            'view' => 'View utility meters',
            'create' => 'Create utility meters',
            'edit' => 'Edit utility meters',
        ],
        'vendors' => [
            'view' => 'View vendors',
            'create' => 'Create vendors',
            'edit' => 'Edit vendors',
        ],
        'departments' => [
            'view' => 'View departments',
            'create' => 'Create departments',
            'edit' => 'Edit departments',
        ],
        'marketing' => [
            'view' => 'View marketing budgets and spend',
            'create' => 'Create marketing budgets and spend',
            'edit' => 'Edit marketing budgets and spend',
        ],
        'owner_requests' => [
            'view' => 'View owner requests',
            'create' => 'Create owner requests',
            'edit' => 'Respond to / edit owner requests',
        ],
        'notes' => [
            'view' => 'View communications log entries',
            'create' => 'Log communications',
            'edit' => 'Edit communications log entries',
        ],
        'users' => [
            'view' => 'View users',
            'create' => 'Create users',
            'edit' => 'Edit users',
        ],
        'roles' => [
            'view' => 'View roles',
            'create' => 'Create custom roles',
            'edit' => 'Edit roles + their permissions',
        ],
        'reports' => [
            'view' => 'View reports',
            'download' => 'Download monthly close PDF',
        ],
        'budget' => [
            'manage' => 'Set the annual budget',
        ],
        'activity_log' => [
            'view' => 'View the activity log',
        ],
        'inventory' => [
            'view' => 'View warehouses, items & stock movements',
            'create' => 'Create warehouses / items & receive stock',
            'edit' => 'Edit warehouses / items & record stock movements',
        ],
        'fixed_assets' => [
            'view' => 'View the fixed-asset register & depreciation',
            'create' => 'Register fixed assets',
            'edit' => 'Edit fixed assets, dispose & post depreciation',
        ],
        'announcements' => [
            'view' => 'View announcements & their read receipts',
            'create' => 'Compose announcements to tenants',
            'edit' => 'Edit a draft or scheduled announcement (sent ones are immutable)',
            'send' => 'Broadcast an announcement to a property\'s tenants',
        ],
        'marketing_posts' => [
            'view' => 'View marketing posts (offers, events, mall news)',
            'create' => 'Compose a marketing post',
            'edit' => 'Edit a marketing post, feature it, or archive it',
            'approve' => 'Approve or reject a retailer\'s submission, and publish to the mall app',
        ],
        'settings' => [
            'view' => 'View settings',
            'manage' => 'Edit system settings (billing rules, SLA, integrations)',
        ],
    ],
    'role_descriptions' => [
        'super_admin' => 'Full access — create, edit, delete, view everything plus settings + role management.',
        'manager' => 'General manager — create + edit on every module, no delete, no settings.',
        'viewer' => 'Read-only access for stakeholders + auditors.',
        'owner' => 'Jawad owner — read-only oversight of owned properties in the admin app + owner requests.',
        'leasing' => 'Leasing department — properties, units, tenants, leases, sales.',
        'operations' => 'Operations department — maintenance, vendor dispatch, meters.',
        'accounting' => 'Accounting department — invoices, payments, credit notes, CAM, reports.',
        'marketing' => 'Marketing department — the marketing budget + spend.',
        'hr' => 'HR department — staff accounts, roles, departments, and preparing the monthly payroll run.',
        'technician' => 'In-house technician — sees only the requests + work orders assigned to them.',
        'coordinator' => 'Maintenance coordinator — sees the whole request/work-order board and assigns technicians.',
        'customer_service' => 'Customer service — logs and tracks tenant requests at the front desk; no work authority.',
        'vendor' => 'External vendor — view-only on maintenance work, plus CSV upload; no other edits.',
        'mall_admin' => 'Mall admin — a manager for their assigned properties, plus the right to import data.',
    ],
];
