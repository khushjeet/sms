<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SchoolDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_details_can_be_updated_via_multipart_method_spoofing(): void
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

        $response = $this->post('/api/v1/school/details', [
            '_method' => 'PUT',
            'name' => 'Indian Public School',
            'website' => 'https://ips.example.test',
            'phone' => '9771782335',
            'address' => 'Main Road, Yogapatti',
            'registration_number' => 'REG-42',
            'udise_code' => 'UDISE-42',
            'watermark_text' => 'IPS',
            'watermark_logo_url' => 'storage/school/watermark.png',
            'logo_url' => 'storage/school/logo.png',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Indian Public School')
            ->assertJsonPath('data.website', 'https://ips.example.test')
            ->assertJsonPath('data.phone', '9771782335')
            ->assertJsonPath('data.address', 'Main Road, Yogapatti')
            ->assertJsonPath('data.registration_number', 'REG-42')
            ->assertJsonPath('data.udise_code', 'UDISE-42')
            ->assertJsonPath('data.watermark_text', 'IPS');

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_name',
            'value' => 'Indian Public School',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_website',
            'value' => 'https://ips.example.test',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_phone',
            'value' => '9771782335',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_address',
            'value' => 'Main Road, Yogapatti',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_registration_number',
            'value' => 'REG-42',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_udise_code',
            'value' => 'UDISE-42',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_watermark_text',
            'value' => 'IPS',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_watermark_logo_url',
            'value' => 'storage/school/watermark.png',
        ]);

        $this->assertDatabaseHas('school_settings', [
            'key' => 'school_logo_url',
            'value' => 'storage/school/logo.png',
        ]);
    }
}
