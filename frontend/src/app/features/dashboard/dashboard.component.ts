import { DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../core/services/auth.service';
import { ClassesService } from '../../core/services/classes.service';
import { EmployeesService } from '../../core/services/employees.service';
import { ExpensesService } from '../../core/services/expenses.service';
import { SelfAttendanceService } from '../../core/services/self-attendance.service';
import { EmailSystemStatus, SchoolDetailsService } from '../../core/services/school-details.service';
import { NotificationsService } from '../../core/services/notifications.service';
import { StudentsService } from '../../core/services/students.service';
import { StudentDashboardService } from '../../core/services/student-dashboard.service';
import { StudentThemeService } from '../../core/services/student-theme.service';
import { StudentDashboardResponse, StudentDashboardYearOption } from '../../models/student-dashboard';
import { AppNotification } from '../../models/notification';
import { SelfAttendanceStatusResponse } from '../../models/self-attendance';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule, DecimalPipe, RouterLink],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss'
})
export class DashboardComponent {
  private readonly auth = inject(AuthService);
  private readonly studentDashboardService = inject(StudentDashboardService);
  private readonly studentThemeService = inject(StudentThemeService);
  private readonly selfAttendanceService = inject(SelfAttendanceService);
  private readonly studentsService = inject(StudentsService);
  private readonly employeesService = inject(EmployeesService);
  private readonly classesService = inject(ClassesService);
  private readonly expensesService = inject(ExpensesService);
  private readonly schoolDetailsService = inject(SchoolDetailsService);
  private readonly notificationsService = inject(NotificationsService);
  private readonly fb = inject(FormBuilder);
  private mediaStream: MediaStream | null = null;
  private capturedBlob: Blob | null = null;

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly data = signal<StudentDashboardResponse | null>(null);
  readonly notifications = signal<AppNotification[]>([]);
  readonly selfAttendance = signal<SelfAttendanceStatusResponse | null>(null);
  readonly cameraError = signal<string | null>(null);
  readonly cameraReady = signal(false);
  readonly capturePreview = signal<string | null>(null);
  readonly actionBusy = signal(false);
  readonly viewerImage = signal<string | null>(null);
  readonly overviewStats = signal({
    students: 0,
    employees: 0,
    classes: 0,
    expenses: 0,
  });
  readonly emailHealth = signal<EmailSystemStatus | null>(null);

  readonly filters = this.fb.nonNullable.group({
    academic_year_id: [''],
    month: [new Date().toISOString().slice(0, 7)]
  });

