import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { AuthService } from '../../core/services/auth.service';
import { ClassesService } from '../../core/services/classes.service';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';
import { ExamConfiguration } from '../../models/exam-configuration';
import { Subject } from '../../models/subject';

@Component({
  selector: 'app-subject-detail',
  standalone: true,
  imports: [RouterLink, ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './subject-detail.component.html',
  styleUrl: './subject-detail.component.scss'
})
export class SubjectDetailComponent {
  private readonly subjectsService = inject(SubjectsService);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly subject = signal<Subject | null>(null);
  readonly classes = signal<ClassModel[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly examConfigurations = signal<ExamConfiguration[]>([]);
  readonly canManage = computed(() => ['super_admin', 'school_admin'].includes(this.auth.user()?.role || ''));

  readonly yearNameMap = computed(() => {
    const map = new Map<number, string>();
    for (const year of this.academicYears()) {
      map.set(year.id, year.name);
    }
    return map;
  });

  readonly form = this.fb.nonNullable.group({
    class_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    academic_year_exam_config_id: ['', Validators.required],
    max_marks: [100, [Validators.required, Validators.min(1), Validators.max(1000)]],
    pass_marks: [35, [Validators.required, Validators.min(0), Validators.max(1000)]],
    is_mandatory: [true, Validators.required]
  });

  ngOnInit() {
    const subjectId = Number(this.route.snapshot.paramMap.get('id'));
    if (!subjectId) {
      this.error.set('Invalid subject id.');
      this.loading.set(false);
      return;
    }

    this.loadData(subjectId);
  }

  loadData(subjectId: number) {
    this.loading.set(true);
    this.error.set(null);
    this.success.set(null);

    forkJoin({
      subject: this.subjectsService.getById(subjectId),
      classes: this.classesService.list({ status: 'active', per_page: 250 }),
      academicYears: this.academicYearsService.list({ per_page: 250 })
    }).subscribe({
      next: ({ subject, classes, academicYears }) => {
        this.subject.set(subject);
        this.classes.set(classes.data || []);
        this.academicYears.set(academicYears.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load subject details.');
      }
    });
  }

  beginEditMapping(mapping: {
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
    this.success.set('Loaded mapping in form. Update values and click Save Mapping.');
  }

  onAcademicYearChange(academicYearIdRaw: string) {
    this.form.patchValue({ academic_year_id: academicYearIdRaw, academic_year_exam_config_id: '' });
    const academicYearId = Number(academicYearIdRaw);
    if (!academicYearId) {
      this.examConfigurations.set([]);
      return;
    }

    this.loadExamConfigurations(academicYearId);
  }

  saveMapping() {
    const subject = this.subject();
    if (!subject || this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const maxMarks = Number(raw.max_marks);
    const passMarks = Number(raw.pass_marks);

    if (passMarks > maxMarks) {
      this.error.set('Pass marks cannot be greater than max marks.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService
      .upsertClassMapping(subject.id, {
        class_id: Number(raw.class_id),
        academic_year_id: Number(raw.academic_year_id),
        academic_year_exam_config_id: Number(raw.academic_year_exam_config_id),
        max_marks: maxMarks,
        pass_marks: passMarks,
        is_mandatory: Boolean(raw.is_mandatory)
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.success.set('Mapping saved successfully.');
          this.loadData(subject.id);
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(err?.error?.message || 'Unable to save mapping.');
        }
      });
  }

  removeMapping(classId: number, academicYearId: number) {
    const subject = this.subject();
    if (!subject || this.saving()) {
      return;
    }

    if (!confirm('Remove this class mapping?')) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService.removeClassMapping(subject.id, classId, academicYearId).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Mapping removed successfully.');
        this.loadData(subject.id);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to remove mapping.');
      }
    });
  }

  yearLabel(yearId: number): string {
    return this.yearNameMap().get(yearId) || `Year #${yearId}`;
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
