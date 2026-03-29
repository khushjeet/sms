<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->string('principal_signature_path')->nullable()->after('current_address');
            $table->string('director_signature_path')->nullable()->after('principal_signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn(['principal_signature_path', 'director_signature_path']);
        });
    }
};
