<?php

use App\Models\SchoolSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'school_watermark_text' => config('school.name'),
            'school_watermark_logo_url' => null,
        ];

        foreach ($defaults as $key => $value) {
            SchoolSetting::putValue($key, SchoolSetting::getValue($key, $value));
        }
    }

    public function down(): void
    {
        SchoolSetting::query()->whereIn('key', [
            'school_watermark_text',
            'school_watermark_logo_url',
        ])->delete();
    }
};
