<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserAvatarUrlTest extends TestCase
{
    public function test_it_returns_null_when_avatar_is_missing(): void
    {
        $user = new User([
            'avatar' => null,
        ]);

        $this->assertNull($user->avatar_url);
    }

    public function test_it_builds_a_public_storage_url_for_relative_avatar_paths(): void
    {
        config()->set('filesystems.disks.public.url', 'http://localhost/storage');

        $user = new User([
            'avatar' => 'students/avatars/example.jpg',
        ]);

        $this->assertSame(
            'http://localhost/storage/students/avatars/example.jpg',
            $user->avatar_url
        );
    }

    public function test_it_keeps_absolute_avatar_urls_unchanged(): void
    {
        $user = new User([
            'avatar' => 'https://cdn.example.com/avatar.jpg',
        ]);

        $this->assertSame('https://cdn.example.com/avatar.jpg', $user->avatar_url);
    }
}
