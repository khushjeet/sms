import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { NgFor } from '@angular/common';
import { AuthService } from '../core/services/auth.service';

interface NavItem {
  label: string;
  route: string;
  roles?: string[];
}

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, NgFor],
  templateUrl: './app-shell.component.html',
  styleUrl: './app-shell.component.scss'
})
export class AppShellComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  readonly user = computed(() => this.auth.user());

  readonly navItems: NavItem[] = [
    { label: 'Dashboard', route: '/dashboard' },
    { label: 'Admit Card', route: '/student/admit-card', roles: ['student'] },
    { label: 'Fee', route: '/student/fee', roles: ['student'] },
    { label: 'Result', route: '/student/result', roles: ['student'] },
    { label: 'Timetable', route: '/student/timetable', roles: ['student'] },
    { label: 'Academic History', route: '/student/academic-history', roles: ['student'] },
    { label: 'Attendance History', route: '/student/attendance-history', roles: ['student'] },
    { label: 'Students', route: '/students', roles: ['super_admin', 'school_admin', 'parent'] },
    { label: 'Manage Employees', route: '/employees', roles: ['super_admin', 'school_admin'] },
    { label: 'Enrollments', route: '/enrollments', roles: ['super_admin', 'school_admin'] },
    { label: 'Attendance', route: '/attendance', roles: ['super_admin', 'school_admin'] },
    { label: 'Academic Years', route: '/academic-years', roles: ['super_admin', 'school_admin'] },
    { label: 'Exam Configuration', route: '/exam-configurations', roles: ['super_admin'] },
    { label: 'Classes', route: '/classes', roles: ['super_admin', 'school_admin'] },
    { label: 'Sections', route: '/sections', roles: ['super_admin', 'school_admin'] },
    { label: 'Subjects', route: '/subjects', roles: ['super_admin', 'school_admin'] },
    { label: 'Assign Marks', route: '/admin/assign-marks', roles: ['super_admin'] },
    { label: 'Admit Cards', route: '/admin/admit-cards', roles: ['super_admin'] },
    { label: 'Published Result', route: '/admin/published-results', roles: ['super_admin'] },
    { label: 'Allotted Subjects', route: '/subjects', roles: ['teacher'] },
    { label: 'Assign Marks', route: '/teacher/assign-marks', roles: ['teacher'] },
    { label: 'Download Results', route: '/teacher/published-results', roles: ['teacher'] },
    { label: 'Mark Attendance', route: '/teacher/mark-attendance', roles: ['teacher'] },
    { label: 'Subject Teacher Assign', route: '/subjects/teacher-assignments', roles: ['super_admin', 'school_admin'] },
    { label: 'Finance', route: '/finance', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'HR Payroll', route: '/hr-payroll', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'Class Subject Assign', route: '/subjects/assignments', roles: ['super_admin', 'school_admin'] },
    { label: 'Expenses', route: '/expenses', roles: ['super_admin', 'school_admin', 'accountant'] }
  ];

  visibleItems() {
    const role = this.user()?.role;
    const items = this.navItems.filter((item) => !item.roles || (role && item.roles.includes(role)));

    if (role === 'teacher') {
      return items.filter((item) => [
        '/dashboard',
        '/subjects',
        '/teacher/assign-marks',
        '/teacher/published-results',
        '/teacher/mark-attendance',
      ].includes(item.route));
    }

    return items;
  }

  logout() {
    this.auth.logout().subscribe({
      next: () => this.router.navigate(['/login']),
      error: () => {
        this.auth.clearSession();
        this.router.navigate(['/login']);
      }
    });
  }
}
