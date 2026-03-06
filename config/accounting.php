<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default account codes (stable references)
    |--------------------------------------------------------------------------
    |
    | Keep these codes stable for decades. You can rename account names later,
    | but avoid changing codes once data exists.
    |
    */
    'accounts' => [
        'ar_students' => 'AR_STUDENTS',
        'income_fees' => 'INCOME_FEES',
        'income_transport' => 'INCOME_TRANSPORT',
        'income_other' => 'INCOME_OTHER',
        'expense_operating' => 'EXPENSE_OPERATING',
        'contra_scholarship' => 'CONTRA_SCHOLARSHIP',
        'cash_drawer' => 'CASH_DRAWER',
        'bank_main' => 'BANK_MAIN',
        'bank_secondary' => 'BANK_SECONDARY',
        'clearing_gateway' => 'CLEARING_GATEWAY',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment method -> account code mapping
    |--------------------------------------------------------------------------
    */
    'payment_method_accounts' => [
        'cash' => 'CASH_DRAWER',
        'cheque' => 'BANK_MAIN',
        'online' => 'BANK_MAIN',
        'card' => 'BANK_MAIN',
        'upi' => 'BANK_MAIN',
    ],
];
