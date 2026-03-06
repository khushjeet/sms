<?php

return [
    'name' => env('SCHOOL_NAME', env('APP_NAME', 'School Management System')),
    'logo_url' => env('SCHOOL_LOGO_URL', null),
    'address' => env('SCHOOL_ADDRESS', ''),
    'phone' => env('SCHOOL_PHONE', ''),
    'website' => env('SCHOOL_WEBSITE', ''),
    'reg_no' => env('SCHOOL_REG_NO', env('SCHOOL_REGISTRATION_NUMBER', '')),
    'udise' => env('SCHOOL_UDISE', env('SCHOOL_UDISE_CODE', '')),
];
