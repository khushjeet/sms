import { NgFor, NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TeacherAcademicsService } from '../../core/services/teacher-academics.service';
import { TeacherAssignment, TeacherAttendanceRow } from '../../models/teacher-academic';

@Component({
  selector: 'app-teacher-mark-attendance',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './teacher-mark-attendance.component.html'
})
export class TeacherMarkAttendanceComponent {
  private readonly teacherAcademics = inject(TeacherAcademicsService);

  readonly assignments = signal<TeacherAssignment[]>([]);
  readonly rows = signal<TeacherAttendanceRow[]>([]);
  readonly assignmentId = signal<string>('');
  readonly date = signal<string>(new Date().toISOString().slice(0, 10));
  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  ngOnInit() {
    this.loading.set(true);
    this.teacherAcademics.listAssignments().subscribe({
      next: (data) => {
        this.assignments.set(data || []);
        if (data.length > 0) {
          this.assignmentId.set(String(data[0].id));
          this.loadSheet();
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

  loadSheet() {
    const assignmentId = Number(this.assignmentId());
    if (!assignmentId || !this.date()) {
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.teacherAcademics.getAttendanceSheet({ assignment_id: assignmentId, date: this.date() }).subscribe({
      next: (rows) => {
        this.rows.set(rows);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load attendance sheet.');
      }
    });
  }

  onStatusChange(row: TeacherAttendanceRow, status: string) {
    row.status = status as TeacherAttendanceRow['status'];
    this.rows.set([...this.rows()]);
  }

  save() {
    const assignmentId = Number(this.assignmentId());
    if (!assignmentId || !this.date()) {
      this.error.set('Select assigned subject and date.');
      return;
    }

    const attendances = this.rows()
      .filter((row) => row.status !== 'not_marked')
      .map((row) => ({
        enrollment_id: row.enrollment_id,
        status: row.status as 'present' | 'absent' | 'leave' | 'half_day',
        remarks: row.remarks || undefined
      }));

    if (!attendances.length) {
      this.error.set('No attendance status selected to save.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.teacherAcademics
      .saveAttendance({ assignment_id: assignmentId, date: this.date(), attendances })
      .subscribe({
        next: (response) => {
          this.saving.set(false);
          this.message.set(response.message || 'Attendance saved.');
          this.loadSheet();
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(err?.error?.message || 'Unable to save attendance.');
        }
      });
  }
}
