import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { forkJoin } from 'rxjs';
import { AdminMarksService } from '../../core/services/admin-marks.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { AuditDownloadsService } from '../../core/services/audit-downloads.service';
import {
  AdminMarksFiltersResponse,
  AdminMarksRow,
  AdminMarksScope,
  AdminMarksTeacherColumn,
} from '../../models/admin-marks';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';
import { ClassesService } from '../../core/services/classes.service';
import { extractApiError } from '../../core/utils/api-error.util';

@Component({
  selector: 'app-admin-assign-marks',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule],
  templateUrl: './admin-assign-marks.component.html',
  styleUrl: './admin-assign-marks.component.scss'
})
export class AdminAssignMarksComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly adminMarksService = inject(AdminMarksService);
  private readonly auditDownloadsService = inject(AuditDownloadsService);

  readonly classes = signal<ClassModel[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly teachers = signal<AdminMarksTeacherColumn[]>([]);
  readonly rows = signal<AdminMarksRow[]>([]);
  readonly scope = signal<AdminMarksScope | null>(null);
  readonly filters = signal<AdminMarksFiltersResponse | null>(null);
  readonly targetEnrollmentIds = signal<number[]>([]);

  readonly classId = signal<string>('');
  readonly academicYearId = signal<string>('');
  readonly sectionId = signal<string>('');
  readonly subjectId = signal<string>('');
  readonly subjectCode = signal<string>('');
  readonly examConfigurationId = signal<string>('');
  readonly markedOn = signal<string>(new Date().toISOString().slice(0, 10));

  readonly loading = signal(false);
  readonly loadingFilters = signal(false);
  readonly saving = signal(false);
  readonly finalizing = signal(false);
  readonly downloadingPdf = signal(false);
  readonly downloadingExcel = signal(false);
  readonly isFinalized = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly emptyStateMessage = signal<string | null>(null);

  readonly availableSections = computed(() => this.filters()?.sections || []);
  readonly availableSubjects = computed(() => this.filters()?.subjects || []);
  readonly examConfigurations = computed(() => this.filters()?.exam_configurations || []);
  readonly academicYear = computed(() => this.filters()?.academic_year || null);
  readonly setupMessages = computed(() => {
    const messages = this.filters()?.messages;
    if (!messages) {
      return [];
    }

    return Object.entries(messages)
      .filter(([key, value]) => key !== 'sections' && key !== 'academic_year' && !!value)
      .map(([, value]) => value as string);
  });

  readonly showSectionSelect = computed(() => !!this.classId() && !!this.academicYearId() && this.availableSections().length > 0);
  readonly showAllSectionsHint = computed(() => !this.sectionId() && this.availableSections().length > 1);
  readonly hasAcademicYear = computed(() => !!this.academicYear());
  readonly canPickSubject = computed(() => !!this.classId() && this.hasAcademicYear() && this.availableSubjects().length > 0);
  readonly canPickDate = computed(() => this.canPickSubject() && !!this.subjectId());
  readonly canPickExamConfiguration = computed(() => this.canPickDate() && this.examConfigurations().length > 0);
  readonly canLoadSheet = computed(() => !!this.classId() && !!this.academicYearId() && !!this.subjectId() && !!this.examConfigurationId() && !!this.markedOn());
  readonly canDownload = computed(() => this.isFinalized() && this.rows().length > 0 && !!this.scope());
  readonly subjectPlaceholder = computed(() => {
    if (!this.classId()) {
      return 'Select class first';
    }
    if (!this.hasAcademicYear()) {
      return 'Select academic year first';
    }
    if (this.availableSubjects().length === 0) {
      return 'No subjects configured';
    }
    return 'Select subject';
  });
  readonly examConfigurationPlaceholder = computed(() => {
    if (!this.subjectId()) {
      return 'Select subject first';
    }
    if (this.examConfigurations().length === 0) {
      return 'No exam configured';
    }
    return 'Select configured exam';
  });
  readonly showNoSectionInfo = computed(() => !!this.classId() && this.hasAcademicYear() && this.availableSections().length === 0);

  private pendingSectionId: string | null = null;
  private pendingSubjectId: string | null = null;
  private pendingExamConfigurationId: string | null = null;
  private pendingMarkedOn: string | null = null;

  ngOnInit() {
    this.loading.set(true);
    forkJoin({
      classes: this.classesService.list({ per_page: 200 }),
      academicYears: this.academicYearsService.list({ per_page: 200 }),
    }).subscribe({
      next: ({ classes, academicYears }) => {
        this.classes.set(classes.data || []);
        this.academicYears.set(academicYears.data || []);
        this.applyQueryParams();
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(extractApiError(err, 'Unable to load classes.'));
      }
    });
  }

  private applyQueryParams() {
    const query = this.route.snapshot.queryParamMap;
    const classId = query.get('class_id') || '';
    const academicYearId = query.get('academic_year_id') || '';
    const sectionId = query.get('section_id') || '';
    const subjectId = query.get('subject_id') || '';
    const examConfigurationId = query.get('exam_configuration_id') || '';
    const markedOn = query.get('marked_on') || '';
    const enrollmentIds = (query.get('enrollment_ids') || '')
      .split(',')
      .map((value) => Number(value.trim()))
      .filter((value) => Number.isFinite(value) && value > 0);

    this.targetEnrollmentIds.set(enrollmentIds);
    this.academicYearId.set(academicYearId);
    this.pendingSectionId = sectionId || null;
    this.pendingSubjectId = subjectId || null;
    this.pendingExamConfigurationId = examConfigurationId || null;
    this.pendingMarkedOn = markedOn || null;

    if (markedOn) {
      this.markedOn.set(markedOn);
    }

    if (classId) {
      this.onClassChange(classId);
    }
  }

  onClassChange(classIdRaw: string) {
    this.classId.set(classIdRaw);
    this.clearFilterSelections(false);
    this.resetSheetState();

    this.tryLoadFilters();
  }

  onAcademicYearChange(academicYearIdRaw: string) {
    this.academicYearId.set(academicYearIdRaw);
    this.clearFilterSelections(false);
    this.resetSheetState();
    this.tryLoadFilters();
  }

  onSectionChange(sectionIdRaw: string) {
    this.sectionId.set(sectionIdRaw);
    this.subjectId.set('');
    this.subjectCode.set('');
    this.examConfigurationId.set('');
    this.resetSheetState();

    this.tryLoadFilters();
  }

  onSubjectChange(subjectIdRaw: string) {
    this.subjectId.set(subjectIdRaw);
    this.resetSheetState();

    const selectedSubject = this.availableSubjects().find((item) => item.id === Number(subjectIdRaw));
    this.subjectCode.set(selectedSubject?.subject_code || selectedSubject?.code || '');

    const preferredExamId = selectedSubject?.academic_year_exam_config_id || null;
    const selectedStillExists = this.examConfigurations().some((item) => Number(item.id) === Number(this.examConfigurationId()));

    if (preferredExamId && this.examConfigurations().some((item) => item.id === preferredExamId)) {
      this.examConfigurationId.set(String(preferredExamId));
      return;
    }

    if (!selectedStillExists) {
      this.examConfigurationId.set(this.examConfigurations().length > 0 ? String(this.examConfigurations()[0].id) : '');
    }
  }

  onDateChange(date: string) {
    this.markedOn.set(date);
    this.resetSheetState();
    this.validateDateSelection();
  }

  onExamConfigurationChange(examConfigurationId: string) {
    this.examConfigurationId.set(examConfigurationId);
    this.resetSheetState();
  }

  loadSheet() {
    const classId = Number(this.classId());
    const academicYearId = Number(this.academicYearId());
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const examConfigurationId = Number(this.examConfigurationId());

    if (!classId || !academicYearId || !subjectId || !examConfigurationId) {
      this.error.set('Select class, academic year, subject, date, and configured exam.');
      return;
    }

    if (!this.validateDateSelection()) {
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);
    this.emptyStateMessage.set(null);

    this.adminMarksService
      .sheet({
        class_id: classId,
        academic_year_id: academicYearId,
        section_id: sectionId || undefined,
        subject_id: subjectId,
        subject_code: this.subjectCode() || undefined,
        marked_on: this.markedOn() || undefined,
        exam_configuration_id: examConfigurationId
      })
      .subscribe({
        next: (response) => {
          this.scope.set(response.scope);
          this.markedOn.set(response.marked_on);
          this.subjectId.set(String(response.scope.subject_id));
          this.subjectCode.set(response.scope.subject_code || '');
          if (response.scope.exam_configuration_id) {
            this.examConfigurationId.set(String(response.scope.exam_configuration_id));
          }
          this.teachers.set(response.teachers || []);
          const targetIds = this.targetEnrollmentIds();
          const rows = (response.rows || []).map((row) => ({
            ...row,
            compiled_is_absent: (row.compiled_remarks || '').trim().toUpperCase() === 'A',
          }));
          this.rows.set(targetIds.length > 0
            ? rows.filter((row) => targetIds.includes(row.enrollment_id))
            : rows);
          this.isFinalized.set(!!response.is_finalized);
          this.emptyStateMessage.set(response.empty_state_message || null);
          this.loading.set(false);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(extractApiError(err, 'Unable to load marks sheet.'));
        }
      });
  }

  saveCompiled() {
    const classId = Number(this.classId());
    const academicYearId = Number(this.academicYearId());
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const examConfigurationId = Number(this.examConfigurationId());

    if (!classId || !academicYearId || !subjectId || !examConfigurationId) {
      this.error.set('Select class, academic year, subject, date, and configured exam.');
      return;
    }
    if (!this.validateDateSelection()) {
      return;
    }
    if (this.isFinalized()) {
      this.error.set('This sheet is already finalized.');
      return;
    }
    if (!this.rows().length) {
      this.error.set('No student rows available.');
      return;
    }

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService
      .compile({
        class_id: classId,
        academic_year_id: academicYearId,
        section_id: sectionId || undefined,
        subject_id: subjectId,
        subject_code: this.subjectCode() || undefined,
        marked_on: this.markedOn(),
        exam_configuration_id: examConfigurationId,
        rows: this.rows().map((row) => ({
          enrollment_id: row.enrollment_id,
          marks_obtained: row.compiled_marks_obtained ?? null,
          max_marks: row.compiled_max_marks ?? null,
          remarks: row.compiled_remarks || undefined
        }))
      })
      .subscribe({
        next: (response) => {
          this.saving.set(false);
          this.message.set(response.message || 'Compiled marks saved.');
          this.loadSheet();
        },
        error: (err) => {
          this.saving.set(false);
          this.error.set(extractApiError(err, 'Unable to save compiled marks.'));
        }
      });
  }

  finalize() {
    const classId = Number(this.classId());
    const academicYearId = Number(this.academicYearId());
    const sectionId = Number(this.sectionId());
    const subjectId = Number(this.subjectId());
    const examConfigurationId = Number(this.examConfigurationId());

    if (!classId || !academicYearId || !subjectId) {
      this.error.set('Select class, academic year, and subject.');
      return;
    }
    if (!examConfigurationId) {
      this.error.set('Select exam from configured exam list.');
      return;
    }
    if (!this.validateDateSelection()) {
      return;
    }

    this.finalizing.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService
      .finalize({
        class_id: classId,
        academic_year_id: academicYearId,
        section_id: sectionId || undefined,
        subject_id: subjectId,
        subject_code: this.subjectCode() || undefined,
        marked_on: this.markedOn(),
        exam_configuration_id: examConfigurationId
      })
      .subscribe({
        next: (response) => {
          this.finalizing.set(false);
          this.message.set(response.message || 'Marks finalized.');
          this.loadSheet();
        },
        error: (err) => {
          this.finalizing.set(false);
          this.error.set(extractApiError(err, 'Unable to finalize marks.'));
        }
      });
  }

  onCompiledMarksChange(row: AdminMarksRow, value: string | number | null) {
    row.compiled_marks_obtained = this.toNullableNumber(value);
    this.rows.set([...this.rows()]);
  }

  onCompiledMaxChange(row: AdminMarksRow, value: string | number | null) {
    row.compiled_max_marks = this.toNullableNumber(value);
    this.rows.set([...this.rows()]);
  }

  onCompiledRemarksChange(row: AdminMarksRow, value: string) {
    row.compiled_remarks = value;
    this.rows.set([...this.rows()]);
  }

  onCompiledAbsentChange(row: AdminMarksRow, absent: boolean) {
    (row as AdminMarksRow & { compiled_is_absent?: boolean }).compiled_is_absent = absent;
    if (absent) {
      row.compiled_marks_obtained = null;
      row.compiled_remarks = 'A';
    } else if ((row.compiled_remarks || '').trim().toUpperCase() === 'A') {
      row.compiled_remarks = '';
    }
    this.rows.set([...this.rows()]);
  }

  downloadExcel() {
    if (!this.canDownload()) {
      this.error.set('Finalize marks first to download exports.');
      return;
    }

    const scope = this.scope();
    if (!scope) {
      this.error.set('No marks scope available for export.');
      return;
    }

    this.downloadingExcel.set(true);
    this.error.set(null);

    try {
      const teacherColumns = this.teachers();
      const headers = [
        'Roll Number',
        'Student Name',
        ...teacherColumns.map((teacher) => `${teacher.name} Marks`),
        'Final Marks',
        'Final Max',
        'Percentage',
        'Remarks'
      ];

      const csvRows = this.rows().map((row) => {
        const percentage =
          row.compiled_marks_obtained !== null &&
          row.compiled_marks_obtained !== undefined &&
          row.compiled_max_marks !== null &&
          row.compiled_max_marks !== undefined &&
          row.compiled_max_marks > 0
            ? ((row.compiled_marks_obtained / row.compiled_max_marks) * 100).toFixed(2)
            : '';

        return [
          row.roll_number ?? '',
          row.student_name ?? '',
          ...teacherColumns.map((teacher) => row.teacher_marks[String(teacher.id)]?.marks_obtained ?? ''),
          row.compiled_marks_obtained ?? '',
          row.compiled_max_marks ?? '',
          percentage,
          row.compiled_remarks ?? ''
        ];
      });

      const csv = [headers, ...csvRows]
        .map((line) => line.map((value) => this.escapeCsv(value)).join(','))
        .join('\n');

      const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
      const fileName = this.buildFileName(scope, 'csv');
      this.downloadBlob(blob, fileName);
      this.logDownload('csv', fileName, blob);
      this.message.set('Marks Excel download started.');
    } catch {
      this.error.set('Unable to generate Excel export.');
    } finally {
      this.downloadingExcel.set(false);
    }
  }

  async downloadPdf() {
    if (!this.canDownload()) {
      this.error.set('Finalize marks first to download exports.');
      return;
    }

    const scope = this.scope();
    if (!scope) {
      this.error.set('No marks scope available for export.');
      return;
    }

    this.downloadingPdf.set(true);
    this.error.set(null);

    try {
      const { jsPDF } = await import('jspdf');
      const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
      const margin = 24;
      const pageWidth = doc.internal.pageSize.getWidth();
      const pageHeight = doc.internal.pageSize.getHeight();
      const usableWidth = pageWidth - margin * 2;
      let y = 28;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(13);
      doc.text('Finalized Marks Sheet', margin, y);
      y += 16;

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9);
      doc.text(
        `Class: ${scope.class_name} | Section: ${scope.section_name} | Subject: ${scope.subject_name} (${scope.subject_code || '-'}) | Date: ${this.markedOn()}`,
        margin,
        y
      );
      y += 10;
      doc.text(`Academic Year: ${scope.academic_year_name}`, margin, y);
      y += 18;

      const columns = [
        { label: 'Roll', width: 48 },
        { label: 'Student', width: 210 },
        { label: 'Final', width: 52 },
        { label: 'Max', width: 52 },
        { label: '%', width: 46 },
        { label: 'Remarks', width: usableWidth - (48 + 210 + 52 + 52 + 46) }
      ];

      const drawHeader = () => {
        let x = margin;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.5);
        columns.forEach((column) => {
          doc.rect(x, y - 10, column.width, 16);
          doc.text(column.label, x + 3, y);
          x += column.width;
        });
        y += 16;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8);
      };

      drawHeader();

      this.rows().forEach((row) => {
        if (y > pageHeight - 26) {
          doc.addPage();
          y = 30;
          drawHeader();
        }

        const percentage =
          row.compiled_marks_obtained !== null &&
          row.compiled_marks_obtained !== undefined &&
          row.compiled_max_marks !== null &&
          row.compiled_max_marks !== undefined &&
          row.compiled_max_marks > 0
            ? ((row.compiled_marks_obtained / row.compiled_max_marks) * 100).toFixed(2)
            : '-';

        const values = [
          String(row.roll_number ?? '-'),
          String(row.student_name ?? '-').slice(0, 46),
          row.compiled_marks_obtained !== null && row.compiled_marks_obtained !== undefined ? String(row.compiled_marks_obtained) : '-',
          row.compiled_max_marks !== null && row.compiled_max_marks !== undefined ? String(row.compiled_max_marks) : '-',
          percentage,
          String(row.compiled_remarks ?? '-').slice(0, 60)
        ];

        let x = margin;
        values.forEach((value, index) => {
          const width = columns[index].width;
          doc.rect(x, y - 10, width, 16);
          doc.text(value, x + 3, y);
          x += width;
        });

        y += 16;
      });

      const fileName = this.buildFileName(scope, 'pdf');
      const blob = doc.output('blob');
      this.downloadBlob(blob, fileName);
      this.logDownload('pdf', fileName, blob);
      this.message.set('Marks PDF download started.');
    } catch {
      this.error.set('Unable to generate PDF export.');
    } finally {
      this.downloadingPdf.set(false);
    }
  }

  private loadFilters(classId: number, sectionId?: number) {
    this.loadingFilters.set(true);
    this.error.set(null);
    this.message.set(null);

    this.adminMarksService.filters({
      class_id: classId,
      academic_year_id: Number(this.academicYearId()),
      section_id: sectionId,
    }).subscribe({
      next: (response) => {
        this.loadingFilters.set(false);
        this.filters.set(response);

        const sections = response.sections || [];
        const subjects = response.subjects || [];
        const exams = response.exam_configurations || [];

        const resolvedSectionId = response.section_id ? String(response.section_id) : '';
        this.sectionId.set(resolvedSectionId);
        this.pendingSectionId = null;

        if (response.academic_year) {
          if (this.pendingMarkedOn) {
            this.markedOn.set(this.pendingMarkedOn);
            this.pendingMarkedOn = null;
          }

          if (!this.isDateWithinAcademicYear(this.markedOn())) {
            this.markedOn.set(response.academic_year.end_date);
          }
        }

        const subjectId = this.pickValidOption(this.pendingSubjectId, subjects.map((item) => String(item.id)));
        this.subjectId.set(subjectId);
        this.pendingSubjectId = null;

        const selectedSubject = subjects.find((item) => String(item.id) === subjectId);
        this.subjectCode.set(selectedSubject?.subject_code || selectedSubject?.code || '');

        const preferredExamId = selectedSubject?.academic_year_exam_config_id ? String(selectedSubject.academic_year_exam_config_id) : null;
        const examId = this.pickValidOption(
          this.pendingExamConfigurationId || preferredExamId,
          exams.map((item) => String(item.id))
        );
        this.examConfigurationId.set(examId);
        this.pendingExamConfigurationId = null;
      },
      error: (err) => {
        this.loadingFilters.set(false);
        this.filters.set(null);
        this.error.set(err?.error?.message || 'Unable to load marks filters.');
      }
    });
  }

  private pickValidOption(preferredValue: string | null, options: string[]): string {
    if (preferredValue && options.includes(preferredValue)) {
      return preferredValue;
    }

    return options[0] || '';
  }

  private validateDateSelection(): boolean {
    const year = this.academicYear();
    if (!year) {
      this.error.set('Academic year is not available for the selected class/section.');
      return false;
    }

    if (!this.markedOn()) {
      this.error.set('Select marks date.');
      return false;
    }

    if (!this.isDateWithinAcademicYear(this.markedOn())) {
      this.error.set(`Date must be within the academic year (${year.start_date} to ${year.end_date}).`);
      return false;
    }

    return true;
  }

  private isDateWithinAcademicYear(date: string): boolean {
    const year = this.academicYear();
    if (!year || !date) {
      return false;
    }

    return date >= year.start_date && date <= year.end_date;
  }

  private resetSheetState() {
    this.rows.set([]);
    this.scope.set(null);
    this.teachers.set([]);
    this.isFinalized.set(false);
    this.emptyStateMessage.set(null);
    this.error.set(null);
    this.message.set(null);
  }

  private tryLoadFilters() {
    const classId = Number(this.classId());
    const academicYearId = Number(this.academicYearId());

    this.filters.set(null);
    if (!classId || !academicYearId) {
      return;
    }

    this.loadFilters(classId, this.pendingSectionId ? Number(this.pendingSectionId) : Number(this.sectionId() || 0) || undefined);
  }

  private clearFilterSelections(clearAcademicYear: boolean) {
    if (clearAcademicYear) {
      this.academicYearId.set('');
    }
    this.sectionId.set('');
    this.subjectId.set('');
    this.subjectCode.set('');
    this.examConfigurationId.set('');
    this.filters.set(null);
  }

  private toNullableNumber(value: string | number | null): number | null {
    if (value === null || value === '') {
      return null;
    }
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }

  private escapeCsv(value: unknown): string {
    const text = String(value ?? '');
    const escaped = text.replace(/"/g, '""');
    return `"${escaped}"`;
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }

  private logDownload(format: 'csv' | 'pdf', fileName: string, blob?: Blob) {
    const scope = this.scope();
    if (!scope) {
      return;
    }

    this.buildChecksum(blob).then((checksum) => {
      this.auditDownloadsService.logDownload({
        module: 'assign_marks',
        report_key: 'final_marks_sheet',
        report_label: 'Final Marks Sheet',
        format,
        file_name: fileName,
        file_checksum: checksum,
        row_count: this.rows().length,
        filters: {
          class_id: scope.class_id,
          section_id: scope.section_id,
          subject_id: scope.subject_id,
          academic_year_id: scope.academic_year_id,
          exam_configuration_id: scope.exam_configuration_id,
          marked_on: this.markedOn(),
        },
        context: {
          class_name: scope.class_name,
          section_name: scope.section_name,
          subject_name: scope.subject_name,
          academic_year_name: scope.academic_year_name,
        },
      }).subscribe({ error: () => void 0 });
    });
  }

  private async buildChecksum(blob?: Blob): Promise<string | null> {
    if (!blob || !window.crypto?.subtle) {
      return null;
    }

    const buffer = await blob.arrayBuffer();
    const digest = await window.crypto.subtle.digest('SHA-256', buffer);

    return Array.from(new Uint8Array(digest)).map((value) => value.toString(16).padStart(2, '0')).join('');
  }

  private buildFileName(scope: AdminMarksScope, extension: 'pdf' | 'csv'): string {
    const parts = [
      'marks',
      scope.class_name,
      scope.section_name,
      scope.subject_code || scope.subject_name,
      this.markedOn()
    ];
    const safe = parts
      .join('_')
      .replace(/[^a-zA-Z0-9_-]+/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_|_$/g, '')
      .toLowerCase();

    return `${safe}.${extension}`;
  }
}
