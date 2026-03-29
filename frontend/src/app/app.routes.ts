import { Routes } from '@angular/router';
import { LoginComponent } from './features/auth/login.component';
import { PasswordResetComponent } from './features/auth/password-reset.component';
import { AppShellComponent } from './layout/app-shell.component';
import { DashboardComponent } from './features/dashboard/dashboard.component';
import { authGuard } from './core/guards/auth.guard';
import { roleGuard } from './core/guards/role.guard';
import { UnauthorizedComponent } from './layout/unauthorized.component';
import { NotFoundComponent } from './layout/not-found.component';
import { StudentsListComponent } from './features/students/students-list.component';
import { StudentDetailComponent } from './features/students/student-detail.component';
import { StudentFormComponent } from './features/students/student-form.component';
import { EnrollmentsListComponent } from './features/enrollments/enrollments-list.component';
import { EnrollmentDetailComponent } from './features/enrollments/enrollment-detail.component';
import { EnrollmentFormComponent } from './features/enrollments/enrollment-form.component';
import { AcademicYearsListComponent } from './features/academic-years/academic-years-list.component';
import { AcademicYearFormComponent } from './features/academic-years/academic-year-form.component';
import { ExamConfigurationsComponent } from './features/exam-configurations/exam-configurations.component';
import { ClassesListComponent } from './features/classes/classes-list.component';
import { ClassFormComponent } from './features/classes/class-form.component';
import { SectionsListComponent } from './features/sections/sections-list.component';
import { SectionFormComponent } from './features/sections/section-form.component';
import { SubjectsListComponent } from './features/subjects/subjects-list.component';
import { SubjectFormComponent } from './features/subjects/subject-form.component';
import { SubjectDetailComponent } from './features/subjects/subject-detail.component';
import { SubjectAssignmentsComponent } from './features/subjects/subject-assignments.component';
import { SubjectTeacherAssignmentsComponent } from './features/subjects/subject-teacher-assignments.component';
import { FinanceComponent } from './features/finance/finance.component';
import { HrPayrollComponent } from './features/hr-payroll/hr-payroll.component';
import { AttendanceComponent } from './features/attendance/attendance.component';
import { ExpensesComponent } from './features/expenses/expenses.component';
import { SignatureUploadComponent } from './features/signature-upload/signature-upload.component';
import { TeachersListComponent } from './features/teachers/teachers-list.component';
import { TeacherDetailComponent } from './features/teachers/teacher-detail.component';
import { TeacherFormComponent } from './features/teachers/teacher-form.component';
import { EmployeesListComponent } from './features/employees/employees-list.component';
import { EmployeeDetailComponent } from './features/employees/employee-detail.component';
import { EmployeeFormComponent } from './features/employees/employee-form.component';
import { TeacherAssignMarksComponent } from './features/teacher-academics/teacher-assign-marks.component';
import { TeacherMarkAttendanceComponent } from './features/teacher-academics/teacher-mark-attendance.component';
import { TeacherAssignedTimetableComponent } from './features/teacher-academics/teacher-assigned-timetable.component';
import { AdminAssignMarksComponent } from './features/admin-marks/admin-assign-marks.component';
import { PublishedResultsComponent } from './features/results/published-results.component';
import { AdmitManagementComponent } from './features/admits/admit-management.component';
import { StudentPortalSectionComponent } from './features/student-portal/student-portal-section.component';
import { TimetableManagementComponent } from './features/timetable/timetable-management.component';
import { CredentialsComponent } from './features/credentials/credentials.component';
import { MessageCenterComponent } from './features/message-center/message-center.component';
import { AuditDownloadsComponent } from './features/audit-downloads/audit-downloads.component';
import { PhaseTwoComponent } from './features/phase-two/phase-two.component';
import { EventsComponent } from './features/events/events.component';
import { NotificationsPageComponent } from './features/notifications/notifications-page.component';

