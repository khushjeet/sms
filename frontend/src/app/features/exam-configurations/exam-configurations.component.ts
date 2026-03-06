import { NgFor, NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { AcademicYear } from '../../models/academic-year';
import { ExamConfiguration } from '../../models/exam-configuration';

@Component({
  selector: 'app-exam-configurations',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './exam-configurations.component.html',
  styleUrl: './exam-configurations.component.scss'
})
export class ExamConfigurationsComponent {
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);

  readonly academicYears = signal<AcademicYear[]>([]);
  readonly exams = signal<ExamConfiguration[]>([]);
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly selectedAcademicYearId = signal<string>('');
  readonly newExamName = signal('');
  readonly newExamSequence = signal<string>('');
  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);

  ngOnInit() {
    this.loadAcademicYears();
  }

  loadAcademicYears() {
    this.loading.set(true);
    this.academicYearsService.list({ per_page: 200 }).subscribe({
      next: (response) => {
        const years = response.data || [];
        this.academicYears.set(years);
        if (years.length > 0) {
          const current = years.find((year) => year.is_current) ?? years[0];
          this.selectedAcademicYearId.set(String(current.id));
          this.loadExamConfigurations();
        } else {
          this.exams.set([]);
        }
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load academic years.');
      }
    });
  }

  onAcademicYearChange(value: string) {
    this.selectedAcademicYearId.set(value);
    this.message.set(null);
    this.error.set(null);
    this.exams.set([]);
    this.loadExamConfigurations();
  }

  loadExamConfigurations() {
    const academicYearId = Number(this.selectedAcademicYearId());
    if (!academicYearId) {
      this.exams.set([]);
      return;
    }

    this.loading.set(true);
    this.examConfigurationsService.list({ academic_year_id: academicYearId }).subscribe({
      next: (response) => {
        this.exams.set(response.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load exam configurations.');
      }
    });
  }

  addExam() {
    const academicYearId = Number(this.selectedAcademicYearId());
    const name = this.newExamName().trim();
    const sequence = this.newExamSequence() ? Number(this.newExamSequence()) : undefined;

    if (!academicYearId) {
      this.error.set('Select academic year first.');
      return;
    }
    if (!name) {
      this.error.set('Enter exam name.');
      return;
    }
    if (sequence !== undefined && (!Number.isFinite(sequence) || sequence < 1)) {
      this.error.set('Sequence must be a positive number.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.examConfigurationsService.create({
      academic_year_id: academicYearId,
      name,
      sequence
    }).subscribe({
      next: (response) => {
        this.saving.set(false);
        this.message.set(response.message || 'Exam configuration created.');
        this.newExamName.set('');
        this.newExamSequence.set('');
        this.loadExamConfigurations();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to create exam configuration.');
      }
    });
  }

  toggleActive(exam: ExamConfiguration) {
    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.examConfigurationsService.update(exam.id, { is_active: !exam.is_active }).subscribe({
      next: (response) => {
        this.saving.set(false);
        this.message.set(response.message || 'Exam configuration updated.');
        this.loadExamConfigurations();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to update exam configuration.');
      }
    });
  }

  deleteExam(exam: ExamConfiguration) {
    if (!confirm(`Delete exam "${exam.name}"?`)) {
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.examConfigurationsService.delete(exam.id).subscribe({
      next: (response) => {
        this.saving.set(false);
        this.message.set(response.message || 'Exam configuration deleted.');
        this.loadExamConfigurations();
      },
      error: (err) => {
        this.saving.set(false);
        this.error.set(err?.error?.message || 'Unable to delete exam configuration.');
      }
    });
  }
}

