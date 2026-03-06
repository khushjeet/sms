import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { EnrollmentsService } from '../../core/services/enrollments.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { SectionsService } from '../../core/services/sections.service';
import { ClassesService } from '../../core/services/classes.service';
import { Enrollment } from '../../models/enrollment';
import { AcademicYear } from '../../models/academic-year';
import { Section } from '../../models/section';
import { ClassModel } from '../../models/class';
import { debounceTime, distinctUntilChanged, map } from 'rxjs/operators';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { merge } from 'rxjs';

@Component({
  selector: 'app-enrollments-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './enrollments-list.component.html',
  styleUrl: './enrollments-list.component.scss'
})
export class EnrollmentsListComponent {
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly sectionsService = inject(SectionsService);
  private readonly classesService = inject(ClassesService);
  private readonly fb = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly enrollments = signal<Enrollment[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly sectionsPagination = signal({ current_page: 1, last_page: 1, total: 0 });
  readonly pagination = signal({ current_page: 1, last_page: 1, total: 0 });

  readonly filters = this.fb.nonNullable.group({
    search: [''],
    academic_year_id: [''],
    class_id: [''],
    section_id: [''],
    section_search: [''],
    status: [''],
    student_id: [''],
    per_page: ['15'],
  });

  readonly filteredSections = computed(() => {
    const raw = this.filters.getRawValue();
    const selectedClassId = raw.class_id ? Number(raw.class_id) : null;
    const selectedAcademicYearId = raw.academic_year_id ? Number(raw.academic_year_id) : null;

    return this.sections().filter((section) => {
      const classMatch = !selectedClassId || section.class_id === selectedClassId;
      const yearMatch = !selectedAcademicYearId || section.academic_year_id === selectedAcademicYearId;
      return classMatch && yearMatch;
    });
  });

  ngOnInit() {
    this.loadReferenceData();
    this.bindRealtimeFilters();
    this.load(1);
  }

  loadReferenceData() {
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => this.academicYears.set(response.data)
    });
    this.classesService.list({ per_page: 200 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });
    this.loadSections(1);
  }

  bindRealtimeFilters() {
    merge(
      this.filters.controls.academic_year_id.valueChanges,
      this.filters.controls.class_id.valueChanges,
      this.filters.controls.section_search.valueChanges
    )
      .pipe(
        debounceTime(250),
        map(() => this.filters.getRawValue()),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => {
        this.filters.patchValue({ section_id: '' }, { emitEvent: false });
        this.loadSections(1);
      });

    merge(
      this.filters.controls.search.valueChanges,
      this.filters.controls.academic_year_id.valueChanges,
      this.filters.controls.class_id.valueChanges,
      this.filters.controls.section_id.valueChanges,
      this.filters.controls.status.valueChanges,
      this.filters.controls.student_id.valueChanges,
      this.filters.controls.per_page.valueChanges
    )
      .pipe(
        debounceTime(300),
        map(() => this.filters.getRawValue()),
        distinctUntilChanged((a, b) => JSON.stringify(a) === JSON.stringify(b)),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => this.load(1));
  }

  loadSections(page = 1) {
    const raw = this.filters.getRawValue();
    const classId = raw.class_id ? Number(raw.class_id) : undefined;
    const academicYearId = raw.academic_year_id ? Number(raw.academic_year_id) : undefined;
    const search = raw.section_search?.trim() ? raw.section_search.trim() : undefined;

    this.sectionsService
      .list({
        class_id: classId,
        academic_year_id: academicYearId,
        search,
        page,
        per_page: 25,
      })
      .subscribe({
        next: (response) => {
          this.sections.set(response.data);
          this.sectionsPagination.set({
            current_page: response.current_page,
            last_page: response.last_page,
            total: response.total,
          });
        },
      });
  }

  load(page = 1) {
    this.loading.set(true);
    this.error.set(null);

    const raw = this.filters.getRawValue();
    const perPage = raw.per_page ? Number(raw.per_page) : 15;
    this.enrollmentsService
      .list({
        academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
        class_id: raw.class_id ? Number(raw.class_id) : undefined,
        section_id: raw.section_id ? Number(raw.section_id) : undefined,
        status: raw.status || undefined,
        student_id: raw.student_id ? Number(raw.student_id) : undefined,
        search: raw.search?.trim() ? raw.search.trim() : undefined,
        page,
        per_page: perPage,
      })
      .subscribe({
        next: (response) => {
          this.enrollments.set(response.data);
          this.pagination.set({
            current_page: response.current_page,
            last_page: response.last_page,
            total: response.total
          });
          this.loading.set(false);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to load enrollments.');
        }
      });
  }

  applyFilters() {
    this.load(1);
  }

  onClassOrAcademicYearFilterChange() {
    this.filters.patchValue({ section_id: '' }, { emitEvent: false });
    this.loadSections(1);
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

  previousSectionsPage() {
    const page = this.sectionsPagination().current_page;
    if (page > 1) {
      this.loadSections(page - 1);
    }
  }

  nextSectionsPage() {
    const page = this.sectionsPagination().current_page;
    const last = this.sectionsPagination().last_page;
    if (page < last) {
      this.loadSections(page + 1);
    }
  }

  getStudentName(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    const fullName = enrollment.student?.user?.full_name
      || anyEnrollment?.student?.user?.full_name;
    if (fullName) {
      return fullName;
    }

    const firstName = enrollment.student?.user?.first_name
      || anyEnrollment?.student?.user?.first_name;
    const lastName = enrollment.student?.user?.last_name
      || anyEnrollment?.student?.user?.last_name;

    const joined = [firstName, lastName].filter(Boolean).join(' ').trim();
    return joined || '-';
  }

  getAcademicYearName(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    return enrollment.academicYear?.name
      || anyEnrollment?.academic_year?.name
      || '-';
  }

  getClassSection(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    const classFromEnrollment = enrollment.classModel?.name
      || anyEnrollment?.class_model?.name
      || anyEnrollment?.classModel?.name;
    const className = enrollment.section?.class?.name
      || anyEnrollment?.section?.class?.name
      || classFromEnrollment
      || '-';
    const sectionName = enrollment.section?.name
      || anyEnrollment?.section?.name
      || '-';

    return `${className} / ${sectionName}`;
  }
}
