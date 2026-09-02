<?php

return [
    'activity' => [
        'nav_label' => 'Activity Log',
        'page_title' => 'Activity Log',
        'when' => 'When',
        'who' => 'Who',
        'what' => 'What',
        'record' => 'Record',
        'event' => 'Event',
        'changes' => 'Changes',
        'subject' => 'Subject',
        'system' => 'System',
        'subjects' => [
            'facility_work_order_comment' => 'Work order comment',
            'work_order_proposal' => 'Work order quote',
            'failure_code' => 'Failure code',
            'holiday' => 'Holiday',
            'payment_method' => 'Payment method',
            'retail_category' => 'Retail category',
            'expense_category' => 'Expense category',
            'tenant_request_subcategory' => 'Request subcategory',
            'document_template' => 'Document wording',
            'payroll_rate' => 'Payroll rate',
            'recurring_expense' => 'Recurring cost',
            'custom_field' => 'Custom field',
            'violation_category' => 'Violation category',
            'vendor_document_type' => 'Vendor document type',
            'work_order_labour' => 'Work order labour',
            'trade' => 'Trade',
            'unit_ownership' => 'Unit ownership',
            // spatie's fallback log name. Every model now declares its own, but rows written
            // BEFORE that fix are still filed under `default` and must still read as something.
            'default' => 'Other',
            'property_setting' => 'Property setting',
            'tax_code' => 'Tax code',
            'tax_rate' => 'Tax rate',
            'utility_tariff' => 'Utility tariff',
            'utility_tariff_rate' => 'Utility price',
            'bank_match' => 'Bank match',
            'bank_account' => 'Bank account',
            'bank_statement' => 'Bank statement',
            'charge_code' => 'Charge code',
            'rent_index' => 'Rent index',
            'lease_clause' => 'Lease clause',
            'work_permit' => 'Permit to work',
            'tenant_document' => 'Tenant document',
            'account_mapping' => 'Posting map row',
            'floor' => 'Floor',
            'rentable_item' => 'Rentable item',
            'marketing_post' => 'Marketing post',
            'lease' => 'Lease',
            'lease_option' => 'Lease option',
            'invoice' => 'Invoice',
            'payment' => 'Payment',
            'tenant' => 'Tenant',
            'charge' => 'Charge',
            'asset' => 'Property',
            'tenant_sales' => 'Sales Declaration',
            'cam_pool' => 'CAM Pool',
            'credit_note' => 'Credit Note',
            'vendor' => 'Vendor',
            'vendor_contract' => 'Vendor Contract',
            'note' => 'Note',
            'marketing_budget' => 'Marketing Budget',
            'marketing_spend' => 'Marketing Spend',
            'department' => 'Department',
            'user' => 'User',
            'owner_request' => 'Owner Request',
            'access_control' => 'Access Control',
            'tenant_request' => 'Tenant Request',
            'tenant_sales_declaration' => 'Sales Declaration',
            'cam_expense_pool' => 'CAM Expense Pool',
            'expense' => 'Expense',
            'vendor_bill' => 'Vendor Bill',
            'journal_entry' => 'Journal Entry',
            'ledger_account' => 'Ledger Account',
            'deposit_transaction' => 'Deposit Transaction',
            'fixed_asset' => 'Fixed Asset',
            'fixed_asset_disposal' => 'Asset Disposal',
            'depreciation_entry' => 'Depreciation Entry',
            'employee' => 'Employee',
            'employee_advance' => 'Employee Advance',
            'employee_advance_repayment' => 'Advance Repayment',
            'payroll' => 'Payroll',
            'custody' => 'Custody',
            'custody_transaction' => 'Custody Transaction',
            'warehouse' => 'Warehouse',
            'inventory_item' => 'Inventory Item',
            'stock_movement' => 'Stock Movement',
            'service_plan' => 'Service Plan',
            'work_order_part' => 'Work Order Part',
            'purchase_request' => 'Procurement Request',
            'facility_work_order' => 'Work Order',
            'equipment' => 'Equipment',
            'area' => 'Area',
            'violation' => 'Violation',
            'approval_rule' => 'Approval Rule',
            'disbursement' => 'Owner Disbursement',
            'sla_penalty' => 'SLA Penalty',
            'owner_statement' => 'Owner Statement',
            'owner_statement_run' => 'Owner Statement Run',
            'post_dated_cheque' => 'Post-dated Cheque',
            'sla_policy' => 'SLA Policy',
            'vendor_contract_amendment' => 'Contract Amendment',
            'vendor_document' => 'Vendor Document',

            // Not a model — the Settings and Property Overrides pages log under this name so a
            // money figure changed on a settings screen leaves history like any other record.
            'settings' => 'Settings',

            // Raised by VoidVendorBillPaymentService — the payment is not itself an activity-logged
            // model, so this log name exists only here.
            'vendor_bill_payment' => 'Vendor Payment',
        ],
        'events' => [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',

            // Custom events raised by the void/reverse services. Absent until 2026-08-12, so
            // every voided invoice, payment and vendor payment showed the literal string
            // `admin.activity.events.voided` in its badge — in English too, not just Arabic.
            'voided' => 'Voided',
            'reversed' => 'Reversed',
            // Raised by AcceptWorkOrderService when a contractor (or an operator on their
            // behalf) acknowledges a dispatched job — the act the response SLA is measured to.
            'accepted' => 'Accepted',
        ],

        // What a row's stored `description` means. **Descriptions are KEYS, not sentences** —
        // the log stores data and this file turns it into words, so one row reads correctly in
        // both languages and a wording fix reaches every row ever written. Rows written before
        // that rule fall back to their stored English (see ActivityVocabulary::description).
        // NESTED, not flat: a stored description is looked up with `__()`, which reads dots as
        // array nesting — a literal `'payment.voided' => …` key can never be found.
        'descriptions' => [
            'facility_work_order' => ['accepted' => 'Job accepted by the contractor'],
            'invoice' => [
                'cancelled' => 'Draft invoice cancelled',
                'voided' => 'Invoice voided',
                // The two settlement channels an operator can un-apply from the invoice screen.
                // Filed against the INVOICE because the application rows are soft-deleted by the
                // reversal, and a trail row pointing at a deleted subject is one nobody finds.
                'credit_reversed' => 'Applied tenant credit reversed',
                'deposit_reversed' => 'Netted security deposit reversed',
            ],
            'payment' => ['voided' => 'Receipt voided'],
            'vendor_bill' => ['cancelled' => 'Vendor bill cancelled'],
            'expense' => ['cancelled' => 'Expense cancelled'],
            'payroll' => ['cancelled' => 'Payroll run cancelled'],
            'deposit_transaction' => ['cancelled' => 'Deposit transaction cancelled'],
            'disbursement' => ['cancelled' => 'Owner disbursement cancelled'],
            'credit_note' => [
                'voided' => 'Credit note voided',
                'reversed' => 'Credit note applications reversed',
            ],
            'invoice_write_off' => ['reversed' => 'Bad-debt write-off reversed'],
            'fixed_asset' => ['reversed' => 'Fixed asset acquisition reversed'],
            'marketing_spend' => ['cancelled' => 'Marketing spend cancelled'],
            'employee_advance' => ['reversed' => 'Employee advance reversed'],
            'custody' => ['reversed' => 'Custody float reversed'],
            'vendor_bill_payment' => ['voided' => 'Vendor payment voided'],
            'employee_advance_repayment' => ['reversed' => 'Advance repayment reversed'],
            'custody_transaction' => ['reversed' => 'Custody transaction reversed'],
            'settings' => ['updated' => 'Portfolio settings updated'],
            'property_settings' => ['updated' => 'Property settings updated'],

            // AccessControlAudit::ACTIONS — flat, single-segment keys, because the stored value
            // is also the attribute_changes field name and rows predating this carry it.
            'role_granted' => 'Role granted',
            'role_revoked' => 'Role revoked',
            'permission_granted' => 'Permission granted to role',
            'permission_revoked' => 'Permission revoked from role',
            'role_deleted' => 'Role deleted — every holder lost it',
            'property_access_change_blocked' => 'Property access change refused',
            'protected_role_change_blocked' => 'Protected role change refused',
        ],

        // Per-model field labels — rung 1 of ActivityVocabulary::field(), for the rare column
        // whose meaning is model-specific. Everything else resolves from the shared
        // `admin.fields.*` catalogue, so the audit trail names a field exactly as its form does.
        'fields' => [
            // `unit` here is a unit of MEASURE (each / box / kg), not a leasable unit.
            'inventory_item' => ['unit' => 'Unit of measure'],

            // The audit trail invents its own field names (the value is a list of role or
            // permission names), so they belong here rather than in the form catalogue.
            'access_control' => [
                'role_granted' => 'Roles granted',
                'role_revoked' => 'Roles revoked',
                'permission_granted' => 'Permissions granted',
                'permission_revoked' => 'Permissions revoked',
                'role_deleted' => 'Permissions the deleted role held',
                'property_access_change_blocked' => 'Properties refused',
                'protected_role_change_blocked' => 'Roles refused',
            ],
        ],
        'empty_value' => '(empty)',
        'held_by' => 'held by',
        'bool_true' => 'yes',
        'bool_false' => 'no',
        'period' => 'Period',
        'periods' => [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'last_7_days' => 'Last 7 days',
            'last_30_days' => 'Last 30 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
        ],
    ],

];
