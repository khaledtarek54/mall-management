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
            'work_order_proposal' => 'Work order quote',
            'failure_code' => 'Failure code',
            'holiday' => 'Holiday',
            'payment_method' => 'Payment method',
            'retail_category' => 'Retail category',
            'expense_category' => 'Expense category',
            'tenant_request_subcategory' => 'Request subcategory',
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
        ],

        // What a row's stored `description` means. **Descriptions are KEYS, not sentences** —
        // the log stores data and this file turns it into words, so one row reads correctly in
        // both languages and a wording fix reaches every row ever written. Rows written before
        // that rule fall back to their stored English (see ActivityVocabulary::description).
        // NESTED, not flat: a stored description is looked up with `__()`, which reads dots as
        // array nesting — a literal `'payment.voided' => …` key can never be found.
        'descriptions' => [
            'invoice' => ['voided' => 'Invoice voided'],
            'payment' => ['voided' => 'Payment voided / refunded'],
            'vendor_bill_payment' => ['voided' => 'Vendor payment voided'],
            'employee_advance_repayment' => ['reversed' => 'Advance repayment reversed'],
            'custody_transaction' => ['reversed' => 'Custody transaction reversed'],
            'settings' => ['updated' => 'Portfolio settings updated'],
            'property_settings' => ['updated' => 'Property settings updated'],
        ],

        // Per-model field labels — rung 1 of ActivityVocabulary::field(), for the rare column
        // whose meaning is model-specific. Everything else resolves from the shared
        // `admin.fields.*` catalogue, so the audit trail names a field exactly as its form does.
        'fields' => [
            // `unit` here is a unit of MEASURE (each / box / kg), not a leasable unit.
            'inventory_item' => ['unit' => 'Unit of measure'],
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