  readonly isStudent = computed(() => this.auth.user()?.role === 'student');
  readonly role = computed(() => this.auth.user()?.role ?? '');
  readonly widgetMap = computed(() => this.data()?.widgets ?? {});
  readonly yearOptions = computed<StudentDashboardYearOption[]>(() => this.data()?.academic_year_options ?? []);
  readonly schoolStageLabel = computed(() => {
    const totalStudents = this.overviewStats().students;
    if (totalStudents >= 1500) {
      return 'Enterprise-ready for large campuses';
    }
    if (totalStudents >= 500) {
      return 'Ready for growing multi-section schools';
    }
    if (totalStudents > 0) {
      return 'Ready for day-to-day school operations';
    }

    return 'Ready to onboard your next academic year';
  });
  readonly emailHealthAlert = computed(() => {
    const health = this.emailHealth();
    return health && health.status !== 'healthy' ? health : null;
  });
  readonly overviewHighlights = computed(() => {
    const stats = this.overviewStats();

    return [
      {
        label: 'Student Records',
        value: stats.students,
        tone: 'blue',
        caption: 'Admissions, profiles, history, and portal access'
      },
      {
        label: 'Team Strength',
        value: stats.employees,
        tone: 'amber',
        caption: 'Employees, teachers, HR operations, and payroll'
      },
      {
        label: 'Academic Structure',
        value: stats.classes,
        tone: 'green',
        caption: 'Classes, sections, timetable, and subject planning'
      },
      {
        label: 'Expense Entries',
        value: stats.expenses,
        tone: 'slate',
        caption: 'Controlled spending, receipts, and audits'
      },
    ];
  });
  readonly spotlightCards = computed(() => {
    const stats = this.overviewStats();
    const notificationCount = this.notifications().length;
    const selfAttendance = this.selfAttendance();

    return [
      {
        title: 'Admissions + Academic Journey',
        value: `${stats.students} students`,
        description: 'Create records, manage enrollments, and show year-wise academic history in one flow.',
        route: '/students',
        cta: 'Open Students'
      },
      {
        title: 'Parent Trust + Student Portal',
        value: `${notificationCount} live notices`,
        description: 'Demo fee, result, admit card, timetable, and attendance from the student side.',
        route: '/dashboard',
        cta: 'Show Portal'
      },
      {
        title: 'Operational Discipline',
        value: selfAttendance?.session?.review_status ? selfAttendance.session.review_status : 'Live',
        description: 'Attendance lock, selfie proof, payroll finalization, and auditable workflows.',
        route: '/hr-payroll',
        cta: 'Open HR Payroll'
      }
    ];
  });
  readonly quickActions = computed(() => {
    const role = this.role();

    if (role === 'teacher') {
      return [
        { label: 'Mark Attendance', route: '/teacher/mark-attendance', caption: 'Take attendance quickly' },
        { label: 'Assign Marks', route: '/teacher/assign-marks', caption: 'Enter subject-wise marks' },
        { label: 'My Timetable', route: '/teacher/timetable', caption: 'Show weekly teaching plan' },
        { label: 'Published Results', route: '/teacher/published-results', caption: 'Download result papers' },
      ];
    }

    if (role === 'accountant') {
      return [
        { label: 'Collect Fee', route: '/finance', caption: 'Assignments, receipts, and ledgers' },
        { label: 'Expenses', route: '/expenses', caption: 'Track and verify spending' },
        { label: 'HR Payroll', route: '/hr-payroll', caption: 'Generate and finalize payroll' },
        { label: 'Receipts & Audit', route: '/admin/audit-downloads', caption: 'Show download and checksum trail' },
      ];
    }

    return [
      { label: 'New Admission', route: '/students/new', caption: 'Create a fresh student profile' },
      { label: 'Collect Fee', route: '/finance', caption: 'Show receipts, dues, and ledger controls' },
      { label: 'Publish Result', route: '/admin/published-results', caption: 'Reveal result publishing workflow' },
      { label: 'Admit Cards', route: '/admin/admit-cards', caption: 'Generate and print exam cards' },
      { label: 'Send Message', route: '/admin/send-message', caption: 'Parents, students, and scheduled mailers' },
      { label: 'Generate Payroll', route: '/hr-payroll', caption: 'Attendance-backed salary snapshot demo' },
    ];
  });
  readonly demoMoments = computed(() => {
    const attendance = this.selfAttendance();
    const hasCameraFlow = !!attendance?.can_mark || !!attendance?.session;

    return [
      {
        title: 'Single student, complete digital journey',
        detail: 'Admission to result to fee to timetable, all visible from one profile.'
      },
      {
        title: 'Parent confidence',
        detail: 'Receipts, portal visibility, printable artifacts, and audit-friendly records.'
      },
      {
        title: 'Principal control',
        detail: hasCameraFlow
          ? 'Live attendance and approval workflow ready to demonstrate.'
          : 'Admin oversight across academics, finance, and HR from one place.'
      }
    ];
  });
  readonly studentQuickInsight = computed(() => {
    const vm = this.data();
    if (!vm) {
      return [];
    }

    return [
      { label: 'Attendance', value: `${vm.quick_stats.attendance_percent.toFixed(2)}%`, caption: 'Current month performance' },
      { label: 'Pending Fee', value: vm.quick_stats.pending_fee.toFixed(2), caption: 'Live fee position' },
      { label: 'Upcoming Exam', value: vm.quick_stats.upcoming_exam || 'N/A', caption: 'Academic readiness' },
      { label: 'Assignments Due', value: String(vm.quick_stats.assignments_due), caption: 'Action items this week' },
    ];
  });

  @ViewChild('cameraVideo') cameraVideo?: ElementRef<HTMLVideoElement>;
  @ViewChild('cameraCanvas') cameraCanvas?: ElementRef<HTMLCanvasElement>;

  ngOnInit() {
    if (this.isStudent()) {
      this.loadStudentDashboard();
      return;
    }

    this.loadOverviewStats();
    this.loadNotifications();
    this.loadSelfAttendanceStatus();
  }

  ngOnDestroy() {
    this.stopCamera();
    this.revokePreview();
  }

  reload() {
    if (!this.isStudent()) {
      return;
    }

    this.loadStudentDashboard();
  }

  widgetEnabled(key: string): boolean {
    const widget = this.widgetMap()?.[key];
    return !!widget?.enabled;
  }

  toggleStudentTheme() {
    this.studentThemeService.toggleTheme();
  }

  isStudentDarkMode(): boolean {
    return this.studentThemeService.isDark();
  }

  loadNotifications() {
    this.notificationsService.fetchRecent(6).subscribe({
      next: (response) => this.notifications.set(response.data ?? this.notificationsService.recentItems()),
      error: (err) => this.error.set(err?.error?.message || 'Unable to load notifications.')
    });
  }

  loadOverviewStats() {
    forkJoin({
      students: this.studentsService.list({ per_page: 1 }),
      employees: this.employeesService.list({ per_page: 1 }),
      classes: this.classesService.list({ per_page: 1 }),
      expenses: this.expensesService.list({ per_page: 1 }),
      emailHealth: this.schoolDetailsService.getEmailHealth(),
    }).subscribe({
      next: ({ students, employees, classes, expenses, emailHealth }) => {
        this.overviewStats.set({
          students: students.total ?? 0,
          employees: employees.total ?? 0,
          classes: classes.total ?? 0,
          expenses: expenses.data?.total ?? 0,
        });
        this.emailHealth.set(emailHealth);
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Unable to load dashboard overview.');
      }
    });
  }

  loadSelfAttendanceStatus() {
    this.selfAttendanceService.status().subscribe({
      next: (response) => this.selfAttendance.set(response),
      error: (err) => this.error.set(err?.error?.message || 'Unable to load self-attendance status.')
    });
  }

