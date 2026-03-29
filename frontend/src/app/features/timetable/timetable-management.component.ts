import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ClassesService } from '../../core/services/classes.service';
import { SectionsService } from '../../core/services/sections.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { TeachersService } from '../../core/services/teachers.service';
import { TimetableService } from '../../core/services/timetable.service';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';
import { Section } from '../../models/section';
import { Subject } from '../../models/subject';
import { Teacher } from '../../models/teacher';
import { TimeSlot, TimetableDay, TimetableRow } from '../../models/timetable';

type BusyAction = 'bootstrap' | 'load' | 'save' | 'slot_save' | 'slot_delete' | null;

interface TimetableCell {
  day_of_week: TimetableDay;
  time_slot_id: number;
  subject_id: string;
  teacher_id: string;
  room_number: string;
}

@Component({
  selector: 'app-timetable-management',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './timetable-management.component.html',
  styleUrl: './timetable-management.component.scss'
})
export class TimetableManagementComponent {
  private readonly fb = inject(FormBuilder);
  private readonly timetableService = inject(TimetableService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly classesService = inject(ClassesService);
  private readonly sectionsService = inject(SectionsService);
  private readonly subjectsService = inject(SubjectsService);
  private readonly teachersService = inject(TeachersService);

  readonly busyAction = signal<BusyAction>('bootstrap');
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly subjects = signal<Subject[]>([]);
  readonly teachers = signal<Teacher[]>([]);
  readonly timeSlots = signal<TimeSlot[]>([]);
  readonly timetableRows = signal<TimetableRow[]>([]);
  readonly selectedTimetableMeta = signal<{ class_name?: string | null; section_name?: string | null } | null>(null);

  readonly days: Array<{ value: TimetableDay; label: string }> = [
    { value: 'monday', label: 'Monday' },
    { value: 'tuesday', label: 'Tuesday' },
    { value: 'wednesday', label: 'Wednesday' },
    { value: 'thursday', label: 'Thursday' },
    { value: 'friday', label: 'Friday' },
    { value: 'saturday', label: 'Saturday' },
  ];

  readonly filterForm = this.fb.nonNullable.group({
    academic_year_id: ['', Validators.required],
    class_id: ['', Validators.required],
    section_id: ['', Validators.required],
  });

  readonly slotForm = this.fb.nonNullable.group({
    name: ['', Validators.required],
    start_time: ['', Validators.required],
    end_time: ['', Validators.required],
    slot_order: [1, [Validators.required, Validators.min(1)]],
    is_break: [false],
  });

  readonly filteredSections = computed(() => {
    const yearId = Number(this.filterForm.controls.academic_year_id.value || 0);
    const classId = Number(this.filterForm.controls.class_id.value || 0);

    return this.sections().filter((section) => {
      const matchesYear = !yearId || Number(section.academic_year_id) === yearId;
      const matchesClass = !classId || Number(section.class_id) === classId;
      return matchesYear && matchesClass;
    });
  });

  readonly teacherOptions = computed(() =>
    this.teachers()
      .filter((row) => row.status === 'active' && row.employee_type === 'teaching')
      .map((row) => ({
        id: row.user_id,
        label: `${row.employee_id} - ${row.user?.full_name || row.user?.email || 'Teacher'}`
      }))
  );

  readonly timetableGrid = signal<Record<string, TimetableCell>>({});
  readonly timetablePdfLoading = signal(false);

  ngOnInit() {
    this.loadBootstrap();
  }

  loadBootstrap() {
    this.busyAction.set('bootstrap');
    this.error.set(null);

    forkJoin({
      years: this.academicYearsService.list({ per_page: 200 }),
      classes: this.classesService.list({ per_page: 250, status: 'active' }),
      sections: this.sectionsService.list({ per_page: 400, status: 'active' }),
      teachers: this.teachersService.list({ per_page: 300, status: 'active' }),
      timeSlots: this.timetableService.listTimeSlots(),
    })
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: ({ years, classes, sections, teachers, timeSlots }) => {
          this.academicYears.set(years.data || []);
          this.classes.set(classes.data || []);
          this.sections.set(sections.data || []);
          this.teachers.set(teachers.data || []);
          this.timeSlots.set((timeSlots || []).slice().sort((a, b) => a.slot_order - b.slot_order));

          const currentYear = (years.data || []).find((item) => !!item.is_current);
          if (currentYear) {
            this.filterForm.patchValue({ academic_year_id: String(currentYear.id) }, { emitEvent: false });
          }
          this.rebuildGrid();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load timetable data.');
        }
      });
  }

  onAcademicYearChange(value: string) {
    this.filterForm.patchValue({ academic_year_id: value, section_id: '' }, { emitEvent: false });
    this.subjects.set([]);
    this.timetableRows.set([]);
    this.selectedTimetableMeta.set(null);
    this.rebuildGrid();
    this.tryLoadSubjects();
  }

  onClassChange(value: string) {
    this.filterForm.patchValue({ class_id: value, section_id: '' }, { emitEvent: false });
    this.subjects.set([]);
    this.timetableRows.set([]);
    this.selectedTimetableMeta.set(null);
    this.rebuildGrid();
    this.tryLoadSubjects();
  }

  loadSectionTimetable() {
    if (this.filterForm.invalid) {
      this.filterForm.markAllAsTouched();
      return;
    }

    const raw = this.filterForm.getRawValue();
    this.busyAction.set('load');
    this.error.set(null);
    this.success.set(null);

    this.tryLoadSubjects();

    this.timetableService
      .getSectionTimetable({
        academic_year_id: Number(raw.academic_year_id),
        section_id: Number(raw.section_id),
      })
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (response) => {
          this.timetableRows.set(response.rows || []);
          this.selectedTimetableMeta.set({
            class_name: response.meta.class_name,
            section_name: response.meta.section_name,
          });
          this.rebuildGrid(response.rows || []);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load section timetable.');
        }
      });
  }

  saveTimetable() {
    const raw = this.filterForm.getRawValue();
    if (!raw.academic_year_id || !raw.section_id) {
      this.error.set('Select academic year, class, and section first.');
      return;
    }

    const entries = Object.values(this.timetableGrid()).map((cell) => ({
      day_of_week: cell.day_of_week,
      time_slot_id: cell.time_slot_id,
      subject_id: cell.subject_id ? Number(cell.subject_id) : null,
      teacher_id: cell.teacher_id ? Number(cell.teacher_id) : null,
      room_number: cell.room_number?.trim() || null,
    }));

    this.busyAction.set('save');
    this.error.set(null);
    this.success.set(null);

    this.timetableService
      .saveSectionTimetable({
        academic_year_id: Number(raw.academic_year_id),
        section_id: Number(raw.section_id),
        entries,
      })
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (response) => {
          this.success.set(response.message || 'Timetable saved successfully.');
          this.loadSectionTimetable();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save timetable.');
        }
      });
  }

  downloadTimetablePdf() {
    const raw = this.filterForm.getRawValue();
    if (!raw.academic_year_id || !raw.section_id) {
      this.error.set('Select academic year, class, and section first.');
      return;
    }

    this.timetablePdfLoading.set(true);
    this.error.set(null);

    this.timetableService
      .downloadSectionTimetablePdf({
        academic_year_id: Number(raw.academic_year_id),
        section_id: Number(raw.section_id),
      })
      .pipe(finalize(() => this.timetablePdfLoading.set(false)))
      .subscribe({
        next: (blob) => this.saveBlob(blob, 'section-timetable.pdf'),
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to download timetable PDF.');
        }
      });
  }

  saveTimeSlot() {
    if (this.slotForm.invalid) {
      this.slotForm.markAllAsTouched();
      return;
    }

    this.busyAction.set('slot_save');
    this.error.set(null);
    this.success.set(null);

    this.timetableService
      .createTimeSlot(this.slotForm.getRawValue())
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (response) => {
          this.success.set(response.message || 'Time slot created successfully.');
          this.slotForm.reset({
            name: '',
            start_time: '',
            end_time: '',
            slot_order: this.timeSlots().length + 1,
            is_break: false,
          });
          this.reloadTimeSlots();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to create time slot.');
        }
      });
  }

  deleteTimeSlot(id: number) {
    if (!confirm('Delete this time slot?')) {
      return;
    }

    this.busyAction.set('slot_delete');
    this.error.set(null);
    this.success.set(null);

    this.timetableService
      .deleteTimeSlot(id)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (response) => {
          this.success.set(response.message || 'Time slot deleted successfully.');
          this.reloadTimeSlots();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to delete time slot.');
        }
      });
  }

  updateCell(day: TimetableDay, timeSlotId: number, field: 'subject_id' | 'teacher_id' | 'room_number', value: string) {
    const key = this.cellKey(day, timeSlotId);
    const current = this.timetableGrid()[key] ?? {
      day_of_week: day,
      time_slot_id: timeSlotId,
      subject_id: '',
      teacher_id: '',
      room_number: '',
    };

    this.timetableGrid.set({
      ...this.timetableGrid(),
      [key]: {
        ...current,
        [field]: value,
      }
    });
  }

  cell(day: TimetableDay, timeSlotId: number): TimetableCell {
    return this.timetableGrid()[this.cellKey(day, timeSlotId)] ?? {
      day_of_week: day,
      time_slot_id: timeSlotId,
      subject_id: '',
      teacher_id: '',
      room_number: '',
    };
  }

  isBusy(action: Exclude<BusyAction, null>) {
    return this.busyAction() === action;
  }

  trackBySlot(_: number, slot: TimeSlot) {
    return slot.id;
  }

  trackByDay(_: number, day: { value: TimetableDay; label: string }) {
    return day.value;
  }

  private reloadTimeSlots() {
    this.timetableService.listTimeSlots().subscribe({
      next: (slots) => {
        this.timeSlots.set((slots || []).slice().sort((a, b) => a.slot_order - b.slot_order));
        this.rebuildGrid(this.timetableRows());
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Unable to reload time slots.');
      }
    });
  }

  private tryLoadSubjects() {
    const raw = this.filterForm.getRawValue();
    if (!raw.academic_year_id || !raw.class_id) {
      this.subjects.set([]);
      return;
    }

    this.subjectsService.list({
      per_page: 300,
      status: 'active',
      class_id: Number(raw.class_id),
      academic_year_id: Number(raw.academic_year_id),
    }).subscribe({
      next: (response) => {
        this.subjects.set(response.data || []);
      },
      error: (err) => {
        this.subjects.set([]);
        this.error.set(err?.error?.message || 'Unable to load subjects for selected class.');
      }
    });
  }

  private rebuildGrid(rows: TimetableRow[] = []) {
    const next: Record<string, TimetableCell> = {};

    for (const day of this.days) {
      for (const slot of this.timeSlots()) {
        next[this.cellKey(day.value, slot.id)] = {
          day_of_week: day.value,
          time_slot_id: slot.id,
          subject_id: '',
          teacher_id: '',
          room_number: '',
        };
      }
    }

    for (const row of rows) {
      next[this.cellKey(row.day_of_week, row.time_slot_id)] = {
        day_of_week: row.day_of_week,
        time_slot_id: row.time_slot_id,
        subject_id: row.subject_id ? String(row.subject_id) : '',
        teacher_id: row.teacher_id ? String(row.teacher_id) : '',
        room_number: row.room_number || '',
      };
    }

    this.timetableGrid.set(next);
  }

  private cellKey(day: TimetableDay, timeSlotId: number) {
    return `${day}:${timeSlotId}`;
  }

  private saveBlob(blob: Blob, filename: string) {
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 0);
  }
}
