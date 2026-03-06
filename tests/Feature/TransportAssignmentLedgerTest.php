<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransportAssignmentLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_transport_with_auto_generate_cycle_creates_ledger_debit_for_enrollment(): void
    {
        [$admin, $enrollment] = $this->bootstrapTransportContext();
        Sanctum::actingAs($admin);

        $route = TransportRoute::create([
            'route_name' => 'Route A',
            'route_number' => 'R-100',
            'vehicle_number' => 'BUS-1',
            'driver_name' => 'Driver A',
            'fee_amount' => '0',
            'status' => 'active',
            'active' => true,
        ]);

        $stop = TransportStop::create([
            'route_id' => $route->id,
            'stop_name' => 'Stop A',
            'fee_amount' => '500.00',
            'distance_km' => '3.00',
            'pickup_time' => '08:00:00',
            'drop_time' => '15:00:00',
            'stop_order' => 1,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/transport/assignments', [
            'enrollment_id' => $enrollment->id,
            'route_id' => $route->id,
            'stop_id' => $stop->id,
            'start_date' => now()->toDateString(),
            'auto_generate_cycle' => true,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('student_fee_ledger', [
            'enrollment_id' => $enrollment->id,
            'transaction_type' => 'debit',
            'reference_type' => 'transport',
            'amount' => '500.00',
        ]);
    }

    private function bootstrapTransportContext(): array
    {
        $this->seed(AccountingSeeder::class);

        $admin = User::create([
            'email' => 'admin+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'super_admin',
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'status' => 'active',
        ]);

        $studentUser = User::create([
            'email' => 'student+' . uniqid() . '@school.test',
            'password' => 'password',
            'role' => 'student',
            'first_name' => 'Test',
            'last_name' => 'Student',
            'status' => 'active',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'admission_number' => 'ADM-' . random_int(10000, 99999),
            'admission_date' => now()->toDateString(),
            'date_of_birth' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'status' => 'active',
        ]);

        $academicYear = AcademicYear::create([
            'name' => 'AY ' . now()->year,
            'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
            'status' => 'active',
            'is_current' => true,
        ]);

        $class = ClassModel::create([
            'name' => 'Class Test',
            'numeric_order' => 1,
            'status' => 'active',
        ]);

        $section = Section::create([
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
            'name' => 'A',
            'capacity' => 40,
            'status' => 'active',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
            'section_id' => $section->id,
            'roll_number' => 1,
            'enrollment_date' => now()->toDateString(),
            'status' => 'active',
            'is_locked' => false,
        ]);

        return [$admin, $enrollment];
    }
}

