import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TeacherAcademicsService } from '../../core/services/teacher-academics.service';
import { TeacherAssignment, TeacherMarksRow } from '../../models/teacher-academic';

@Component({
  selector: 'app-teacher-assign-marks',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './teacher-assign-marks.component.html'
})
export class TeacherAssignMarksComponent {
  private readonly teacherAcademics = inject(TeacherAcademicsService);

  readonly assignments = signal<TeacherAssignment[]>([]);
  readonly rows = signal<TeacherMarksRow[]>([]);
  readonly assignmentId = signal<string>('');
  readonly examConfigurationId = signal<string>('');
  readonly markedOn = signal<string>(new Date().toISOString().slice(0, 10));
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly selectedAssignment = computed(() => this.assignments().find((item) => String(item.id) === this.assignmentId()) ?? null);

  ngOnInit() {
    this.loading.set(true);
    this.teacherAcademics.listAssignments().subscribe({
      next: (data) => {
        this.assignments.set(data || []);
        if (data.length > 0) {
          this.onAssignmentChange(String(data[0].id));
        } else {
          this.loading.set(false);
        }
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load assigned subjects.');
      }
    });
  }

  onAssignmentChange(assignmentIdRaw: string) {
    this.assignmentId.set(assignmentIdRaw);
    this.rows.set([]);
    this.error.set(null);
    this.message.set(null);

    const assignment = this.assignments().find((item) => String(item.id) === assignmentIdRaw);
    const mappedExamConfigId = Number(assignment?.mapped_exam_configuration_id || 0);
    this.examConfigurationId.set(mappedExamConfigId ? String(mappedExamConfigId) : '');
    if (!mappedExamConfigId) {
      this.error.set('Exam configuration is not mapped in Subject Class Assignment for this subject.');
      return;
    }

    this.loadSheet();
  }

  loadSheet() {
    const assignmentId = Number(this.assignmentId());
    const examConfigurationId = Number(this.examConfigurationId());
    if (!assignmentId || !examConfigurationId) {
      this.error.set('Select allotted subject with mapped exam configuration.');
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.teacherAcademics
      .getMarksSheet({ assignment_id: assignmentId, marked_on: this.markedOn(), exam_configuration_id: examConfigurationId })
      .subscribe({
      next: (response) => {
        this.rows.set(response.rows || []);
        this.markedOn.set(response.marked_on || this.markedOn());
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load marks sheet.');
      }
    });
  }

  save() {
    const assignmentId = Number(this.assignmentId());
    const examConfigurationId = Number(this.examConfigurationId());
    if (!assignmentId || !this.markedOn() || !examConfigurationId) {
      this.error.set('Select allotted subject with mapped exam configuration, and date.');
      return;
    }

    const marks = this.rows().map((row) => ({
      enrollment_id: row.enrollment_id,
      marks_obtained: row.marks_obtained ?? null,
      max_marks: row.max_marks ?? null,
      remarks: row.remarks || undefined
    }));

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.teacherAcademics
      .saveMarks({ assignment_id: assignmentId, marked_on: this.markedOn(), exam_configuration_id: examConfigurationId, marks })
      .subscribe({
        next: (response) => {
          this.saving.set(false);
          this.message.set(response.message || 'Marks saved.');
          this.loadSheet();
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(err?.error?.message || 'Unable to save marks.');
        }
      });
  }

  onRowChange() {
    this.rows.set([...this.rows()]);
  }

  parseNullableNumber(value: unknown): number | null {
    if (value === '' || value === null || value === undefined) {
      return null;
    }

    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }
}
