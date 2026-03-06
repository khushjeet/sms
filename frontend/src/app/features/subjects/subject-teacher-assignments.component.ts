import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { SectionsService } from '../../core/services/sections.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { TeachersService } from '../../core/services/teachers.service';
import { AcademicYear } from '../../models/academic-year';
import { ExamConfiguration } from '../../models/exam-configuration';
import { Section } from '../../models/section';
import { Subject, SubjectTeacherAssignment } from '../../models/subject';
import { Teacher } from '../../models/teacher';

@Component({
  selector: 'app-subject-teacher-assignments',
  standalone: true,
  imports: [RouterLink, ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './subject-teacher-assignments.component.html',
  styleUrl: './subject-teacher-assignments.component.scss'
})
export class SubjectTeacherAssignmentsComponent {
  private readonly subjectsService = inject(SubjectsService);
  private readonly teachersService = inject(TeachersService);
  private readonly sectionsService = inject(SectionsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);

  readonly subjects = signal<Subject[]>([]);
  readonly teachers = signal<Teacher[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly examConfigurations = signal<ExamConfiguration[]>([]);
  readonly selectedSubject = signal<Subject | null>(null);
  readonly assignments = signal<SubjectTeacherAssignment[]>([]);
  readonly teachingStaff = computed(() => this.teachers().filter((row) => row.employee_type === 'teaching'));

  readonly filteredSections = computed(() => {
    const yearId = Number(this.form.controls.academic_year_id.value || 0);
    if (!yearId) {
      return this.sections();
    }
    return this.sections().filter((section) => Number(section.academic_year_id) === yearId);
  });

  readonly form = this.fb.nonNullable.group({
    subject_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    academic_year_exam_config_id: ['', Validators.required],
    section_id: ['', Validators.required],
    teacher_ids: this.fb.nonNullable.control<string[]>([], [Validators.required]),
  });

  ngOnInit() {
    this.loadBootstrapData();
  }

  loadBootstrapData() {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      subjects: this.subjectsService.list({ per_page: 300, status: 'active' }),
      teachers: this.teachersService.list({ per_page: 300, status: 'active' }),
      sections: this.sectionsService.list({ per_page: 400, status: 'active' }),
      academicYears: this.academicYearsService.list({ per_page: 200 })
    }).subscribe({
      next: ({ subjects, teachers, sections, academicYears }) => {
        this.subjects.set(subjects.data || []);
        this.teachers.set(teachers.data || []);
        this.sections.set(sections.data || []);
        this.academicYears.set(academicYears.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load subject-teacher assignment data.');
      }
    });
  }

  onSubjectChange(subjectIdValue: string) {
    this.form.patchValue({ subject_id: subjectIdValue });
    const subjectId = Number(subjectIdValue);
    if (!subjectId) {
      this.selectedSubject.set(null);
      this.assignments.set([]);
      return;
    }

    this.loading.set(true);
    this.subjectsService.getById(subjectId).subscribe({
      next: (subject) => {
        this.selectedSubject.set(subject);
        this.loadAssignments(subject.id);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load subject details.');
      }
    });
  }

  saveAssignments() {
    if (this.form.invalid || this.saving()) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const subjectId = Number(raw.subject_id);
    const teacherIds = (raw.teacher_ids || []).map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0);

    if (!subjectId || teacherIds.length === 0) {
      this.error.set('Select a subject and at least one teacher.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService.assignTeachers(subjectId, {
      teacher_ids: teacherIds,
      section_id: Number(raw.section_id),
      academic_year_id: Number(raw.academic_year_id),
      academic_year_exam_config_id: Number(raw.academic_year_exam_config_id),
    }).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Teacher assignment saved. One subject can now have multiple teachers.');
        this.form.patchValue({ teacher_ids: [] });
        this.loadAssignments(subjectId);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to save teacher assignment.');
      }
    });
  }

  removeAssignment(assignmentId: number) {
    const subject = this.selectedSubject();
    if (!subject || this.saving()) {
      return;
    }

    if (!confirm('Remove this teacher assignment?')) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.success.set(null);

    this.subjectsService.removeTeacherAssignment(subject.id, assignmentId).subscribe({
      next: () => {
        this.saving.set(false);
        this.success.set('Teacher assignment removed.');
        this.loadAssignments(subject.id);
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to remove teacher assignment.');
      }
    });
  }

  teacherLabel(row: Teacher): string {
    const fullName = row.user?.full_name || `${row.user?.first_name ?? ''} ${row.user?.last_name ?? ''}`.trim();
    return `${row.employee_id} - ${fullName || row.user?.email || 'Teacher #' + row.user_id}`;
  }

  sectionLabel(row: Section): string {
    const className = row.class?.name || 'Class';
    return `${className} - ${row.name}`;
  }

  onAcademicYearChange(academicYearIdValue: string) {
    this.form.patchValue({
      academic_year_id: academicYearIdValue,
      academic_year_exam_config_id: '',
      section_id: '',
    });

    const academicYearId = Number(academicYearIdValue);
    if (!academicYearId) {
      this.examConfigurations.set([]);
      return;
    }

    this.loadExamConfigurations(academicYearId);
  }

  private loadAssignments(subjectId: number) {
    this.subjectsService.listTeacherAssignments(subjectId).subscribe({
      next: (rows) => {
        this.assignments.set(rows || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load teacher assignments.');
      }
    });
  }

  private loadExamConfigurations(academicYearId: number) {
    this.examConfigurations.set([]);
    this.examConfigurationsService.list({ academic_year_id: academicYearId }).subscribe({
      next: (response) => {
        this.examConfigurations.set(response.data || []);
      },
      error: (err) => {
        this.examConfigurations.set([]);
        this.error.set(err?.error?.message || 'Unable to load exam configurations.');
      }
    });
  }
}
