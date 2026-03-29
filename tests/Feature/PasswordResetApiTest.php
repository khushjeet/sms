<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_teacher_can_request_a_password_reset_link_via_api(): void
    {
        Notification::fake();

        $user = $this->makeUser('teacher');

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJson([
                'status' => 'If your account is eligible, you will receive a password reset link shortly.',
            ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_active_school_admin_can_request_a_password_reset_link_via_api(): void
    {
        Notification::fake();

        $user = $this->makeUser('school_admin');

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJson([
                'status' => 'If your account is eligible, you will receive a password reset link shortly.',
            ]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_ineligible_accounts_receive_generic_success_without_notification(): void
    {
        Notification::fake();

        $user = $this->makeUser('parent');

        $this->postJson('/api/v1/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJson([
                'status' => 'If your account is eligible, you will receive a password reset link shortly.',
            ]);

        Notification::assertNothingSent();
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'email' => uniqid($role . '-', true) . '@school.test',
            'password' => 'password123',
            'role' => $role,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'status' => 'active',
        ]);
    }
}
