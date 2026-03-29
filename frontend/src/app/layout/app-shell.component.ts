import { DatePipe, NgFor, NgIf } from '@angular/common';
import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { timer } from 'rxjs';
import { AuthService } from '../core/services/auth.service';
import { NotificationsService } from '../core/services/notifications.service';
import { AppNotification } from '../models/notification';

interface NavItem {
  label: string;
  route: string;
  group?: string;
  roles?: string[];
}

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, NgFor, NgIf, DatePipe],
  templateUrl: './app-shell.component.html',
  styleUrl: './app-shell.component.scss'
})
export class AppShellComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly notificationsService = inject(NotificationsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly user = computed(() => this.auth.user());
  readonly notificationMenuOpen = signal(false);
  readonly notificationError = signal<string | null>(null);
  readonly unreadCount = computed(() => this.notificationsService.unreadCount().total ?? 0);
  readonly recentNotifications = computed(() => this.notificationsService.recentItems());

  readonly navItems: NavItem[] = [
    { label: 'Dashboard', route: '/dashboard', group: 'Overview' },
    { label: 'Notifications', route: '/notifications', group: 'Overview' },
    { label: 'Admit Card', route: '/student/admit-card', group: 'Student Portal', roles: ['student'] },
    { label: 'Fee', route: '/student/fee', group: 'Student Portal', roles: ['student'] },
    { label: 'Result', route: '/student/result', group: 'Student Portal', roles: ['student'] },
    { label: 'Timetable', route: '/student/timetable', group: 'Student Portal', roles: ['student'] },
    { label: 'Academic History', route: '/student/academic-history', group: 'Student Portal', roles: ['student'] },
    { label: 'Attendance History', route: '/student/attendance-history', group: 'Student Portal', roles: ['student'] },
    { label: 'Result', route: '/parent/result', group: 'Parent Portal', roles: ['parent'] },
    { label: 'Students', route: '/students', group: 'Admissions', roles: ['super_admin', 'school_admin'] },
    { label: 'Enrollments', route: '/enrollments', group: 'Admissions', roles: ['super_admin', 'school_admin'] },
    { label: 'Academic Years', route: '/academic-years', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Classes', route: '/classes', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Sections', route: '/sections', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Subjects', route: '/subjects', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Class Subject Assign', route: '/subjects/assignments', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Subject Teacher Assign', route: '/subjects/teacher-assignments', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Attendance', route: '/attendance', group: 'Academics', roles: ['super_admin', 'school_admin'] },
    { label: 'Timetable', route: '/admin/timetable', group: 'Academics', roles: ['super_admin'] },
    { label: 'Exam Configuration', route: '/exam-configurations', group: 'Exam Control', roles: ['super_admin'] },
    { label: 'Assign Marks', route: '/admin/assign-marks', group: 'Exam Control', roles: ['super_admin'] },
    { label: 'Admit Cards', route: '/admin/admit-cards', group: 'Exam Control', roles: ['super_admin'] },
    { label: 'Published Result', route: '/admin/published-results', group: 'Exam Control', roles: ['super_admin'] },
    { label: 'Manage Employees', route: '/employees', group: 'People & HR', roles: ['super_admin', 'school_admin'] },
    { label: 'Teachers', route: '/teachers', group: 'People & HR', roles: ['super_admin', 'school_admin'] },
    { label: 'HR Payroll', route: '/hr-payroll', group: 'People & HR', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'Allotted Subjects', route: '/subjects', group: 'Teaching', roles: ['teacher'] },
    { label: 'Assign Marks', route: '/teacher/assign-marks', group: 'Teaching', roles: ['teacher'] },
    { label: 'Download Results', route: '/teacher/published-results', group: 'Teaching', roles: ['teacher'] },
    { label: 'Mark Attendance', route: '/teacher/mark-attendance', group: 'Teaching', roles: ['teacher'] },
    { label: 'Assigned Timetable', route: '/teacher/timetable', group: 'Teaching', roles: ['teacher'] },
    { label: 'Finance', route: '/finance', group: 'Finance & Ops', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'Expenses', route: '/expenses', group: 'Finance & Ops', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'Signature Upload', route: '/signature-upload', group: 'Finance & Ops', roles: ['super_admin', 'school_admin', 'accountant'] },
    { label: 'Events', route: '/admin/events', group: 'Finance & Ops', roles: ['super_admin'] },
    { label: 'Credentials', route: '/credentials', group: 'Admin Tools', roles: ['super_admin'] },
    { label: 'Send Message', route: '/admin/send-message', group: 'Admin Tools', roles: ['super_admin'] },
    { label: 'Audit & Downloads', route: '/admin/audit-downloads', group: 'Admin Tools', roles: ['super_admin'] },
    { label: 'Phase 2', route: '/admin/phase-2', group: 'Admin Tools', roles: ['super_admin'] }
  ];
  readonly demoShortcuts = computed(() => {
    const role = this.user()?.role;

    if (role === 'student') {
      return [
        { label: 'Portal', route: '/dashboard' },
        { label: 'Fee', route: '/student/fee' },
        { label: 'Result', route: '/student/result' },
      ];
    }

    if (role === 'teacher') {
      return [
        { label: 'Attendance', route: '/teacher/mark-attendance' },
        { label: 'Marks', route: '/teacher/assign-marks' },
        { label: 'Timetable', route: '/teacher/timetable' },
      ];
    }

    if (role === 'parent') {
      return [
        { label: 'Portal', route: '/dashboard' },
        { label: 'Result', route: '/parent/result' },
      ];
    }

    return [
      { label: 'Admissions', route: '/students' },
      { label: 'Finance', route: '/finance' },
      { label: 'Portal Demo', route: '/dashboard' },
    ];
  });
  readonly visibleItems = computed(() => {
    const role = this.user()?.role;
    const items = this.navItems.filter((item) => !item.roles || (role && item.roles.includes(role)));

    if (role === 'teacher') {
      return items.filter((item) => [
        '/dashboard',
        '/notifications',
        '/subjects',
        '/teacher/assign-marks',
        '/teacher/published-results',
        '/teacher/mark-attendance',
        '/teacher/timetable',
      ].includes(item.route));
    }

    return items;
  });

  readonly groupedVisibleItems = computed(() => {
    const groups = new Map<string, NavItem[]>();
    for (const item of this.visibleItems()) {
      const key = item.group || 'General';
      const current = groups.get(key) ?? [];
      current.push(item);
      groups.set(key, current);
    }

    return Array.from(groups.entries()).map(([label, items]) => ({ label, items }));
  });

  constructor() {
    timer(0, 20000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => {
        this.notificationsService.fetchUnreadCount().subscribe({
          error: (err) => this.notificationError.set(err?.error?.message || 'Unable to refresh notification count.')
        });
        this.notificationsService.fetchRecent(6).subscribe({
          error: () => undefined
        });
      });
  }

  toggleNotificationMenu() {
    this.notificationMenuOpen.update((open) => !open);
  }

  openNotificationsPage() {
    this.notificationMenuOpen.set(false);
    this.router.navigate(['/notifications']);
  }

  openNotification(item: AppNotification) {
    const navigate = () => {
      this.notificationMenuOpen.set(false);
      this.router.navigateByUrl(item.action_target || '/notifications');
    };

    if (item.is_read) {
      navigate();
      return;
    }

    this.notificationsService.markRead(item.id).subscribe({
      next: () => navigate(),
      error: () => navigate()
    });
  }

  markAllNotificationsRead() {
    this.notificationsService.markAllRead().subscribe({
      next: () => this.notificationMenuOpen.set(false),
      error: (err) => this.notificationError.set(err?.error?.message || 'Unable to mark notifications as read.')
    });
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
