<?php

return [
    'employees' => [
        'payslips' => 'Payslips',
        'no_payslips' => 'No payslips yet — this employee has not appeared on a payroll run',
        'repays_advance' => 'Repays an advance',
        'errors' => [
            'repayment_before_advance' => 'A repayment cannot be dated before the advance was granted (:granted).',
            'repayment_already_reversed' => 'This repayment has already been reversed.',
        ],
        'group' => 'HR',
        'singular' => 'Employee',
        'plural' => 'Employees',
        'fields' => [
            'code' => 'Staff no.', 'name' => 'Name', 'national_id' => 'National ID', 'position' => 'Position',
            'property' => 'Property', 'department' => 'Department', 'hire_date' => 'Hired', 'base_salary' => 'Base salary',
            'payment_method' => 'Paid via', 'phone' => 'Phone', 'status' => 'Status', 'terminated_on' => 'Terminated on',
            'notes' => 'Notes', 'total_base_salary' => 'Total base payroll',
        ],
        'statuses' => ['active' => 'Active', 'terminated' => 'Terminated'],
        'methods' => ['cash' => 'Cash', 'bank' => 'Bank'],
        'actions' => ['terminate' => 'Terminate', 'reinstate' => 'Reinstate', 'reinstate_confirm' => 'This person returns to active. Their termination date is cleared, so every payroll run, picker and report treats them as employed again.', 'grant_advance' => 'Grant advance', 'record_repayment' => 'Record repayment', 'reverse_repayment' => 'Reverse a repayment'],
        'terminated' => 'Employee terminated',
        'reinstated' => 'Employee reinstated',
        'advances' => 'Advances & loans',
        'advance_fields' => [
            'type' => 'Type', 'amount' => 'Amount', 'advance_date' => 'Date', 'paid_from' => 'Paid from',
            'repaid' => 'Repaid', 'outstanding' => 'Outstanding', 'repaid_on' => 'Repaid on', 'method' => 'Method',
            'granted_by' => 'Granted by',
        ],
        'types' => ['advance' => 'Advance', 'loan' => 'Loan'],
        'granted' => 'Advance granted',
        'repaid_notice' => 'Repayment recorded',
        'reverse_repayment_modal_description' => 'Reversing voids the repayment and puts the amount back on the outstanding balance. The record is kept for audit.',
        'reverse_which_repayment' => 'Which repayment',
        'reverse_reason' => 'Reason',
        'repayment_reversed' => 'Repayment reversed',
    ],

    'payroll' => [
        'errors' => [
            'already_paid_this_month' => 'Approving this run would give :count employee(s) a SECOND approved payslip for this month — :names. Cancel the earlier run, or remove them from this one. A supplementary run is fine; paying the same person twice for one month is not.',
            'approved_immutable' => 'This payroll run has been approved and posted to the ledger, so its amounts, period and source account are settled. Cancel the run to correct it, or record the difference on the next run.',
        ],
    ],
    'payroll_lines' => [
        'title' => 'Employee lines',
        'add_line' => 'Add employee',
        'payslip' => 'Payslip',
        'fields' => [
            'employee' => 'Employee', 'gross' => 'Gross', 'salary_tax' => 'Salary tax',
            'social_insurance' => 'Social insurance', 'net' => 'Net',
            'basic' => 'Basic', 'allowances' => 'Allowances',
            'employer_social_insurance' => 'Employer social insurance',
            'advance_deduction' => 'Advance installment',
            'other_deductions' => 'Other deductions',
            'other_deductions_helper' => 'Penalties, absence, damages or any other withholding (خصومات). Deducted from net pay.',
            'deduction_note' => 'Deduction note',
            'deduction_note_placeholder' => 'e.g. absence 2 days, penalty…',
            'gross_helper' => 'Total earnings (basic + allowances).',
            'allowances_helper' => 'The allowance portion of gross (بدلات). Basic = gross − allowances.',
            'employer_social_insurance_helper' => 'Company contribution — not deducted from net pay.',
        ],
        'deduct_advance' => [
            'label' => 'Advance installment',
            'advance' => 'Advance / loan',
            'advance_helper' => 'The outstanding advance this installment repays.',
            'amount' => 'Installment amount',
            'amount_helper' => 'Deducted from net pay this run and applied to the loan. Set to 0 to remove it.',
            'advance_required' => 'Choose an outstanding advance for this installment.',
        ],
        'generate' => [
            'rates' => 'It will deduct salary tax at :tax% and social insurance at :si% of gross.',
            'no_rates' => 'Salary tax and social insurance are both set to 0%, so every payslip will show gross = net. Set them in Settings → Payroll if that is not intended.',
            'label' => 'Generate payslips',
            'heading' => 'Generate payslips from the roster',
            'description' => 'This adds a payslip for each of the :count active employee(s) on this property, pre-filled from their base salary and the configured deduction rates. Review and adjust each line before approving.',
            'none' => 'Every active employee on this property already has a payslip on this run — there is no one left to add.',
            'confirm' => 'Generate',
            'nothing_added' => 'No payslips were added — every active employee already has one.',
            'done' => 'Payslips generated',
            'added_body' => ':count payslip(s) added from the roster. Review and adjust the amounts, then approve the run.',
            'zero_salary_note' => ':count had no base salary set — enter their pay before approving.',
        ],
        'empty' => [
            'heading' => 'No payslips yet',
            'description' => 'Generate them from the active roster, or add employees one at a time.',
        ],
        'errors' => [
            'net_negative' => 'Deductions exceed gross — net pay would be negative.',
            'run_not_draft' => 'This payroll run has been approved, so its payslips are settled — the books have already taken it. Cancel the run to correct it, or record the difference on the next run.',
            'generate_not_draft' => 'Payslips can only be generated while the run is a draft.',
            'allowances_exceed_gross' => 'Allowances cannot exceed gross — they are a portion of it.',
            'advance_deduction_without_advance' => 'An advance installment must name the advance it repays.',
            'advance_over_repay' => 'That exceeds the advance’s outstanding balance (EGP :outstanding).',
            'advance_gone' => 'The linked advance no longer exists — remove the installment before approving.',
        ],
    ],

    'payslip' => [
        'title' => 'Payslip',
        'month' => 'Month',
        'employee' => 'Employee',
        'details' => 'Details',
        'basic' => 'Basic salary',
        'allowances' => 'Allowances',
        'gross' => 'Gross salary',
        'salary_tax' => 'Salary tax',
        'social_insurance' => 'Social insurance',
        'advance_deduction' => 'Advance installment',
        'other_deductions' => 'Other deductions',
        'net' => 'Net pay',
        'employer_social_insurance' => 'Employer social insurance',
        'employer_social_insurance_note' => 'company contribution, not deducted from your pay',
        'egp' => 'EGP',
        'footer' => 'This payslip is computer-generated and does not require a signature.',
    ],

    'users' => [
        // Account lifecycle — suspend a leaver instead of deleting them, so every record and
        // activity-log entry they touched stays attributable to a real name.
        'statuses' => [
            'active' => 'Active',
            'suspended' => 'Suspended',
        ],
        'actions' => [
            'suspend' => 'Suspend access',
            'suspend_confirm' => 'This account will be signed out and blocked from logging in. Nothing it created is deleted, and you can reactivate it at any time.',
            'reactivate' => 'Reactivate',
        ],
        'fields' => [
            'suspended_reason' => 'Reason (optional)',
            'suspended_reason_help' => 'Shown next to the account in the user list — e.g. "left the company", "on secondment".',
        ],
        'notices' => [
            'suspended' => ':name can no longer sign in',
            'reactivated' => ':name can sign in again',
        ],
        'account' => 'Account',
        'name' => 'Name',
        'password' => 'Password',
        'password_edit_helper' => 'Leave blank to keep current password.',
        'roles' => 'Access Roles',
        'role' => 'Role',
        'role_helper' => 'super_admin = full control · manager = create + edit · viewer = read-only.',
        'properties' => 'Property Access',
        'properties_helper' => 'Which properties this user can switch into. New users start with every property selected — deselect to restrict.',
        'assigned_properties' => 'Assigned properties',
        'created' => 'Created',
        'roles_list' => [
            'super_admin' => 'Super Admin',
            'manager' => 'Manager',
            'viewer' => 'Viewer',
            'owner' => 'Owner',
            'leasing' => 'Leasing',
            'operations' => 'Operations',
            'accounting' => 'Accounting',
            'marketing' => 'Marketing',
            'hr' => 'HR',
            'mall_admin' => 'Mall Admin',
            'technician' => 'Technician',
            'coordinator' => 'Coordinator',
            'customer_service' => 'Customer Service',
            'vendor' => 'Vendor',
        ],
    ],

    // Roles module — cloning an existing role instead of ticking ~200 boxes by hand.
    'roles' => [
        'actions' => [
            'clone' => 'Clone role',
        ],
        'notices' => [
            'cloned' => 'Role “:name” created',
            'cloned_body' => 'It starts with the same :count permission(s). Edit it to narrow them down.',
        ],
    ],

    'operators' => [
        'label' => 'Operator',
        'all' => 'All Operators',
        'switch' => 'Switch operator',
    ],
    'auth' => [
        // Shown only after the submitted password has already been verified — so it names the
        // real reason without telling an attacker anything they did not just prove they knew.
        'account_suspended' => 'This account has been suspended. Contact your administrator to restore access.',
        'turnstile_failed' => 'We could not confirm you are human. Please complete the check and try again.',
    ],

];
