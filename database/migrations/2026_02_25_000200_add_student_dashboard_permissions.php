<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $now = now();
        $codes = [
            'student.view_dashboard',
            'student.view_attendance',
            'student.view_result',
            'student.view_fee',
            'student.view_admit_card',
            'student.view_notice_board',
            'student.view_assignments',
            'student.view_timetable',
            'student.view_academic_history',
            'student.view_attendance_history',
        ];

        foreach ($codes as $code) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => 'student',
                    'action' => str_replace('student.', '', $code),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $studentRoleId = DB::table('roles')->where('name', 'student')->value('id');
        if (!$studentRoleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', $codes)
            ->pluck('id')
            ->all();

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $studentRoleId, 'permission_id' => $permissionId],
                ['updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $codes = [
            'student.view_dashboard',
            'student.view_attendance',
            'student.view_result',
            'student.view_fee',
            'student.view_admit_card',
            'student.view_notice_board',
            'student.view_assignments',
            'student.view_timetable',
            'student.view_academic_history',
            'student.view_attendance_history',
        ];

        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();
        if (!empty($permissionIds)) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};
