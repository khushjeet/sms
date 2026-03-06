import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ClassesService } from '../../core/services/classes.service';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';
import { ExamConfiguration } from '../../models/exam-configuration';
import { Subject } from '../../models/subject';

@Component({
  selector: 'app-subject-assignments',
  standalone: true,
  imports: [RouterLink, ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './subject-assignments.component.html',
  styleUrl: './subject-assignments.component.scss'
})
export class SubjectAssignmentsComponent {
  private readonly subjectsService = inject(SubjectsService);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly subjects = signal<Subject[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly examConfigurations = signal<ExamConfiguration[]>([]);
  readonly selectedSubject = signal<Subject | null>(null);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);

  readonly yearNameMap = computed(() => {
    const map = new Map<number, string>();
    for (const year of this.academicYears()) {
      map.set(year.id, year.name);
    }
    return map;
  });

  readonly form = this.fb.nonNullable.group({
    subject_id: ['', Validators.required],
    class_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    academic_year_exam_config_id: ['', Validators.required],
    max_marks: [100, [Validators.required, Validators.min(1), Validators.max(1000)]],
    pass_marks: [35, [Validators.required, Validators.min(0), Validators.max(1000)]],
    is_mandatory: [true, Validators.required]
  });

  ngOnInit() {
    this.loadBootstrapData();
  }

  loadBootstrapData() {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      subjects: this.subjectsService.list({ per_page: 300, status: 'active' }),
      classes: this.classesService.list({ status: 'active', per_page: 250 }),
      academicYears: this.academicYearsService.list({ per_page: 250 })
    }).subscribe({
      next: ({ subjects, classes, academicYears }) => {
        this.subjects.set(subjects.data || []);
        this.classes.set(classes.data || []);
        this.academicYears.set(academicYears.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load assignment master data.');
      }
    });
  }

  onSubjectChange(subjectIdValue: string) {
    this.form.patchValue({ subject_id: subjectIdValue });
    const subjectId = Number(subjectIdValue);
    if (!subjectId) {
      this.selectedSubject.set(null);
      return;
    }

    this.loading.set(true);
    this.subjectsService.getById(subjectId).subscribe({
      next: (subject) => {
        this.selectedSubject.set(subject);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load subject details.');
      }
    });
  }

  onAcademicYearChange(academicYearIdValue: string) {
    this.form.patchValue({ academic_year_id: academicYearIdValue, academic_year_exam_config_id: '' });
    const academicYearId = Number(academicYearIdValue);
    if (!academicYearId) {
      this.examConfigurations.set([]);
      return;
    }

    this.loadExamConfigurations(academicYearId);
  }

  saveAssignment() {
    const raw = this.form.getRawValue();
    const subjectId = Number(raw.subject_id);

    if (!subjectId || this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    if (Number(raw.pass_marks) > Number(raw.max_marks)) {
      this.error.set('Pass marks cannot be greater than max marks.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService
      .upsertClassMapping(subjectId, {
        class_id: Number(raw.class_id),
        academic_year_id: Number(raw.academic_year_id),
        academic_year_exam_config_id: Number(raw.academic_year_exam_config_id),
        max_marks: Number(raw.max_marks),
        pass_marks: Number(raw.pass_marks),
        is_mandatory: Boolean(raw.is_mandatory)
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.success.set('Subject class assignment saved.');
          this.onSubjectChange(String(subjectId));
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(err?.error?.message || 'Unable to save assignment.');
        }
      });
  }

  editAssignment(mapping: {
    id: number;
    pivot: {
      academic_year_id: number;
      academic_year_exam_config_id?: number | null;
      max_marks: number;
      pass_marks: number;
      is_mandatory: number | boolean;
    };
  }) {
    const academicYearId = Number(mapping.pivot.academic_year_id);
    this.form.patchValue({
      class_id: String(mapping.id),
      academic_year_id: String(academicYearId),
      academic_year_exam_config_id: mapping.pivot.academic_year_exam_config_id ? String(mapping.pivot.academic_year_exam_config_id) : '',
      max_marks: Number(mapping.pivot.max_marks),
      pass_marks: Number(mapping.pivot.pass_marks),
      is_mandatory: Boolean(mapping.pivot.is_mandatory)
    });
    this.loadExamConfigurations(academicYearId);
    this.success.set('Assignment loaded in form. Update and save.');
  }

  removeAssignment(classId: number, academicYearId: number) {
    const subject = this.selectedSubject();
    if (!subject || this.saving()) {
      return;
    }

    if (!confirm('Remove this subject class assignment?')) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService.removeClassMapping(subject.id, classId, academicYearId).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Assignment removed.');
        this.onSubjectChange(String(subject.id));
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to remove assignment.');
      }
    });
  }

  yearLabel(id: number): string {
    return this.yearNameMap().get(id) || `Year #${id}`;
  }

  private loadExamConfigurations(academicYearId: number): void {
    this.examConfigurations.set([]);
    this.examConfigurationsService.list({ academic_year_id: academicYearId }).subscribe({
      next: (response) => {
        this.examConfigurations.set(response.data || []);
      },
      error: (err) => {
        this.examConfigurations.set([]);
        this.error.set(err?.error?.message || 'Unable to load exam configurations for selected year.');
      }
    });
  }
}
