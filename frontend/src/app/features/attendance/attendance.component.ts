import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators, FormsModule } from '@angular/forms';
import { NgIf, NgFor, JsonPipe } from '@angular/common';
import { AttendanceService } from '../../core/services/attendance.service';
import { SectionsService } from '../../core/services/sections.service';
import { ClassesService } from '../../core/services/classes.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import {
  AttendanceListItem,
  AttendanceLiveSearchItem,
  AttendanceMarkItem,
  AttendanceReportStudent,
  BulkMonthlyAttendanceRow
} from '../../models/attendance';
import { Section } from '../../models/section';
import { ClassModel } from '../../models/class';
import { AcademicYear } from '../../models/academic-year';
import { finalize } from 'rxjs/operators';
import { jsPDF } from 'jspdf';

@Component({
  selector: 'app-attendance',
  standalone: true,
  imports: [ReactiveFormsModule, FormsModule, NgIf, NgFor, JsonPipe],
  templateUrl: './attendance.component.html',
  styleUrl: './attendance.component.scss'
})
export class AttendanceComponent {
  private readonly attendanceService = inject(AttendanceService);
  private readonly sectionsService = inject(SectionsService);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly fb = inject(FormBuilder);
  private liveSearchTimer: ReturnType<typeof setTimeout> | null = null;

  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly sections = signal<Section[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly attendanceList = signal<AttendanceListItem[]>([]);
  readonly statistics = signal<any[] | null>(null);
  readonly reportStudents = signal<AttendanceReportStudent[]>([]);
  readonly liveSearchResults = signal<AttendanceLiveSearchItem[]>([]);
  readonly bulkRows = signal<BulkMonthlyAttendanceRow[]>([]);
  readonly selectedClassIds = signal<number[]>([]);
  readonly busyAction = signal<'load' | 'save' | 'lock' | 'stats' | 'search' | 'monthly' | 'session' | 'live' | 'bulk_excel' | 'bulk_pdf' | null>(null);
  readonly flashAction = signal<'save' | 'lock' | null>(null);
  readonly monthOptions = [
    { value: 1, label: 'January' },
    { value: 2, label: 'February' },
    { value: 3, label: 'March' },
    { value: 4, label: 'April' },
    { value: 5, label: 'May' },
    { value: 6, label: 'June' },
    { value: 7, label: 'July' },
    { value: 8, label: 'August' },
    { value: 9, label: 'September' },
    { value: 10, label: 'October' },
    { value: 11, label: 'November' },
    { value: 12, label: 'December' }
  ];

  readonly form = this.fb.nonNullable.group({
    section_id: ['', Validators.required],
    date: ['', Validators.required],
    start_date: [''],
    end_date: ['']
  });

  readonly reportForm = this.fb.nonNullable.group({
    student_id: ['', Validators.required],
    live_query: [''],
    enrollment_id: [''],
    student_ids: [''],
    month: [new Date().getMonth() + 1, [Validators.required, Validators.min(1), Validators.max(12)]],
    academic_year_id: ['']
  });

  ngOnInit() {
    this.sectionsService.list({ per_page: 200 }).subscribe({
      next: (response) => this.sections.set(response.data)
    });

    this.classesService.list({ per_page: 200 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });

    this.academicYearsService.list({ per_page: 200 }).subscribe({
      next: (response) => {
        this.academicYears.set(response.data);
        const current = response.data.find((item) => !!item.is_current);
        if (current && !this.reportForm.getRawValue().academic_year_id) {
          this.reportForm.patchValue({ academic_year_id: String(current.id) }, { emitEvent: false });
        }
      }
    });
  }

  loadAttendance() {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const payload = {
      section_id: Number(raw.section_id),
      date: raw.date
    };

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('load');
    this.attendanceService
      .getSectionAttendance(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (data) => this.attendanceList.set(data),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load attendance.')
      });
  }

  markAttendance() {
    const raw = this.form.getRawValue();
    const payload = {
      section_id: Number(raw.section_id),
      date: raw.date,
      attendances: this.attendanceList().map((item): AttendanceMarkItem => ({
        enrollment_id: item.enrollment_id,
        status: item.status as AttendanceMarkItem['status'],
        remarks: item.remarks || undefined
      }))
    };

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('save');
    this.attendanceService
      .markAttendance(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: () => {
          this.loadAttendance();
          this.message.set('Attendance saved successfully.');
          this.triggerFlash('save');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to mark attendance.')
      });
  }

