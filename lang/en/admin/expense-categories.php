<?php

return [
    'expense_categories' => [
        'singular' => 'Expense category',
        'plural' => 'Expense categories',
        'floor' => 'Default account',
        'help' => [
            'code' => 'The value stored on every bill and expense. Cannot change once saved.',
            'ledger_account' => 'Leave blank to keep the account this category books to today.',
            'cost_nature' => 'Fixed costs do not move with occupancy; variable ones do. Read by your own reporting.',
            'is_active' => 'Switching off hides it from pickers; documents already using it are unchanged.',
        ],
    ],
];
