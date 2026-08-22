<?php

return [
    'payroll_rates_screen' => [
        'singular' => 'Payroll rate',
        'plural' => 'Payroll rates',
        'in_force' => 'In force',
        'no_band' => 'No band',
        'sections' => [
            'period' => 'From when',
            'band' => 'Insurable wage band',
            'band_description' => 'Social insurance is charged on the wage inside this band, not on the whole salary. Leave a box empty for no limit.',
            'rates' => 'Contribution rates',
            'rates_description' => 'Percentages of the insurable wage, except salary tax which is a percentage of the whole gross. Leave at 0 to enter each payslip by hand.',
        ],
        'help' => [
            'effective_from' => 'The first day these numbers apply. They stay in force until the next row starts.',
            'floor' => 'An employee earning less is still insured on this amount.',
            'ceiling' => 'Earnings above this are not insured. Caps the employer share too.',
            'employee_si' => 'Deducted from the payslip. Egypt: 11% of the insurable wage.',
            'employer_si' => 'A company cost. It does not reduce net pay.',
            'salary_tax' => 'A flat rate on gross. Egyptian income tax is progressive — leave 0 and enter per employee.',
        ],
    ],
];
