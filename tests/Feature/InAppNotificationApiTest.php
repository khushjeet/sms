<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InAppNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_fetch_their_own_notifications(): void
    {
        $user = $this->makeUser('student');
        $otherUser = $this->makeUser('teacher');

        UserNotification::query()->create([
            'user_id' => $user->id,
            'role' => 'student',
            'title' => 'Result published',
            'message' => 'Your result is available.',
            'type' => 'result',
            'priority' => 'important',
            'action_target' => '/student/result',
        ]);

        UserNotification::query()->create([
            'user_id' => $otherUser->id,
            'role' => 'teacher',
            'title' => 'Attendance locked',
            'message' => 'Attendance is locked.',
            'type' => 'attendance',
            'priority' => 'important',
            'action_target' => '/teacher/mark-attendance',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Result published');

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('by_type.result', 1);
    }

    public function test_user_can_mark_notification_as_read_and_mark_all_read(): void
    {
        $user = $this->makeUser('student');

        $first = UserNotification::query()->create([
            'user_id' => $user->id,
            'role' => 'student',
            'title' => 'Fee reminder',
            'message' => 'Fee is due soon.',
            'type' => 'finance',
            'priority' => 'important',
            'action_target' => '/student/fee',
        ]);

        $second = UserNotification::query()->create([
            'user_id' => $user->id,
            'role' => 'student',
            'title' => 'Result published',
            'message' => 'Your result is available.',
            'type' => 'result',
            'priority' => 'important',
            'action_target' => '/student/result',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/notifications/' . $first->id . '/read')
            ->assertOk()
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $first->id,
            'is_read' => true,
        ]);

        $this->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        $this->assertDatabaseHas('user_notifications', [
            'id' => $second->id,
            'is_read' => true,
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::query()->create([
            'email' => $role . '+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => $role,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'status' => 'active',
        ]);
    }
}
