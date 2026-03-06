import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { StudentsService } from '../../core/services/students.service';
import { Student, StudentFinancialSummary } from '../../models/student';
import { environment } from '../../../environments/environment';
import { downloadStudentPdfFile, SchoolPrintDetails } from './student-pdf-template';

@Component({
  selector: 'app-student-detail',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor],
  templateUrl: './student-detail.component.html',
  styleUrl: './student-detail.component.scss'
})
export class StudentDetailComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly studentsService = inject(StudentsService);
  private readonly printDateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
  private readonly uiDateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
  private readonly apiOrigin = new URL(environment.apiBaseUrl).origin;
  private readonly schoolDetails: SchoolPrintDetails = {
    name: 'INDIAN PUBLIC SCHOOL',
    address: 'Naugawa Chowk, Yogapatti - 845452',
    phone: '9771782335, 9931482335',
    email: 'info@ipsyogapatti.com',
    website: 'https://ipsyogapatti.com',
    logoUrl: `http://127.0.0.1:8000/storage/assets/ips.png`
  };

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly downloadingPdf = signal(false);
  readonly student = signal<Student | null>(null);
  readonly financialSummary = signal<StudentFinancialSummary | null>(null);
  readonly academicHistory = signal<AcademicHistoryRow[]>([]);
  readonly newPassword = signal('');
  readonly confirmPassword = signal('');
  readonly showNewPassword = signal(false);
  readonly showConfirmPassword = signal(false);
  readonly updatingPassword = signal(false);
  readonly passwordMessage = signal<string | null>(null);

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      this.error.set('Invalid student id.');
      this.loading.set(false);
      return;
    }

    this.studentsService.getById(id).subscribe({
      next: (student) => {
        this.student.set(student);
        this.loading.set(false);
      },
      error: () => {
        this.error.set('Unable to load student.');
        this.loading.set(false);
      }
    });

    this.studentsService.financialSummary(id).subscribe({
      next: (summary) => this.financialSummary.set(summary)
    });

    this.studentsService.academicHistory(id).subscribe({
      next: (history) => {
        const rows = (Array.isArray(history) ? history : []) as AcademicHistoryRow[];
        rows.sort((a, b) => {
          const aTime = Date.parse(a.enrollment_date || '');
          const bTime = Date.parse(b.enrollment_date || '');
          return (isNaN(bTime) ? 0 : bTime) - (isNaN(aTime) ? 0 : aTime);
        });
        this.academicHistory.set(rows);
      }
    });
  }

  deleteStudent() {
    const student = this.student();
    if (!student) {
      return;
    }
    if (!confirm('Are you sure you want to delete this student?')) {
      return;
    }
    this.studentsService.delete(student.id).subscribe({
      next: () => this.router.navigate(['/students']),
      error: () => this.error.set('Unable to delete student.')
    });
  }

  updatePassword() {
    const student = this.student();
    if (!student || this.updatingPassword()) {
      return;
    }

    this.passwordMessage.set(null);
    this.error.set(null);

    const password = this.newPassword().trim();
    const confirmation = this.confirmPassword().trim();

    if (!password) {
      this.passwordMessage.set('Please enter a new password.');
      return;
    }
    if (password.length < 8) {
      this.passwordMessage.set('Password must be at least 8 characters.');
      return;
    }
    if (password !== confirmation) {
      this.passwordMessage.set('Password confirmation does not match.');
      return;
    }

    this.updatingPassword.set(true);
    this.studentsService.update(student.id, {
      password,
      password_confirmation: confirmation
    }).subscribe({
      next: () => {
        this.updatingPassword.set(false);
        this.newPassword.set('');
        this.confirmPassword.set('');
        this.passwordMessage.set('Password updated successfully.');
      },
      error: (err) => {
        this.updatingPassword.set(false);
        this.passwordMessage.set(err?.error?.message || 'Unable to update password.');
      }
    });
  }

  downloadStudentPdf() {
    const student = this.student();
    if (!student) {
      return;
    }

    this.error.set(null);
    this.downloadingPdf.set(true);

    this.studentsService.getById(student.id).subscribe({
      next: async (fullStudent) => {
        try {
          await downloadStudentPdfFile({
            student: fullStudent,
            school: this.schoolDetails,
            generatedOn: this.printDateFormatter.format(new Date()),
            avatarUrl: this.avatarUrl(fullStudent)
          });
        } catch {
          this.error.set('Unable to render student image in PDF.');
        } finally {
          this.downloadingPdf.set(false);
        }
      },
      error: (err) => {
        this.downloadingPdf.set(false);
        this.error.set(err?.error?.message || 'Unable to download student PDF.');
      }
    });
  }

  avatarUrl(student: Student): string | null {
    const avatar = student.avatar_url || student.profile?.avatar_url || student.user?.avatar;
    if (!avatar) {
      return null;
    }
    if (avatar.startsWith('http://') || avatar.startsWith('https://')) {
      return avatar;
    }
    return `${this.apiOrigin}/storage/${avatar.replace(/^\/+/, '')}`;
  }

  formatHistoryDate(value?: string | null): string {
    if (!value) {
      return '-';
    }
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
      return '-';
    }
    return this.uiDateFormatter.format(parsed);
  }

  academicYearName(row: AcademicHistoryRow): string {
    return row.academic_year?.name || '-';
  }

  className(row: AcademicHistoryRow): string {
    const sectionClass = row.section?.class?.name;
    if (sectionClass) {
      return sectionClass;
    }
    if (row.class_id) {
      return `Class ${row.class_id}`;
    }
    return '-';
  }

  sectionName(row: AcademicHistoryRow): string {
    return row.section?.name || '-';
  }

  attendanceCount(row: AcademicHistoryRow): number {
    return Array.isArray(row.attendances) ? row.attendances.length : 0;
  }
}

interface AcademicHistoryRow {
  id: number;
  class_id?: number | null;
  roll_number?: number | string | null;
  enrollment_date?: string | null;
  status?: string | null;
  is_locked?: boolean | null;
  promoted_from_enrollment_id?: number | null;
  remarks?: string | null;
  academic_year?: {
    id: number;
    name: string;
  } | null;
  section?: {
    id?: number;
    name?: string | null;
    class?: {
      id?: number;
      name?: string | null;
    } | null;
  } | null;
  attendances?: unknown[] | null;
}
