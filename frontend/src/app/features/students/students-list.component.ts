import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { ClassesService } from '../../core/services/classes.service';
import { SectionsService } from '../../core/services/sections.service';
import { StudentsService } from '../../core/services/students.service';
import { ClassModel } from '../../models/class';
import { Section } from '../../models/section';
import { Student } from '../../models/student';

@Component({
  selector: 'app-students-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './students-list.component.html',
  styleUrl: './students-list.component.scss'
})
export class StudentsListComponent {
  private readonly studentsService = inject(StudentsService);
  private readonly classesService = inject(ClassesService);
  private readonly sectionsService = inject(SectionsService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly downloadingStudentId = signal<number | null>(null);
  readonly students = signal<Student[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly pagination = signal({ current_page: 1, last_page: 1, total: 0 });
  readonly pageSizeOptions = [15, 25, 30, 50];

  readonly filters = this.fb.nonNullable.group({
    search: [''],
    status: [''],
    class_id: [''],
    section_id: [''],
    per_page: [15]
  });

  readonly filteredSections = computed(() => {
    const classId = Number(this.filters.controls.class_id.value || 0);

    return this.sections().filter((section) => !classId || Number(section.class_id) === classId);
  });

  ngOnInit() {
    this.loadFilterOptions();
    this.filters.controls.class_id.valueChanges.subscribe(() => {
      this.filters.patchValue({ section_id: '' }, { emitEvent: false });
      this.applyFilters();
    });
    this.filters.controls.status.valueChanges.subscribe(() => this.applyFilters());
    this.filters.controls.section_id.valueChanges.subscribe(() => this.applyFilters());
    this.filters.controls.per_page.valueChanges.subscribe(() => this.applyFilters());
    this.load();
  }

  load(page = 1) {
    this.loading.set(true);
    this.error.set(null);

    const { search, status, class_id, section_id, per_page } = this.filters.getRawValue();
    this.studentsService
      .list({
        search: search || undefined,
        status: status || undefined,
        class_id: class_id ? Number(class_id) : undefined,
        section_id: section_id ? Number(section_id) : undefined,
        per_page: Number(per_page) || 15,
        page
      })
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
      || (student as any)?.latestEnrollment?.section?.class?.name
      || (student as any)?.profile?.class?.name;
    const snakeClass = (student as any)?.current_enrollment?.section?.class?.name
      || (student as any)?.latest_enrollment?.section?.class?.name
      || (student as any)?.profile?.class?.name;
    return typedClass || snakeClass || '-';
  }

  getStudentSection(student: Student): string {
    const typedSection = (student as any)?.currentEnrollment?.section?.name;
    const typedLatestSection = (student as any)?.latestEnrollment?.section?.name;
    const snakeSection = (student as any)?.current_enrollment?.section?.name;
    const snakeLatestSection = (student as any)?.latest_enrollment?.section?.name;

    return typedSection || typedLatestSection || snakeSection || snakeLatestSection || '-';
  }

  downloadStudentPdf(student: Student) {
    if (this.downloadingStudentId() === student.id) {
      return;
    }

    this.error.set(null);
    this.downloadingStudentId.set(student.id);

    this.studentsService.downloadPdf(student.id).subscribe({
      next: (blob) => {
        this.saveBlob(blob, `student-${(student.admission_number || student.id).toString().replace(/\s+/g, '-')}.pdf`);
        this.downloadingStudentId.set(null);
      },
      error: (err) => {
        this.downloadingStudentId.set(null);
        this.error.set(err?.error?.message || 'Unable to download student PDF.');
      }
    });
  }

  private saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    URL.revokeObjectURL(url);
  }

  private loadFilterOptions(): void {
    forkJoin({
      classes: this.classesService.list({ status: 'active', per_page: 250 }),
      sections: this.sectionsService.list({ status: 'active', per_page: 400 })
    }).subscribe({
      next: ({ classes, sections }) => {
        this.classes.set(classes.data || []);
        this.sections.set(sections.data || []);
      },
      error: () => {
        this.error.set('Unable to load class and section filters.');
      }
    });
  }
}
