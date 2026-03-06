<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('roles') || !Schema::hasTable('user_roles')) {
            return;
        }

        $now = now();

        $legacyRoles = DB::table('users')
            ->whereNotNull('role')
            ->distinct()
            ->pluck('role');

        foreach ($legacyRoles as $legacyRole) {
            DB::table('roles')->updateOrInsert(
                ['name' => $legacyRole],
                [
                    'description' => ucfirst(str_replace('_', ' ', (string) $legacyRole)),
                    'is_system_role' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $roleByName = DB::table('roles')->pluck('id', 'name');

        DB::table('users')
            ->whereNotNull('role')
            ->orderBy('id')
            ->chunk(500, function ($users) use ($roleByName, $now): void {
                foreach ($users as $user) {
                    $roleId = $roleByName[$user->role] ?? null;
                    if (!$roleId) {
                        continue;
                    }

                    $exists = DB::table('user_roles')
                        ->where('user_id', $user->id)
                        ->where('role_id', $roleId)
                        ->exists();

                    if (!$exists) {
                        DB::table('user_roles')->insert([
                            'user_id' => $user->id,
                            'role_id' => $roleId,
                            'assigned_at' => $now,
                            'assigned_by' => null,
                            'expires_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructive rollback for role migration data.
    }
};
