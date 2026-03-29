<?php

use App\Models\SchoolSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'school_name' => config('school.name'),
            'school_website' => config('school.website'),
            'school_phone' => '9771782335, 9931482335',
            'school_logo_url' => config('school.logo_url'),
            'school_address' => config('school.address'),
            'school_registration_number' => config('school.reg_no'),
            'school_udise_code' => config('school.udise'),
        ];

        foreach ($defaults as $key => $value) {
            SchoolSetting::putValue($key, SchoolSetting::getValue($key, $value));
        }
    }

    public function down(): void
    {
        SchoolSetting::query()->whereIn('key', [
            'school_name',
            'school_website',
            'school_phone',
            'school_logo_url',
            'school_address',
            'school_registration_number',
            'school_udise_code',
        ])->delete();
    }
};
