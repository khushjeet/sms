import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf, NgFor } from '@angular/common';
import { EnrollmentsService } from '../../core/services/enrollments.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { SectionsService } from '../../core/services/sections.service';
import { ClassesService } from '../../core/services/classes.service';
import { StudentsService } from '../../core/services/students.service';
import { AcademicYear } from '../../models/academic-year';
import { Section } from '../../models/section';
import { ClassModel } from '../../models/class';
import { Student } from '../../models/student';
import { debounceTime, distinctUntilChanged, map } from 'rxjs/operators';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

@Component({
  selector: 'app-enrollment-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './enrollment-form.component.html',
  styleUrl: './enrollment-form.component.scss'
})
export class EnrollmentFormComponent {
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly sectionsService = inject(SectionsService);
  private readonly classesService = inject(ClassesService);
  private readonly studentsService = inject(StudentsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);
  private readonly destroyRef = inject(DestroyRef);

  readonly loading = signal(false);
  readonly submitting = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  private enrollmentId?: number;

  readonly academicYears = signal<AcademicYear[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly students = signal<Student[]>([]);
  readonly studentsPagination = signal({ current_page: 1, last_page: 1, total: 0 });
  readonly sectionsPagination = signal({ current_page: 1, last_page: 1, total: 0 });

  readonly studentSearch = this.fb.nonNullable.control('');
  readonly sectionSearch = this.fb.nonNullable.control('');

  readonly form = this.fb.nonNullable.group({
    student_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    class_id: [''],
    section_id: [''],
    roll_number: [''],
    enrollment_date: ['', Validators.required],
    remarks: ['']
  });

  readonly filteredSections = computed(() => {
    const raw = this.form.getRawValue();
    const academicYearId = raw.academic_year_id;
    const classId = raw.class_id;

    return this.sections().filter((section) => {
      const yearMatch = !academicYearId || section.academic_year_id === Number(academicYearId);
      const classMatch = !classId || section.class_id === Number(classId);
      return yearMatch && classMatch;
    });
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.enrollmentId = id;
      this.disableEditFields();
      this.loadEnrollment(id);
    }

    this.loadReferenceData();
    this.bindRealtimePickers();
    this.loadStudents(1);
    this.loadSections(1);
  }

  disableEditFields() {
    ['student_id', 'academic_year_id', 'class_id', 'enrollment_date'].forEach((field) => this.form.get(field)?.disable());
  }

  loadReferenceData() {
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => this.academicYears.set(response.data)
    });
    this.classesService.list({ per_page: 200 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });
  }

  bindRealtimePickers() {
    this.studentSearch.valueChanges
      .pipe(
        debounceTime(250),
        map((v) => (v ?? '').toString().trim()),
        distinctUntilChanged(),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => this.loadStudents(1));

    this.sectionSearch.valueChanges
      .pipe(
        debounceTime(250),
        map((v) => (v ?? '').toString().trim()),
        distinctUntilChanged(),
        takeUntilDestroyed(this.destroyRef)
      )
      .subscribe(() => this.loadSections(1));

    this.form.controls.academic_year_id.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => {
        this.form.get('section_id')?.setValue('');
        this.loadSections(1);
      });

    this.form.controls.class_id.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => {
        this.form.get('section_id')?.setValue('');
        this.loadSections(1);
      });
  }

  loadStudents(page = 1) {
    const search = this.studentSearch.value?.trim() || undefined;
    this.studentsService.list({ search, per_page: 25, page }).subscribe({
      next: (response) => {
        this.students.set(response.data);
        this.studentsPagination.set({
          current_page: response.current_page,
          last_page: response.last_page,
          total: response.total,
        });
      }
    });
  }

  loadSections(page = 1) {
    const raw = this.form.getRawValue();
    const academicYearId = raw.academic_year_id ? Number(raw.academic_year_id) : undefined;
    const classId = raw.class_id ? Number(raw.class_id) : undefined;
    const search = this.sectionSearch.value?.trim() || undefined;

    this.sectionsService
      .list({
        academic_year_id: academicYearId,
        class_id: classId,
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
        }
      });
  }

  selectStudent(id: number) {
    this.form.get('student_id')?.setValue(id.toString());
  }

  getStudentLabel(student: Student): string {
    const anyStudent = student as any;
    const user = student.user || anyStudent?.user;
    const fullName = user?.full_name;
    if (typeof fullName === 'string' && fullName.trim().length > 0) {
      return fullName;
    }
    const firstName = user?.first_name ?? '';
    const lastName = user?.last_name ?? '';
    const joined = [firstName, lastName].filter(Boolean).join(' ').trim();
    return joined || `Student #${student.id}`;
  }

  onClassOrAcademicYearChange() {
    this.form.get('section_id')?.setValue('');
    this.loadSections(1);
  }

  previousStudentsPage() {
    const page = this.studentsPagination().current_page;
    if (page > 1) {
      this.loadStudents(page - 1);
    }
  }

  nextStudentsPage() {
    const page = this.studentsPagination().current_page;
    const last = this.studentsPagination().last_page;
    if (page < last) {
      this.loadStudents(page + 1);
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

  loadEnrollment(id: number) {
    this.loading.set(true);
    this.enrollmentsService.getById(id).subscribe({
      next: (enrollment) => {
        const anyEnrollment = enrollment as any;
        const mappedClassId = enrollment.class_id
          || anyEnrollment?.class_id
          || anyEnrollment?.class_model?.id;
        const classId = enrollment.section?.class?.id
          || enrollment.section?.class_id
          || anyEnrollment?.section?.class_id
          || mappedClassId
          || '';

        this.form.patchValue({
          student_id: String(enrollment.student_id),
          academic_year_id: String(enrollment.academic_year_id),
          class_id: classId ? String(classId) : '',
          section_id: enrollment.section_id ? String(enrollment.section_id) : '',
          roll_number: enrollment.roll_number ? String(enrollment.roll_number) : '',
          enrollment_date: enrollment.enrollment_date,
          remarks: enrollment.remarks ?? ''
        });

        const admissionNumber = enrollment.student?.admission_number
          || anyEnrollment?.student?.admission_number
          || '';
        if (admissionNumber) {
          this.studentSearch.setValue(String(admissionNumber), { emitEvent: false });
          this.loadStudents(1);
        } else if (enrollment.student_id) {
          this.studentSearch.setValue(String(enrollment.student_id), { emitEvent: false });
          this.loadStudents(1);
        }

        this.loadSections(1);

        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load enrollment.');
      }
    });
  }

  submit() {
    if (this.form.invalid || this.loading() || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.error.set(null);

    const raw = this.form.getRawValue();
    const classId = raw.class_id ? Number(raw.class_id) : null;
    const sectionId = raw.section_id ? Number(raw.section_id) : null;
    const rollNumber = raw.roll_number ? Number(raw.roll_number) : null;
    const remarks = raw.remarks || undefined;

    if (this.isEdit() && this.enrollmentId) {
      const updatePayload = {
        class_id: classId,
        section_id: sectionId,
        roll_number: rollNumber,
        remarks
      };
      this.enrollmentsService.update(this.enrollmentId, updatePayload).subscribe({
        next: () => {
          this.submitting.set(false);
          this.router.navigate(['/enrollments', this.enrollmentId]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.error.set(this.getApiError(err, 'Unable to update enrollment.'));
        }
      });
      return;
    }

    const payload = {
      student_id: Number(raw.student_id),
      academic_year_id: Number(raw.academic_year_id),
      class_id: classId,
      section_id: sectionId,
      roll_number: rollNumber,
      enrollment_date: raw.enrollment_date,
      remarks
    };

    this.enrollmentsService.create(payload).subscribe({
      next: (response) => {
        this.submitting.set(false);
        this.router.navigate(['/enrollments', response.data.id]);
      },
      error: (err) => {
        this.submitting.set(false);
        this.error.set(this.getApiError(err, 'Unable to create enrollment.'));
      }
    });
  }

  private getApiError(err: any, fallback: string): string {
    const directMessage = err?.error?.message;
    if (typeof directMessage === 'string' && directMessage.trim().length > 0) {
      return directMessage;
    }

    const internalError = err?.error?.error;
    if (typeof internalError === 'string' && internalError.trim().length > 0) {
      return internalError;
    }

    const validationErrors = err?.error?.errors;
    if (validationErrors && typeof validationErrors === 'object') {
      const firstKey = Object.keys(validationErrors)[0];
      const firstValue = Array.isArray(validationErrors[firstKey]) ? validationErrors[firstKey][0] : validationErrors[firstKey];
      if (typeof firstValue === 'string' && firstValue.trim().length > 0) {
        return firstValue;
      }
    }

    return fallback;
  }
}
