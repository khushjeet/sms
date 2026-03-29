<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserAuthorizationCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_and_permission_checks_are_cached_on_the_user_instance(): void
    {
        $this->seed(RbacSeeder::class);

        $user = User::create([
            'email' => 'teacher+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'teacher',
            'first_name' => 'Test',
            'last_name' => 'Teacher',
            'status' => 'active',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->hasPermission('attendance.mark'));
        $this->assertTrue($user->hasPermission('attendance.mark'));
        $this->assertTrue($user->canAccessModule('attendance'));
        $this->assertTrue($user->canAccessModule('attendance'));

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(function (string $query): bool {
                return str_contains($query, 'user_roles')
                    || str_contains($query, 'role_permissions')
                    || str_contains($query, 'permissions')
                    || str_contains($query, 'roles');
            });

        $this->assertCount(
            2,
            $queries,
            'Expected one RBAC role query and one RBAC permission query for repeated authorization checks.'
        );
    }
}
