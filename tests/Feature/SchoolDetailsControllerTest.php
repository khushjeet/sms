<?php

namespace Tests\Feature;

use App\Models\SchoolSetting;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolDetailsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_details_returns_logo_data_url_for_local_logo(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::create([
            'email' => 'superadmin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        SchoolSetting::putValue('school_name', 'Indian Public School');
        SchoolSetting::putValue('school_logo_url', 'http://127.0.0.1:8000/storage/assets/ips.png');

        $response = $this->getJson('/api/v1/school/details');

        $response
            ->assertOk()
            ->assertJsonPath('logo_url', 'http://127.0.0.1:8000/storage/assets/ips.png');

        $logoDataUrl = $response->json('logo_data_url');

        $this->assertIsString($logoDataUrl);
        $this->assertStringStartsWith('data:image/png;base64,', $logoDataUrl);
    }
}
