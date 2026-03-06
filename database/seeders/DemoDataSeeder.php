<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Enrollment;
use App\Models\FeeAssignment;
use App\Models\FeeStructure;
use App\Models\OptionalService;
use App\Models\Payment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentTransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // Users
            $superAdmin = User::firstOrCreate(
                ['email' => 'indianpublicschool2021@gmail.com'],
                [
                    'password' => Hash::make('ips@1234#'),
                    'role' => 'super_admin',
                    'first_name' => 'Super',
                    'last_name' => 'Admin',
                    'status' => 'active',
                ]
            );

            $schoolAdmin = User::firstOrCreate(
                ['email' => 'schooladmin@example.com'],
                [
                    'password' => Hash::make('password'),
                    'role' => 'school_admin',
                    'first_name' => 'School',
                    'last_name' => 'Admin',
                    'status' => 'active',
                ]
            );

            $accountant = User::firstOrCreate(
                ['email' => 'accountant@example.com'],
                [
                    'password' => Hash::make('password'),
                    'role' => 'accountant',
                    'first_name' => 'Finance',
                    'last_name' => 'Accountant',
                    'status' => 'active',
                ]
            );

            $teacher = User::firstOrCreate(
                ['email' => 'teacher@example.com'],
                [
                    'password' => Hash::make('password'),
                    'role' => 'teacher',
                    'first_name' => 'Class',
                    'last_name' => 'Teacher',
                    'status' => 'active',
                ]
            );





            // Academic Year
            $academicYear = AcademicYear::firstOrCreate(
                ['name' => '2025-2026'],
                [
                    'start_date' => '2025-04-01',
                    'end_date' => '2026-03-31',
                    'status' => 'active',
                    'is_current' => true,
                    'description' => 'Seeded academic year',
                ]
            );

            // Class & Section
            $class1 = ClassModel::firstOrCreate(
                ['name' => 'Class 1'],
                ['numeric_order' => 1, 'status' => 'active']
            );

            $sectionA = Section::firstOrCreate(
                [
                    'class_id' => $class1->id,
                    'academic_year_id' => $academicYear->id,
                    'name' => 'A',
                ],
                [
                    'capacity' => 40,
                    'class_teacher_id' => $teacher->id,
                    'room_number' => '101',
                    'status' => 'active',
                ]
            );


            // Fee Structures
            $tuition = FeeStructure::firstOrCreate(
                [
                    'class_id' => $class1->id,
                    'academic_year_id' => $academicYear->id,
                    'fee_type' => 'Tuition',
                ],
                [
                    'amount' => 12000,
                    'frequency' => 'annually',
                    'is_mandatory' => true,
                ]
            );

            $annual = FeeStructure::firstOrCreate(
                [
                    'class_id' => $class1->id,
                    'academic_year_id' => $academicYear->id,
                    'fee_type' => 'Annual',
                ],
                [
                    'amount' => 3000,
                    'frequency' => 'annually',
                    'is_mandatory' => true,
                ]
            );

            // Optional Services
            $transportService = OptionalService::firstOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'name' => 'Transport',
                ],
                [
                    'amount' => 6000,
                    'frequency' => 'annually',
                    'status' => 'active',
                ]
            );

            $hostelService = OptionalService::firstOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'name' => 'Hostel',
                ],
                [
                    'amount' => 24000,
                    'frequency' => 'annually',
                    'status' => 'active',
                ]
            );

            $mealService = OptionalService::firstOrCreate(
                [
                    'academic_year_id' => $academicYear->id,
                    'name' => 'Meals',
                ],
                [
                    'amount' => 4000,
                    'frequency' => 'annually',
                    'status' => 'active',
                ]
            );



            // Fee Assignments
            $baseFee = $tuition->amount + $annual->amount;
            $optionalFee1 = $transportService->amount + $hostelService->amount;
            $optionalFee2 = $mealService->amount;




            // Transport Route & Stops
            $route = TransportRoute::firstOrCreate(
                ['route_number' => 'R-01'],
                [
                    'route_name' => 'Route 1',
                    'fee_amount' => 6000,
                    'status' => 'active',
                ]
            );

            $stop1 = TransportStop::firstOrCreate(
                ['route_id' => $route->id, 'stop_name' => 'Main Gate'],
                [
                    'fee_amount' => 500,
                    'pickup_time' => '07:30:00',
                    'drop_time' => '14:30:00',
                    'stop_order' => 1,
                ]
            );

            $stop2 = TransportStop::firstOrCreate(
                ['route_id' => $route->id, 'stop_name' => 'City Center'],
                [
                    'fee_amount' => 600,
                    'pickup_time' => '07:45:00',
                    'drop_time' => '14:45:00',
                    'stop_order' => 2,
                ]
            );



            // Attendance (last 3 days)
            $dates = [
                now()->subDays(2)->toDateString(),
                now()->subDays(1)->toDateString(),
                now()->toDateString(),
            ];



          
        });
    }
}
