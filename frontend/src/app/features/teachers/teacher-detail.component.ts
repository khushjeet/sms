import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { NgFor, NgIf } from '@angular/common';
import { Teacher, TeacherDocument } from '../../models/teacher';
import { TeachersService } from '../../core/services/teachers.service';

@Component({
  selector: 'app-teacher-detail',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink],
  templateUrl: './teacher-detail.component.html',
  styleUrl: './teacher-detail.component.scss'
})
export class TeacherDetailComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly teachersService = inject(TeachersService);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly downloadingDocumentId = signal<number | null>(null);
  readonly teacher = signal<Teacher | null>(null);

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      this.loading.set(false);
      this.error.set('Invalid teacher id.');
      return;
    }

    this.teachersService.getById(id).subscribe({
      next: (teacher) => {
        this.teacher.set(teacher);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load teacher.');
      }
    });
  }

  deleteTeacher() {
    const teacher = this.teacher();
    if (!teacher) {
      return;
    }
    if (!confirm('Archive this teacher profile?')) {
      return;
    }

    this.teachersService.delete(teacher.id).subscribe({
      next: () => this.router.navigate(['/teachers']),
      error: (err) => this.error.set(err?.error?.message || 'Unable to archive teacher.')
    });
  }

  downloadDocument(doc: TeacherDocument) {
    const teacher = this.teacher();
    if (!teacher) {
      return;
    }
    this.downloadingDocumentId.set(doc.id);
    this.error.set(null);

    this.teachersService.downloadDocument(teacher.id, doc.id).subscribe({
      next: (blob) => {
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = doc.original_name || doc.file_name;
        a.click();
        URL.revokeObjectURL(objectUrl);
        this.downloadingDocumentId.set(null);
      },
      error: (err) => {
        this.downloadingDocumentId.set(null);
        this.error.set(err?.error?.message || 'Unable to download document.');
      }
    });
  }

  fullName(teacher: Teacher): string {
    const user = teacher.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }
}
