import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { StudentsService } from '../../core/services/students.service';
import { Student } from '../../models/student';
import { environment } from '../../../environments/environment';
import { downloadStudentPdfFile, SchoolPrintDetails } from './student-pdf-template';

@Component({
  selector: 'app-students-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './students-list.component.html',
  styleUrl: './students-list.component.scss'
})
export class StudentsListComponent {
  private readonly studentsService = inject(StudentsService);
  private readonly fb = inject(FormBuilder);
  private readonly printDateFormatter = new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' });
  private readonly apiOrigin = new URL(environment.apiBaseUrl).origin;
  private readonly schoolDetails: SchoolPrintDetails = {
    name: 'INDIAN PUBLIC SCHOOL',
    address: 'Naugawa Chowk, Yogapatti - 845452',
    phone: '9771782335, 9931482335',
    email: 'info@ipsyogapatti.com',
    website: 'https://ipsyogapatti.com',
    logoUrl: `http://127.0.0.1:8000/storage/assets/ips.png`
  };

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly downloadingStudentId = signal<number | null>(null);
  readonly students = signal<Student[]>([]);
  readonly pagination = signal({ current_page: 1, last_page: 1, total: 0 });

  readonly filters = this.fb.nonNullable.group({
    search: [''],
    status: ['']
  });

  ngOnInit() {
    this.load();
  }

  load(page = 1) {
    this.loading.set(true);
    this.error.set(null);

    const { search, status } = this.filters.getRawValue();
    this.studentsService
      .list({ search: search || undefined, status: status || undefined, page })
      .subscribe({
        next: (response) => {
          this.students.set(response.data);
          this.pagination.set({
            current_page: response.current_page,
            last_page: response.last_page,
            total: response.total
          });
          this.loading.set(false);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to load students.');
        }
      });
  }

  applyFilters() {
    this.load(1);
  }

  previousPage() {
    const page = this.pagination().current_page;
    if (page > 1) {
      this.load(page - 1);
    }
  }

  nextPage() {
    const page = this.pagination().current_page;
    const last = this.pagination().last_page;
    if (page < last) {
      this.load(page + 1);
    }
  }

  getStudentName(student: Student): string {
    const user = student.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }

  getStudentClass(student: Student): string {
    const typedClass = (student as any)?.currentEnrollment?.section?.class?.name
      || (student as any)?.profile?.class?.name;
    const snakeClass = (student as any)?.current_enrollment?.section?.class?.name
      || (student as any)?.profile?.class?.name;
    return typedClass || snakeClass || '-';
  }

  downloadStudentPdf(student: Student) {
    this.error.set(null);
    this.downloadingStudentId.set(student.id);

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
          this.downloadingStudentId.set(null);
        }
      },
      error: (err) => {
        this.downloadingStudentId.set(null);
        this.error.set(err?.error?.message || 'Unable to download student PDF.');
      }
    });
  }

  private avatarUrl(student: Student): string | null {
    const avatar = student.avatar_url || student.profile?.avatar_url || student.user?.avatar;
    if (!avatar) {
      return null;
    }
    if (avatar.startsWith('http://') || avatar.startsWith('https://')) {
      return avatar;
    }
    return `${this.apiOrigin}/storage/${avatar.replace(/^\/+/, '')}`;
  }
}