  async startCamera() {
    this.cameraError.set(null);

    if (!navigator.mediaDevices?.getUserMedia) {
      this.cameraError.set('Camera is not supported in this browser.');
      return;
    }

    try {
      this.stopCamera();
      this.mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user' },
        audio: false
      });

      const video = this.cameraVideo?.nativeElement;
      if (!video) {
        return;
      }
      video.srcObject = this.mediaStream;
      await video.play();
      this.cameraReady.set(true);
    } catch {
      this.cameraError.set('Unable to open camera. Please allow camera access.');
      this.cameraReady.set(false);
    }
  }

  captureSelfie() {
    this.cameraError.set(null);
    const video = this.cameraVideo?.nativeElement;
    const canvas = this.cameraCanvas?.nativeElement;
    if (!video || !canvas || !this.mediaStream) {
      this.cameraError.set('Start camera before capturing selfie.');
      return;
    }

    const width = video.videoWidth || 640;
    const height = video.videoHeight || 480;
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      this.cameraError.set('Unable to capture image frame.');
      return;
    }
    ctx.drawImage(video, 0, 0, width, height);
    canvas.toBlob((blob) => {
      if (!blob) {
        this.cameraError.set('Unable to capture selfie image.');
        return;
      }
      this.capturedBlob = blob;
      this.revokePreview();
      this.capturePreview.set(URL.createObjectURL(blob));
    }, 'image/jpeg', 0.9);
  }

  clearCapturedSelfie() {
    this.capturedBlob = null;
    this.revokePreview();
  }

  async markSelfAttendance(punchType: 'in' | 'out') {
    if (this.actionBusy()) {
      return;
    }
    if (!this.capturedBlob) {
      this.cameraError.set('Capture selfie before marking attendance.');
      return;
    }

    this.actionBusy.set(true);
    this.error.set(null);
    this.cameraError.set(null);

    try {
      const location = await this.resolveLocation();
      const file = new File([this.capturedBlob], `selfie-${Date.now()}.jpg`, { type: 'image/jpeg' });
      const payload = new FormData();
      payload.append('punch_type', punchType);
      payload.append('selfie', file);
      payload.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
      if (location?.latitude !== undefined) {
        payload.append('latitude', String(location.latitude));
      }
      if (location?.longitude !== undefined) {
        payload.append('longitude', String(location.longitude));
      }
      if (location?.accuracy !== undefined) {
        payload.append('location_accuracy_meters', String(Math.round(location.accuracy)));
      }

      this.selfAttendanceService.markAttendance(payload).subscribe({
        next: (response) => {
          this.error.set(null);
          this.cameraError.set(response.message);
          this.clearCapturedSelfie();
          this.loadSelfAttendanceStatus();
          this.loadNotifications();
          this.actionBusy.set(false);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save self attendance.');
          this.actionBusy.set(false);
        },
      });
    } catch {
      this.actionBusy.set(false);
      this.error.set('Location access failed. Please allow location and try again.');
    }
  }

  openViewer(imageUrl: string | null) {
    if (!imageUrl) {
      return;
    }
    this.viewerImage.set(imageUrl);
  }

  closeViewer() {
    this.viewerImage.set(null);
  }

  attendanceProgressWidth(): number {
    const percent = this.data()?.attendance_overview?.monthly_percentage ?? this.data()?.quick_stats.attendance_percent ?? 0;
    return Math.min(100, Math.max(0, percent));
  }

  feeProgressWidth(): number {
    const fee = this.data()?.fee_summary;
    if (!fee || fee.total_fee <= 0) {
      return 0;
    }

    return Math.min(100, Math.max(0, (fee.paid_amount / fee.total_fee) * 100));
  }

  private async resolveLocation(): Promise<{ latitude: number; longitude: number; accuracy: number } | null> {
    if (!navigator.geolocation) {
      return null;
    }

    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy
          });
        },
        () => reject(new Error('Location permission denied')),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
      );
    });
  }

  private loadStudentDashboard() {
    const raw = this.filters.getRawValue();
    const academicYearId = raw.academic_year_id ? Number(raw.academic_year_id) : undefined;

    this.loading.set(true);
    this.error.set(null);

    this.studentDashboardService
      .getDashboard({
        academic_year_id: academicYearId,
        month: raw.month || undefined
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.data.set(response);

          const scopedYearId = response.scope?.academic_year_id;
          if (scopedYearId && !raw.academic_year_id) {
            this.filters.patchValue({ academic_year_id: String(scopedYearId) }, { emitEvent: false });
          }
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load student dashboard.');
        }
      });
  }

  private stopCamera() {
    this.mediaStream?.getTracks().forEach((track) => track.stop());
    this.mediaStream = null;
    const video = this.cameraVideo?.nativeElement;
    if (video) {
      video.srcObject = null;
    }
    this.cameraReady.set(false);
  }

  private revokePreview() {
    const currentPreview = this.capturePreview();
    if (currentPreview) {
      URL.revokeObjectURL(currentPreview);
    }
    this.capturePreview.set(null);
  }
}
