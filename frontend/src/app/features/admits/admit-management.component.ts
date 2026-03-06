import { NgFor, NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { AdmitCardService } from '../../core/services/admit-card.service';
import { SubjectsService } from '../../core/services/subjects.service';
import { Subject } from '../../models/subject';
import { ExamConfigurationsService } from '../../core/services/exam-configurations.service';
import { ClassModel } from '../../models/class';
import { AcademicYear } from '../../models/academic-year';
import { AdmitSessionCard } from '../../models/admit-card';
import { ExamConfiguration } from '../../models/exam-configuration';
import { ClassesService } from '../../core/services/classes.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';

interface AdmitSessionRow {
  id: number;
  name: string;
  status: string;
  class_id: number;
  academic_year_id: number;
  exam_configuration_id?: number | null;
  active_admit_count: number;
  published_admit_count: number;
  class_model?: { id: number; name: string };
  academic_year?: { id: number; name: string };
  exam_configuration?: { id: number; name: string };
}

interface ScheduleSubjectRow {
  subject_id: number;
  subject_name: string;
  subject_code: string;
  exam_date: string;
  exam_shift: '' | '1st Shift' | '2nd Shift';
  start_time: string;
  end_time: string;
  room_number: string;
  max_marks: number | null;
}

@Component({
  selector: 'app-admit-management',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './admit-management.component.html',
  styleUrl: './admit-management.component.scss'
})
export class AdmitManagementComponent {
  private readonly admitCardService = inject(AdmitCardService);
  private readonly subjectsService = inject(SubjectsService);
  private readonly examConfigurationsService = inject(ExamConfigurationsService);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);

  readonly loading = signal(false);
  readonly loadingSubjects = signal(false);
  readonly loadingExamConfigs = signal(false);
  readonly loadingFilters = signal(false);
  readonly rows = signal<AdmitSessionRow[]>([]);
  readonly scheduleSubjects = signal<ScheduleSubjectRow[]>([]);
  readonly sessionCards = signal<AdmitSessionCard[]>([]);
  readonly classOptions = signal<ClassModel[]>([]);
  readonly academicYearOptions = signal<AcademicYear[]>([]);
  readonly examConfigOptions = signal<ExamConfiguration[]>([]);
  readonly selectedClassId = signal<string>('');
  readonly selectedAcademicYearId = signal<string>('');
  readonly selectedExamConfigId = signal<string>('');
  readonly selectedSessionId = signal<string>('');
  readonly centerName = signal('');
  readonly seatPrefix = signal('S');
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly actionLoading = signal<'generate' | 'publish' | null>(null);
  readonly bulkLoading = signal(false);
  readonly loadingSessionCards = signal(false);
  readonly visibilityActionLoading = signal<number | null>(null);
  readonly searchQuery = signal('');

  ngOnInit() {
    this.loadFilters();
  }

  load() {
    if (!this.canLoadSessions()) {
      this.rows.set([]);
      this.selectedSessionId.set('');
      this.scheduleSubjects.set([]);
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);
    this.selectedSessionId.set('');
    this.scheduleSubjects.set([]);

    const examConfigId = Number(this.selectedExamConfigId());
    this.admitCardService
      .listSessions({
        class_id: Number(this.selectedClassId()),
        academic_year_id: Number(this.selectedAcademicYearId()),
        exam_configuration_id: examConfigId || undefined,
        per_page: 100,
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response: { data: AdmitSessionRow[] }) => {
          const items = response.data || [];
          if (items.length === 0 && Number(this.selectedExamConfigId())) {
            this.loadSessionsWithoutExamConfigFallback();
            return;
          }

          this.rows.set(items);
          this.syncSelectedSession(items);
          this.forceFirstSessionSelection(items);
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load sessions for admits.'),
      });
  }

  onClassChange(value: string) {
    this.selectedClassId.set(value || '');
    this.resetSessionAndTimetable();
    this.load();
  }

  onAcademicYearChange(value: string) {
    this.selectedAcademicYearId.set(value || '');
    this.selectedExamConfigId.set('');
    this.examConfigOptions.set([]);
    this.resetSessionAndTimetable();

    const yearId = Number(this.selectedAcademicYearId());
    if (!yearId) {
      this.rows.set([]);
      return;
    }

    this.loadingExamConfigs.set(true);
    this.examConfigurationsService
      .list({ academic_year_id: yearId, active_only: true })
      .pipe(finalize(() => this.loadingExamConfigs.set(false)))
      .subscribe({
        next: (response) => {
          this.examConfigOptions.set(response.data || []);
          this.load();
        },
        error: () => {
          // Some roles can manage admits but cannot access exam configuration master.
          this.examConfigOptions.set([]);
          this.load();
        },
      });
  }

  onExamConfigChange(value: string) {
    this.selectedExamConfigId.set(value || '');
    this.resetSessionAndTimetable();
    this.load();
  }

  onSessionChange(value: string) {
    this.selectedSessionId.set(value ? String(value) : '');
    this.loadSubjectsForSelectedSession();
    this.loadSessionCards();
  }

  generate() {
    this.error.set(null);
    this.message.set(null);

    const sessionId = this.ensureSessionSelected();
    if (!sessionId) {
      this.error.set('No session available for selected filters.');
      return;
    }

    if (this.scheduleSubjects().length === 0) {
      this.error.set('No subjects found for this session. Please map class subjects first.');
      return;
    }

    const incomplete = this.scheduleSubjects().filter((row) => !row.exam_date || !row.exam_shift || !row.start_time || !row.end_time);
    if (incomplete.length > 0) {
      this.error.set('Complete exam date, shift, start time, and end time for all subjects before generating admits.');
      return;
    }

    this.actionLoading.set('generate');
    this.admitCardService.generate({
      exam_session_id: sessionId,
      center_name: this.centerName() || undefined,
      seat_prefix: this.seatPrefix() || undefined,
      schedule: {
        subjects: this.scheduleSubjects().map((row) => ({
          subject_id: row.subject_id,
          subject_name: row.subject_name || undefined,
          subject_code: row.subject_code || undefined,
          exam_date: row.exam_date || undefined,
          exam_shift: row.exam_shift || undefined,
          start_time: row.start_time || undefined,
          end_time: row.end_time || undefined,
          room_number: row.room_number || undefined,
          max_marks: row.max_marks,
        })),
      },
    }).pipe(finalize(() => this.actionLoading.set(null)))
      .subscribe({
        next: (response: { message?: string }) => {
          this.message.set(response.message || 'Admit cards generated.');
          this.load();
          this.loadSessionCards();
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to generate admit cards.'),
      });
  }

  publish() {
    this.error.set(null);
    this.message.set(null);

    const sessionId = this.ensureSessionSelected();
    if (!sessionId) {
      this.error.set('No session available for selected filters.');
      return;
    }

    this.actionLoading.set('publish');
    this.admitCardService.publishSession(sessionId)
      .pipe(finalize(() => this.actionLoading.set(null)))
      .subscribe({
        next: (response: { message?: string }) => {
          this.message.set(response.message || 'Admit cards published.');
          this.load();
          this.loadSessionCards();
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to publish admit cards.'),
      });
  }

  isActionLoading(name: 'generate' | 'publish'): boolean {
    return this.actionLoading() === name;
  }

  updateScheduleField(
    rowIndex: number,
    field: 'exam_date' | 'exam_shift' | 'start_time' | 'end_time' | 'room_number' | 'max_marks',
    value: string
  ) {
    const rows = [...this.scheduleSubjects()];
    const target = rows[rowIndex];
    if (!target) {
      return;
    }

    if (field === 'max_marks') {
      target.max_marks = value === '' ? null : Number(value);
    } else if (field === 'exam_shift') {
      target.exam_shift = value as ScheduleSubjectRow['exam_shift'];
    } else {
      target[field] = value;
    }

    this.scheduleSubjects.set(rows);
  }

  downloadBulk() {
    this.error.set(null);
    this.message.set(null);

    const sessionId = this.ensureSessionSelected();
    if (!sessionId) {
      this.error.set('No session available for selected filters.');
      return;
    }

    this.bulkLoading.set(true);
    this.admitCardService
      .bulkPaper(sessionId)
      .pipe(finalize(() => this.bulkLoading.set(false)))
      .subscribe({
        next: (blob: Blob) => this.saveBlob(blob, `admit-session-${sessionId}.pdf`),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load admit cards for bulk download.'),
      });
  }

  setVisibility(card: AdmitSessionCard, visibility_status: 'visible' | 'withheld') {
    this.error.set(null);
    this.message.set(null);

    if (!card?.id) {
      return;
    }

    this.visibilityActionLoading.set(card.id);
    this.admitCardService
      .setVisibility(card.id, { visibility_status })
      .pipe(finalize(() => this.visibilityActionLoading.set(null)))
      .subscribe({
        next: (response: { message?: string }) => {
          this.message.set(response.message || 'Admit card visibility updated.');
          this.loadSessionCards();
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to update admit card visibility.'),
      });
  }

  downloadCard(card: AdmitSessionCard) {
    this.error.set(null);
    this.message.set(null);

    if (!card?.id) {
      return;
    }

    this.visibilityActionLoading.set(card.id);
    this.admitCardService
      .downloadPaper(card.id)
      .pipe(finalize(() => this.visibilityActionLoading.set(null)))
      .subscribe({
        next: (blob: Blob) => this.saveBlob(blob, `admit-${card.id}.pdf`),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to download admit card.'),
      });
  }

  filteredSessionCards(): AdmitSessionCard[] {
    const query = this.searchQuery().trim().toLowerCase();
    const cards = this.sessionCards();
    if (!query) {
      return cards;
    }

    return cards.filter((card) => {
      const name = (card.student_name || '').toLowerCase();
      const roll = (card.roll_number || '').toLowerCase();
      const seat = (card.seat_number || '').toLowerCase();
      return name.includes(query) || roll.includes(query) || seat.includes(query);
    });
  }

  isVisibilityLoading(cardId: number): boolean {
    return this.visibilityActionLoading() === cardId;
  }

  refresh() {
    this.loadFilters();
  }

  canLoadSessions(): boolean {
    return !!Number(this.selectedClassId()) && !!Number(this.selectedAcademicYearId());
  }

  private loadSubjectsForSelectedSession() {
    this.scheduleSubjects.set([]);

    const session = this.selectedSession();
    if (!session) {
      return;
    }

    this.loadingSubjects.set(true);
    this.subjectsService
      .list({
        status: 'active',
        is_active: true,
        class_id: session.class_id,
        academic_year_id: session.academic_year_id,
        per_page: 200,
      })
      .pipe(finalize(() => this.loadingSubjects.set(false)))
      .subscribe({
        next: (response) => {
          const subjects = response.data || [];
          this.scheduleSubjects.set(subjects.map((item) => this.toScheduleRow(item)));
        },
        error: (err: any) => {
          this.error.set(err?.error?.message || 'Unable to load subjects for selected session.');
        },
      });
  }

  private loadSessionCards() {
    const sessionId = Number(this.selectedSessionId());
    if (!sessionId) {
      this.sessionCards.set([]);
      return;
    }

    this.loadingSessionCards.set(true);
    this.admitCardService
      .listSessionCards(sessionId, { per_page: 500 })
      .pipe(finalize(() => this.loadingSessionCards.set(false)))
      .subscribe({
        next: (response) => this.sessionCards.set(response.data || []),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load admit cards for this session.'),
      });
  }

  private selectedSession(): AdmitSessionRow | null {
    const sessionId = Number(this.selectedSessionId());
    if (!sessionId) {
      return null;
    }

    return this.rows().find((row) => row.id === sessionId) || null;
  }

  private syncSelectedSession(items: AdmitSessionRow[]) {
    if (items.length === 0) {
      this.selectedSessionId.set('');
      this.scheduleSubjects.set([]);
      this.sessionCards.set([]);
      return;
    }

    const currentSessionId = Number(this.selectedSessionId());
    const currentStillExists = currentSessionId && items.some((row) => row.id === currentSessionId);
    if (currentStillExists) {
      return;
    }

    this.onSessionChange(String(items[0].id));
  }

  private ensureSessionSelected(): number | null {
    const selectedId = Number(this.selectedSessionId());
    if (selectedId) {
      return selectedId;
    }

    const first = this.rows()[0];
    if (!first) {
      return null;
    }

    this.onSessionChange(String(first.id));
    return first.id;
  }

  private loadSessionsWithoutExamConfigFallback() {
    this.loading.set(true);

    this.admitCardService
      .listSessions({
        class_id: Number(this.selectedClassId()),
        academic_year_id: Number(this.selectedAcademicYearId()),
        per_page: 100,
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response: { data: AdmitSessionRow[] }) => {
          const items = response.data || [];
          this.rows.set(items);
          this.syncSelectedSession(items);
          this.forceFirstSessionSelection(items);
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load sessions for admits.'),
      });
  }

  private forceFirstSessionSelection(items: AdmitSessionRow[]) {
    if (items.length === 0 || this.selectedSessionId()) {
      return;
    }

    const firstSessionId = String(items[0].id);
    this.selectedSessionId.set(firstSessionId);
    this.loadSubjectsForSelectedSession();
    this.loadSessionCards();
  }

  private loadFilters() {
    this.loadingFilters.set(true);
    this.error.set(null);
    forkJoin({
      classes: this.classesService.list({ per_page: 500 }).pipe(
        catchError(() => of({ data: [] as ClassModel[] }))
      ),
      years: this.academicYearsService.list({ per_page: 500 }).pipe(
        catchError(() => of({ data: [] as AcademicYear[] }))
      ),
      sessions: this.admitCardService.listSessions({ per_page: 500 }).pipe(
        catchError(() => of({ data: [] as AdmitSessionRow[] }))
      ),
    })
      .pipe(finalize(() => this.loadingFilters.set(false)))
      .subscribe({
        next: ({ classes, years, sessions }) => {
          const classMap = new Map<number, ClassModel>();
          const yearMap = new Map<number, AcademicYear>();

          for (const row of classes.data || []) {
            if (row?.id) {
              classMap.set(row.id, row);
            }
          }

          for (const row of years.data || []) {
            if (row?.id) {
              yearMap.set(row.id, row);
            }
          }

          for (const row of sessions.data || []) {
            if (row.class_id && !classMap.has(row.class_id)) {
              classMap.set(row.class_id, {
                id: row.class_id,
                name: row.class_model?.name || `Class ${row.class_id}`,
              } as ClassModel);
            }

            if (row.academic_year_id && !yearMap.has(row.academic_year_id)) {
              yearMap.set(row.academic_year_id, {
                id: row.academic_year_id,
                name: row.academic_year?.name || `Year ${row.academic_year_id}`,
              } as AcademicYear);
            }
          }

          this.classOptions.set(Array.from(classMap.values()));
          this.academicYearOptions.set(Array.from(yearMap.values()));

          if (classMap.size === 0 || yearMap.size === 0) {
            this.error.set('Unable to load admit filters. Please ensure classes and academic sessions exist.');
          }

          this.load();
        },
      });
  }

  private resetSessionAndTimetable() {
    this.selectedSessionId.set('');
    this.rows.set([]);
    this.scheduleSubjects.set([]);
    this.sessionCards.set([]);
  }

  private toScheduleRow(subject: Subject): ScheduleSubjectRow {
    return {
      subject_id: subject.id,
      subject_name: subject.name,
      subject_code: subject.subject_code || subject.code || '',
      exam_date: '',
      exam_shift: '',
      start_time: '',
      end_time: '',
      room_number: '',
      max_marks: null,
    };
  }

  private saveBlob(blob: Blob, filename: string) {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.URL.revokeObjectURL(url);
  }
}
