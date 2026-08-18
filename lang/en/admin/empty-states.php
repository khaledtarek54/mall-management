<?php

return [
    'empty' => [
        'asset_owners' => [
            'heading' => 'No owners recorded',
            'description' => 'Owner statements apportion this property\'s net by ownership share, so a property with no owners produces no statements. Attach the owner and set their percentage.',
        ],
        'bank_statements' => ['heading' => 'No statements yet', 'description' => 'Add the period you want to reconcile, then import the bank\'s CSV export into it.'],
        'bank_statement_lines' => ['heading' => 'Nothing imported yet', 'description' => 'Use Import to upload the bank\'s CSV export for this period.'],
        'bank_accounts' => ['heading' => 'No bank accounts registered', 'description' => 'Add the accounts this property banks with. Nothing posts through them yet — they are what bank statements will reconcile against.'],
        'approval_rules' => ['heading' => 'No approval bands yet', 'description' => 'With no bands, requests for this area need no approval. Add one to turn the gate on.'],
        'marketing_posts' => [
            'heading' => 'Nothing on the feed yet',
            'description' => 'Offers, events and mall news shown to shoppers in the app. Compose one, or wait for a retailer to submit theirs for review.',
        ],
        'ledger_accounts' => [
            'heading' => 'No accounts yet',
            'description' => 'The chart of accounts is empty. Seed the standard chart or add accounts.',
        ],
        'journal_entries' => [
            'heading' => 'No journal entries yet',
            'description' => 'Create a manual entry, or entries will appear here as transactions post.',
        ],
        'assets' => [
            'heading' => 'No properties yet',
            'description' => 'Add your first mall, plaza, or office building to organize everything underneath it.',
            'cta' => 'Add your first property',
        ],
        'units' => [
            'heading' => 'No units yet',
            'description' => 'Shops, food-court stalls, kiosks. Each unit belongs to a property and can be leased.',
            'cta' => 'Add a unit',
        ],
        'tenants' => [
            'heading' => 'No tenants yet',
            'description' => 'Add the brands renting your units. They\'ll get their own portal login to see invoices and submit maintenance.',
            'cta' => 'Add a tenant',
        ],
        'leases' => [
            'heading' => 'No leases yet',
            'description' => 'A lease connects a tenant to a unit with rent terms. Every invoice flows from here.',
            'cta' => 'Sign a lease',
        ],
        'invoices' => [
            'heading' => 'No invoices yet',
            'description' => 'Run Monthly Billing on the toolbar to generate this month\'s invoices for every active lease.',
            'cta' => 'Create an invoice',
        ],
        'payments' => [
            'heading' => 'No payments recorded',
            'description' => 'Record incoming rent payments and allocate them to invoices.',
            'cta' => 'Record a payment',
        ],
        'credit_notes' => [
            'heading' => 'No credit notes yet',
            'description' => 'Issue credit notes to settle disputes, refund returns, or adjust an invoice.',
            'cta' => 'Issue a credit note',
        ],
        'credit_note_applications' => [
            'heading' => 'Not applied to any invoice yet',
            'description' => 'When this credit is applied to an invoice, each application is listed here — with a per-row option to un-apply it.',
        ],
        'vendor_bills' => [
            'heading' => 'No vendor bills yet',
            'description' => 'Record a vendor bill to track and pay it through accounting.',
        ],
        'expenses' => [
            'heading' => 'No expenses yet',
            'description' => 'Record a direct or petty-cash expense to post it to the ledger.',
        ],
        'deposit_transactions' => [
            'heading' => 'No deposit transactions yet',
            'description' => 'Record a deposit receipt, refund, or forfeit to post it to the ledger.',
        ],
        'payrolls' => [
            'heading' => 'No payroll runs yet',
            'description' => 'Record a monthly payroll run to post it to the ledger.',
        ],
        'vendors' => [
            'heading' => 'No vendors yet',
            'description' => 'Add contractors and suppliers to route maintenance requests externally and track service contracts.',
            'cta' => 'Add a vendor',
        ],
        'requests' => [
            'heading' => 'No requests',
            'description' => 'When a tenant reports an issue from the portal — or you log one yourself — it lands here for triage.',
            'cta' => 'Log a request',
        ],
        'tenant_sales' => [
            'heading' => 'No sales declarations yet',
            'description' => 'Tenants on percentage-rent leases declare their monthly sales here. Locking a declaration generates the percentage-rent charge.',
        ],
        'cam' => [
            'heading' => 'No CAM expense pools yet',
            'description' => 'Create an annual common-area expense pool, then generate per-lease allocations pro-rata by leased square meters.',
            'cta' => 'Create a CAM pool',
        ],
        'cam_allocations' => [
            'heading' => 'No allocations yet',
            'description' => 'Generate per-lease allocations from the pool actions (pro-rata by leased area), then bill each true-up.',
        ],
        'utility_meters' => [
            'heading' => 'No utility meters yet',
            'description' => 'Add electric, water, and gas meters per property. Track consumption monthly to spot anomalies.',
            'cta' => 'Add a meter',
        ],
        'meter_readings' => [
            'heading' => 'No readings yet',
            'description' => 'Log a monthly reading to start tracking consumption on this meter.',
        ],
        'portal_cam_allocations' => [
            'heading' => 'No common-area charges yet',
            'description' => 'When the mall closes its annual CAM reconciliation, your share will appear here with a full breakdown.',
        ],
        'accounting_periods' => [
            'heading' => 'No accounting periods yet',
            'description' => 'Periods open automatically per fiscal year. Create the fiscal year in Settings if this is empty.',
        ],
        'areas' => [
            'heading' => 'No zones yet',
            'description' => 'Split the property into zones — floors, wings, the car park — so requests and work orders route to the right team.',
            'cta' => 'Add a zone',
        ],
        'disbursements' => [
            'heading' => 'No owner payouts yet',
            'description' => 'Payouts appear here once an owner statement is finalised and scheduled for payment.',
        ],
        'equipment' => [
            'heading' => 'No equipment yet',
            'description' => 'Chillers, lifts, generators, pumps. Register them to run preventive maintenance plans against them.',
            'cta' => 'Add equipment',
        ],
        'service_plans' => [
            'heading' => 'No preventive plans yet',
            'description' => 'A plan raises work orders on a schedule — quarterly chiller service, monthly lift inspection — so nothing waits for a breakdown.',
            'cta' => 'Create a plan',
        ],
        'facility_work_orders' => [
            'heading' => 'No work orders yet',
            'description' => 'Corrective and preventive jobs for the facility team. They arrive from tenant requests, plans, or are raised directly.',
            'cta' => 'Raise a work order',
        ],
        'marketing_budgets' => [
            'heading' => 'No marketing budgets yet',
            'description' => 'A budget accrues the marketing levy collected from tenants and tracks spend against it, per year.',
        ],
        'owner_requests' => [
            'heading' => 'No owner requests yet',
            'description' => 'Questions and instructions raised by the property owner. They appear here for the operator to answer.',
        ],
        'owner_statement_runs' => [
            'heading' => 'No statement runs yet',
            'description' => 'Generate a monthly run to produce each owner\'s statement of income, expenses and net distributable.',
        ],
        'post_dated_cheques' => [
            'heading' => 'No cheques lodged yet',
            'description' => 'Tenants often hand over a year of monthly cheques up front. Lodge them here so maturity is tracked and nothing is banked late.',
            'cta' => 'Lodge a cheque',
        ],
        'purchase_requests' => [
            'heading' => 'No purchase requests yet',
            'description' => 'Raise a request to buy goods or services. It routes for approval, then becomes a purchase order and a vendor bill.',
            'cta' => 'Raise a request',
        ],
        'sla_policies' => [
            'heading' => 'No SLA policies yet',
            'description' => 'A policy sets the response and resolution clock per request type and priority. Breaches alert the responsible team.',
            'cta' => 'Add a policy',
        ],
        'stock_movements' => [
            'heading' => 'No stock movements yet',
            'description' => 'Every receipt, consumption, adjustment and transfer is recorded here — the audit trail behind each item\'s quantity on hand.',
        ],
        'users' => [
            'heading' => 'No users yet',
            'description' => 'Staff logins for the admin panel. Each user gets a role, which decides what they can see and do.',
            'cta' => 'Add a user',
        ],
        'violations' => [
            'heading' => 'No violations recorded',
            'description' => 'Log a breach of the tenant handbook — obstruction, signage, waste, trading hours — with a photo, and bill the fine if one applies.',
            'cta' => 'Record a violation',
        ],
        'portal_invoices' => [
            'heading' => 'No invoices yet',
            'description' => 'Your rent and service-charge invoices will appear here as the mall issues them.',
        ],
        'portal_credit_notes' => [
            'heading' => 'No credit notes yet',
            'description' => 'When the mall credits something back — a corrected charge, an overbilled service charge — the credit note appears here and shows how much of it is still yours to use.',
        ],
        'portal_payments' => [
            'heading' => 'No payments yet',
            'description' => 'Payments you make against your invoices are listed here with their receipts.',
        ],
        'portal_tenant_requests' => [
            'heading' => 'No requests yet',
            'description' => 'Raise a request for maintenance, fit-out approval, access or anything else you need from mall management.',
            'cta' => 'Submit a request',
        ],
        'portal_tenant_sales' => [
            'heading' => 'No sales declared yet',
            'description' => 'If your lease has percentage rent, declare each month\'s turnover here by the deadline in your lease.',
            'cta' => 'Declare sales',
        ],
        'warehouses' => [
            'heading' => 'No stores yet',
            'description' => 'A store is where stock physically sits — the main store, a housekeeping cupboard, a maintenance workshop.',
            'cta' => 'Add a store',
        ],
        'inventory_items' => [
            'heading' => 'No stock items yet',
            'description' => 'Consumables and spares — filters, lamps, cleaning supplies. Each item tracks quantity on hand against a reorder level.',
            'cta' => 'Add a stock item',
        ],
        'departments' => [
            'heading' => 'No departments yet',
            'description' => 'Departments route requests and group staff. They are seeded with the system — run the roles/permissions seeder if this is empty.',
        ],
        'roles' => [
            'heading' => 'No roles yet',
            'description' => 'Roles carry the per-module permissions. They are seeded with the system — run the roles/permissions seeder if this is empty.',
        ],
        'custodies' => [
            'heading' => 'No custody advances yet',
            'description' => 'Cash advanced to a staff member (عهدة) to spend on the mall\'s behalf, settled later against receipts.',
            'cta' => 'Advance custody',
        ],
        'portal_announcements' => [
            'heading' => 'Nothing from the mall office yet',
            'description' => 'Notices from the mall — works, trading hours, events — appear here and on your phone.',
        ],
        'announcements' => [
            'heading' => 'No announcements yet',
            'description' => 'Broadcast a notice to every tenant in this property — fire drills, holiday hours, works notices.',
            'cta' => 'Compose an announcement',
        ],
        'employees' => [
            'heading' => 'No employees yet',
            'description' => 'Your own staff — the roster payroll runs from. Not tenants, and not their staff.',
            'cta' => 'Add an employee',
        ],
        'fixed_assets' => [
            'heading' => 'No fixed assets yet',
            'description' => 'Capitalised equipment — chillers, generators, escalators. Each depreciates monthly into the general ledger.',
            'cta' => 'Register a fixed asset',
        ],
    ],

];