export const routes: Routes = [
  { path: 'login', component: LoginComponent },
  { path: 'password-reset/:token', component: PasswordResetComponent },
  {
    path: '',
    component: AppShellComponent,
    canActivate: [authGuard],
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
      { path: 'dashboard', component: DashboardComponent },
      {
        path: 'students',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: StudentsListComponent },
          {
            path: 'new',
            component: StudentFormComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          },
          { path: ':id', component: StudentDetailComponent },
          {
            path: ':id/edit',
            component: StudentFormComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          }
        ]
      },
      {
        path: 'enrollments',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: EnrollmentsListComponent },
          { path: 'new', component: EnrollmentFormComponent },
          { path: ':id', component: EnrollmentDetailComponent },
          { path: ':id/edit', component: EnrollmentFormComponent }
        ]
      },
      {
        path: 'employees',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: EmployeesListComponent },
          { path: 'new', component: EmployeeFormComponent },
          { path: ':id', component: EmployeeDetailComponent },
          { path: ':id/edit', component: EmployeeFormComponent }
        ]
      },
      {
        path: 'teachers',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: TeachersListComponent },
          { path: 'new', component: TeacherFormComponent },
          { path: ':id', component: TeacherDetailComponent },
          { path: ':id/edit', component: TeacherFormComponent }
        ]
      },
      {
        path: 'attendance',
        component: AttendanceComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] }
      },
      {
        path: 'academic-years',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: AcademicYearsListComponent },
          { path: 'new', component: AcademicYearFormComponent },
          { path: ':id/edit', component: AcademicYearFormComponent }
        ]
      },
      {
        path: 'exam-configurations',
        component: ExamConfigurationsComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'classes',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: ClassesListComponent },
          { path: 'new', component: ClassFormComponent },
          { path: ':id/edit', component: ClassFormComponent }
        ]
      },
      {
        path: 'sections',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin'] },
        children: [
          { path: '', component: SectionsListComponent },
          { path: 'new', component: SectionFormComponent },
          { path: ':id/edit', component: SectionFormComponent }
        ]
      },
      {
        path: 'subjects',
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin', 'teacher'] },
        children: [
          { path: '', component: SubjectsListComponent },
          {
            path: 'new',
            component: SubjectFormComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          },
          {
            path: 'assignments',
            component: SubjectAssignmentsComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          },
          {
            path: 'teacher-assignments',
            component: SubjectTeacherAssignmentsComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          },
          {
            path: ':id/edit',
            component: SubjectFormComponent,
            canActivate: [roleGuard],
            data: { roles: ['super_admin', 'school_admin'] }
          },
          { path: ':id', component: SubjectDetailComponent }
        ]
      },
      {
        path: 'admin/assign-marks',
        component: AdminAssignMarksComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/published-results',
        component: PublishedResultsComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/admit-cards',
        component: AdmitManagementComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/timetable',
        component: TimetableManagementComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/send-message',
        component: MessageCenterComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/audit-downloads',
        component: AuditDownloadsComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/phase-2',
        component: PhaseTwoComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'admin/events',
        component: EventsComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'teacher/assign-marks',
        component: TeacherAssignMarksComponent,
        canActivate: [roleGuard],
        data: { roles: ['teacher'] }
      },
      {
        path: 'teacher/published-results',
        component: PublishedResultsComponent,
        canActivate: [roleGuard],
        data: { roles: ['teacher'] }
      },
      {
        path: 'teacher/mark-attendance',
        component: TeacherMarkAttendanceComponent,
        canActivate: [roleGuard],
        data: { roles: ['teacher'] }
      },
      {
        path: 'teacher/timetable',
        component: TeacherAssignedTimetableComponent,
        canActivate: [roleGuard],
        data: { roles: ['teacher'] }
      },
      {
        path: 'finance',
        component: FinanceComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin', 'accountant'] }
      },
      {
        path: 'hr-payroll',
        component: HrPayrollComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin', 'accountant'] }
      },
      {
        path: 'expenses',
        component: ExpensesComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin', 'accountant'] }
      },
      {
        path: 'signature-upload',
        component: SignatureUploadComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin', 'school_admin', 'accountant'] }
      },
      {
        path: 'credentials',
        component: CredentialsComponent,
        canActivate: [roleGuard],
        data: { roles: ['super_admin'] }
      },
      {
        path: 'notifications',
        component: NotificationsPageComponent
      },
      {
        path: 'student/admit-card',
        component: AdmitManagementComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'] }
      },
      {
        path: 'student/fee',
        component: StudentPortalSectionComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'], title: 'Fee', section: 'fee' }
      },
      {
        path: 'student/result',
        component: PublishedResultsComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'] }
      },
      {
        path: 'parent/result',
        component: PublishedResultsComponent,
        canActivate: [roleGuard],
        data: { roles: ['parent'] }
      },
      {
        path: 'student/timetable',
        component: StudentPortalSectionComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'], title: 'Timetable', section: 'timetable' }
      },
      {
        path: 'student/academic-history',
        component: StudentPortalSectionComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'], title: 'Academic History', section: 'academic-history' }
      },
      {
        path: 'student/attendance-history',
        component: StudentPortalSectionComponent,
        canActivate: [roleGuard],
        data: { roles: ['student'], title: 'Attendance History', section: 'attendance-history' }
      },
      { path: 'unauthorized', component: UnauthorizedComponent },
      { path: '**', component: NotFoundComponent }
    ]
  }
];
