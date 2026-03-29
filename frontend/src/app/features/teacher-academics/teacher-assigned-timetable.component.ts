import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { TeacherAcademicsService } from '../../core/services/teacher-academics.service';
import { TeacherTimetableResponse } from '../../models/teacher-academic';

@Component({
  selector: 'app-teacher-assigned-timetable',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './teacher-assigned-timetable.component.html',
  styleUrl: './teacher-assigned-timetable.component.scss'
})
export class TeacherAssignedTimetableComponent {
  private readonly teacherAcademics = inject(TeacherAcademicsService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly vm = signal<TeacherTimetableResponse | null>(null);
  readonly academicYearId = signal<string>('');

  readonly selectedMeta = computed(() => this.vm()?.meta ?? null);

  ngOnInit() {
    this.load();
  }

  load() {
    const selectedYear = Number(this.academicYearId() || 0);
    this.loading.set(true);
    this.error.set(null);

    this.teacherAcademics
      .getTimetable(selectedYear ? { academic_year_id: selectedYear } : undefined)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.vm.set(response);
          if (!this.academicYearId() && response.meta.academic_year_id) {
            this.academicYearId.set(String(response.meta.academic_year_id));
          }
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load assigned timetable.');
        }
      });
  }
}
