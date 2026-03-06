<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    /**
     * Seed baseline RBAC entities and migrate legacy users.role values.
     */
    public function run(): void
    {
        $roles = [
            'super_admin' => 'System owner with unrestricted access',
            'school_admin' => 'School-level administrator',
            'hr' => 'HR operations and attendance approvals',
            'accountant' => 'Finance and accounting operations',
            'teacher' => 'Teaching and attendance operations',
            'parent' => 'Parent portal access',
            'student' => 'Student portal access',
            'staff' => 'General staff operations',
            'principal' => 'School principal operations',
        ];

        foreach ($roles as $name => $description) {
            Role::query()->firstOrCreate(
                ['name' => $name],
                [
                    'description' => $description,
                    'is_system_role' => true,
                ]
            );
        }

        $permissionCodes = [
            'students.view',
            'students.manage',
            'staff.view',
            'staff.manage',
            'academic.view',
            'academic.manage',
            'attendance.view',
            'attendance.mark',
            'attendance.approve',
            'attendance.override',
            'finance.view',
            'finance.manage',
            'reports.view',
            'reports.export',
            'transport.view',
            'transport.manage',
            'employee.view',
            'employee.create',
            'employee.update',
            'employee.delete',
            'payroll.view',
            'payroll.edit',
            'fee.collect',
            'ledger.reverse',
            'user.manage',
            'portal.parent.view',
            'portal.student.view',
            'student.view_dashboard',
            'student.view_attendance',
            'student.view_result',
            'student.view_fee',
            'student.view_admit_card',
            'student.view_notice_board',
            'student.view_assignments',
            'student.view_timetable',
            'student.view_academic_history',
            'student.view_attendance_history',
            'admit.view',
            'admit.generate',
            'admit.publish',
            'admit.manage_visibility',
            'system.manage',
        ];

        foreach ($permissionCodes as $code) {
            [$module, $action] = explode('.', $code, 2);
            Permission::query()->firstOrCreate(
                ['code' => $code],
                [
                    'module' => $module,
                    'action' => $action,
                ]
            );
        }

        $rolePermissionMap = [
            'super_admin' => ['*'],
            'school_admin' => [
                'students.view',
                'students.manage',
                'staff.view',
                'staff.manage',
                'academic.view',
                'academic.manage',
                'attendance.view',
                'attendance.mark',
                'attendance.approve',
                'attendance.override',
                'finance.view',
                'finance.manage',
                'reports.view',
                'reports.export',
                'transport.view',
                'transport.manage',
                'employee.view',
                'employee.create',
                'employee.update',
                'employee.delete',
                'fee.collect',
                'ledger.reverse',
                'admit.view',
            ],
            'hr' => [
                'staff.view',
                'staff.manage',
                'employee.view',
                'employee.create',
                'employee.update',
                'attendance.view',
                'attendance.mark',
                'attendance.approve',
                'attendance.override',
                'payroll.view',
                'payroll.edit',
                'reports.view',
            ],
            'accountant' => [
                'finance.view',
                'finance.manage',
                'reports.view',
                'reports.export',
                'transport.view',
                'fee.collect',
                'ledger.reverse',
                'payroll.view',
            ],
            'teacher' => ['academic.view', 'attendance.view', 'attendance.mark', 'students.view'],
            'parent' => ['portal.parent.view'],
            'student' => [
                'portal.student.view',
                'student.view_dashboard',
                'student.view_attendance',
                'student.view_result',
                'student.view_fee',
                'student.view_admit_card',
                'student.view_notice_board',
                'student.view_assignments',
                'student.view_timetable',
                'student.view_academic_history',
                'student.view_attendance_history',
            ],
            'staff' => ['staff.view', 'employee.view'],
            'principal' => [
                'students.view',
                'students.manage',
                'staff.view',
                'staff.manage',
                'academic.view',
                'academic.manage',
                'attendance.view',
                'attendance.mark',
                'attendance.approve',
                'reports.view',
                'reports.export',
                'transport.view',
                'employee.view',
                'employee.update',
                'payroll.view',
                'finance.view',
                'admit.view',
            ],
        ];

        foreach ($rolePermissionMap as $roleName => $codes) {
            $role = Role::query()->where('name', $roleName)->first();

            if (!$role) {
                continue;
            }

            if ($codes === ['*']) {
                $role->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('code', $codes)
                ->pluck('id')
                ->all();

            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        User::query()
            ->whereNotNull('role')
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    $user->syncLegacyRoleIntoRbac();
                }
            });

        $sampleUserRoleAssignments = [
            'superadmin@example.com' => ['super_admin'],
            'schooladmin@example.com' => ['school_admin', 'principal'],
            'hr@example.com' => ['hr'],
            'accountant@example.com' => ['accountant'],
            'teacher@example.com' => ['teacher'],
            'student1@example.com' => ['student'],
            'student2@example.com' => ['student'],
        ];

        foreach ($sampleUserRoleAssignments as $email => $roleNames) {
            $user = User::query()->where('email', $email)->first();
            if (!$user) {
                continue;
            }

            foreach ($roleNames as $roleName) {
                $user->assignRole($roleName);
            }
        }
    }
}
