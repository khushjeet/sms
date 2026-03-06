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
        $permissions = [
            'admit.view',
            'admit.generate',
            'admit.publish',
            'admit.manage_visibility',
        ];

        foreach ($permissions as $code) {
            [, $action] = explode('.', $code, 2);
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'module' => 'admit',
                    'action' => $action,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $rolePermissionMap = [
            'school_admin' => ['admit.view'],
            'principal' => ['admit.view'],
        ];

        foreach ($rolePermissionMap as $roleName => $codes) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (!$roleId) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $codes = [
            'admit.view',
            'admit.generate',
            'admit.publish',
            'admit.manage_visibility',
        ];

        $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();
        if (!empty($permissionIds)) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('code', $codes)->delete();
    }
};
