<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditDownloadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_audit_download_catalog_and_store_log(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/audit-downloads/catalog')
            ->assertOk()
            ->assertJsonStructure([
                'modules' => [
                    ['module', 'title', 'route', 'available_formats', 'reports'],
                ],
            ]);

        $this->postJson('/api/v1/audit-downloads/logs', [
            'module' => 'assign_marks',
            'report_key' => 'final_marks_sheet',
            'report_label' => 'Final Marks Sheet',
            'format' => 'csv',
            'file_name' => 'marks.csv',
            'row_count' => 10,
            'filters' => ['class_id' => 1],
            'context' => ['class_name' => 'LKG'],
        ])->assertCreated();

        $this->assertDatabaseHas('download_audit_logs', [
            'user_id' => $admin->id,
            'module' => 'assign_marks',
            'report_key' => 'final_marks_sheet',
            'format' => 'csv',
        ]);
    }

    public function test_teacher_cannot_access_audit_download_endpoints(): void
    {
        Sanctum::actingAs($this->makeUser('teacher'));

        $this->getJson('/api/v1/audit-downloads/catalog')->assertForbidden();
        $this->getJson('/api/v1/audit-downloads/logs')->assertForbidden();
        $this->postJson('/api/v1/audit-downloads/logs', [
            'module' => 'published_results',
            'report_key' => 'result_paper',
            'report_label' => 'Result Paper',
            'format' => 'pdf',
        ])->assertForbidden();
    }

    public function test_audit_logs_can_be_filtered_and_exported(): void
    {
        $admin = $this->makeUser('super_admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/audit-downloads/logs', [
            'module' => 'assign_marks',
            'report_key' => 'final_marks_sheet',
            'report_label' => 'Final Marks Sheet',
            'format' => 'csv',
            'file_name' => 'marks.csv',
            'file_checksum' => str_repeat('a', 64),
            'row_count' => 12,
        ])->assertCreated();

        $this->postJson('/api/v1/audit-downloads/logs', [
            'module' => 'published_results',
            'report_key' => 'result_paper',
            'report_label' => 'Result Paper',
            'format' => 'pdf',
            'file_name' => 'result.pdf',
            'file_checksum' => str_repeat('b', 64),
            'row_count' => 5,
        ])->assertCreated();

        $this->getJson('/api/v1/audit-downloads/logs?module=assign_marks&format=csv')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.module', 'assign_marks');

        $this->get('/api/v1/audit-downloads/logs/export?module=assign_marks')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->get('/api/v1/audit-downloads/logs/archive?module=published_results')
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');
    }

    private function makeUser(string $role, string $suffix = null): User
    {
        $suffix ??= $role . rand(100, 999);

        return User::create([
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $suffix . '@example.com',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