  lockAttendance() {
    const raw = this.form.getRawValue();
    const payload = {
      section_id: Number(raw.section_id),
      date: raw.date
    };
    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('lock');
    this.attendanceService
      .lockAttendance(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: () => {
          this.loadAttendance();
          this.message.set('Attendance locked successfully.');
          this.triggerFlash('lock');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to lock attendance.')
      });
  }

  loadStatistics() {
    const raw = this.form.getRawValue();
    if (!raw.section_id || !raw.start_date || !raw.end_date) {
      this.error.set('Select section, start date, and end date for statistics.');
      return;
    }

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('stats');
    this.attendanceService
      .getSectionStatistics({
        section_id: Number(raw.section_id),
        start_date: raw.start_date,
        end_date: raw.end_date
      })
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (data) => this.statistics.set(data),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load statistics.')
      });
  }

  updateStatus(item: AttendanceListItem, value: string) {
    item.status = value;
    this.attendanceList.set([...this.attendanceList()]);
  }

  onReportQueryInput(value: string) {
    this.reportForm.patchValue({ live_query: value }, { emitEvent: false });

    if (this.liveSearchTimer) {
      clearTimeout(this.liveSearchTimer);
    }

    const term = value.trim();
    if (!term) {
      this.liveSearchResults.set([]);
      return;
    }

    this.liveSearchTimer = setTimeout(() => this.runLiveSearch(term), 300);
  }

  runLiveSearch(term?: string) {
    const raw = this.reportForm.getRawValue();
    const query = (term ?? raw.live_query ?? '').trim();
    if (!query) {
      this.liveSearchResults.set([]);
      return;
    }

    const payload: {
      q: string;
      academic_year_id?: number;
      class_ids?: string;
      month?: number;
    } = {
      q: query,
      month: Number(raw.month)
    };

    if (raw.academic_year_id) {
      payload.academic_year_id = Number(raw.academic_year_id);
    }

    const classIds = this.selectedClassIds();
    if (classIds.length) {
      payload.class_ids = classIds.join(',');
    }

    this.busyAction.set('live');
    this.attendanceService
      .liveSearch(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (rows) => this.liveSearchResults.set(rows),
        error: (err) => this.error.set(err?.error?.message || 'Unable to run live search.')
      });
  }

  selectLiveSearchRow(item: AttendanceLiveSearchItem) {
    const currentStudentIds = (this.reportForm.getRawValue().student_ids || '')
      .split(',')
      .map((token) => token.trim())
      .filter((token) => token !== '');

    if (!currentStudentIds.includes(String(item.student_id))) {
      currentStudentIds.push(String(item.student_id));
    }

    this.reportForm.patchValue({
      student_id: String(item.student_id),
      enrollment_id: String(item.enrollment_id),
      student_ids: currentStudentIds.join(',')
    });
    this.message.set(`Selected ${item.student_name || 'student'} (Enrollment #${item.enrollment_id}).`);
  }

  onClassSelectionChange(event: Event) {
    const target = event.target as HTMLSelectElement;
    const selected = Array.from(target.selectedOptions)
      .map((option) => Number(option.value))
      .filter((value) => Number.isFinite(value));
    this.selectedClassIds.set(selected);
  }

  searchStudents() {
    const raw = this.reportForm.getRawValue();
    if (!raw.student_id) {
      this.reportForm.markAllAsTouched();
      return;
    }

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('search');
    this.attendanceService
      .searchStudentsForReports({ student_id: raw.student_id })
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (students) => {
          this.reportStudents.set(students);
          if (!students.length) {
            this.message.set('No student found for this student ID.');
            return;
          }

          const ids = students.map((item) => item.student_id).join(',');
          this.reportForm.patchValue({ student_ids: ids }, { emitEvent: false });
          this.message.set(`Found ${students.length} student(s).`);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to search student attendance.')
      });
  }

  downloadMonthlyReport() {
    const raw = this.reportForm.getRawValue();
    const ids = (raw.student_ids || '').trim();

    if (!ids || !raw.month || !raw.academic_year_id) {
      this.error.set('Search student first and select month/session for monthly attendance download.');
      return;
    }

    const payload: { student_ids: string; month: number; academic_year_id: number } = {
      student_ids: ids,
      month: Number(raw.month),
      academic_year_id: Number(raw.academic_year_id)
    };

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('monthly');
    this.attendanceService
      .downloadMonthlyReport(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (blob) => {
          this.downloadBlob(blob, `attendance_monthly_session_${raw.academic_year_id}_${raw.month}.csv`);
          this.message.set('Monthly attendance CSV downloaded.');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to download monthly attendance report.')
      });
  }

  downloadSessionWiseReport() {
    const raw = this.reportForm.getRawValue();
    const ids = (raw.student_ids || '').trim();

    if (!ids) {
      this.error.set('Search student first to download session-wise attendance.');
      return;
    }

    const payload: { student_ids: string; academic_year_id?: number } = {
      student_ids: ids
    };

    if (raw.academic_year_id) {
      payload.academic_year_id = Number(raw.academic_year_id);
    }

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('session');
    this.attendanceService
      .downloadSessionWiseReport(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (blob) => {
          this.downloadBlob(blob, 'attendance_session_wise.csv');
          this.message.set('Session-wise attendance CSV downloaded.');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to download session-wise attendance report.')
      });
  }

  downloadBulkExcel() {
    const raw = this.reportForm.getRawValue();
    const classIds = this.selectedClassIds();

    if (!classIds.length || !raw.academic_year_id || !raw.month) {
      this.error.set('Select class(es), session and month for bulk download.');
      return;
    }

    const payload = {
      class_ids: classIds.join(','),
      academic_year_id: Number(raw.academic_year_id),
      month: Number(raw.month)
    };

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('bulk_excel');
    this.attendanceService
      .downloadBulkMonthlyExcel(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (blob) => {
          this.downloadBlob(blob, `attendance_bulk_session_${raw.academic_year_id}_${raw.month}.csv`);
          this.message.set('Bulk monthly attendance Excel (CSV) downloaded.');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to download bulk Excel report.')
      });
  }

  downloadBulkPdf() {
    const raw = this.reportForm.getRawValue();
    const classIds = this.selectedClassIds();

    if (!classIds.length || !raw.academic_year_id || !raw.month) {
      this.error.set('Select class(es), session and month for bulk PDF.');
      return;
    }

    const payload = {
      class_ids: classIds.join(','),
      academic_year_id: Number(raw.academic_year_id),
      month: Number(raw.month)
    };

    this.error.set(null);
    this.message.set(null);
    this.busyAction.set('bulk_pdf');
    this.attendanceService
      .getBulkMonthlyData(payload)
      .pipe(finalize(() => this.busyAction.set(null)))
      .subscribe({
        next: (response) => {
          this.bulkRows.set(response.rows);
          this.exportBulkPdf(response.rows, response.meta.month_name, response.meta.year);
          this.message.set('Bulk monthly attendance PDF downloaded.');
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to download bulk PDF report.')
      });
  }

  isBusy(action: 'load' | 'save' | 'lock' | 'stats' | 'search' | 'monthly' | 'session' | 'live' | 'bulk_excel' | 'bulk_pdf') {
    return this.busyAction() === action;
  }

  private triggerFlash(action: 'save' | 'lock') {
    this.flashAction.set(action);
    setTimeout(() => this.flashAction.set(null), 1400);
  }

  private downloadBlob(blob: Blob, filename: string) {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }

  private exportBulkPdf(rows: BulkMonthlyAttendanceRow[], monthName: string, year: number) {
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 26;
    const headerY = 24;
    let y = 72;

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(13);
    doc.text(`Attendance Bulk Report - ${monthName} ${year}`, margin, headerY);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text(`Generated: ${new Date().toLocaleString()}`, margin, headerY + 16);

    const columns = [
      { key: 'enrollment', label: 'Enroll', width: 50 },
      { key: 'student', label: 'Student', width: 55 },
      { key: 'name', label: 'Name', width: 170 },
      { key: 'class', label: 'Class', width: 70 },
      { key: 'section', label: 'Section', width: 70 },
      { key: 'present', label: 'P', width: 36 },
      { key: 'absent', label: 'A', width: 36 },
      { key: 'leave', label: 'L', width: 36 },
      { key: 'halfDay', label: 'HD', width: 40 },
      { key: 'notMarked', label: 'NM', width: 40 }
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
      y += 18;
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
    };

    drawHeader();

    rows.forEach((row) => {
      if (y > pageHeight - 30) {
        doc.addPage();
        y = 34;
        drawHeader();
      }

      let x = margin;
      const line = [
        String(row.enrollment_id),
        String(row.student_id),
        (row.student_name || '').slice(0, 34),
        (row.class || '').slice(0, 12),
        (row.section || '').slice(0, 12),
        String(row.counts.present),
        String(row.counts.absent),
        String(row.counts.leave),
        String(row.counts.half_day),
        String(row.counts.not_marked)
      ];

      line.forEach((value, index) => {
        const width = columns[index].width;
        doc.rect(x, y - 10, width, 16);
        doc.text(value, x + 3, y);
        x += width;
      });

      y += 16;
    });

    doc.save(`attendance_bulk_${monthName.toLowerCase()}_${year}.pdf`);
  }
}
