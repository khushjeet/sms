import { DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { environment } from '../../../environments/environment';
import { finalize } from 'rxjs/operators';
import { AdmitCardService } from '../../core/services/admit-card.service';
import { ResultPublishingService } from '../../core/services/result-publishing.service';
import { StudentDashboardService } from '../../core/services/student-dashboard.service';
import { TimetableService } from '../../core/services/timetable.service';
import { StudentThemeService } from '../../core/services/student-theme.service';
import { MyAdmitCardResponse } from '../../models/admit-card';
import { PublishedResultPaperResponse } from '../../models/result-publishing';
import { StudentDashboardResponse } from '../../models/student-dashboard';


type StudentSectionKey = 'admit-card' | 'fee' | 'result' | 'timetable' | 'academic-history' | 'attendance-history';

@Component({
  selector: 'app-student-portal-section',
  standalone: true,
  imports: [NgIf, NgFor, DecimalPipe],
  templateUrl: './student-portal-section.component.html',
  styleUrls: ['./student-portal-section.component.scss']
})
export class StudentPortalSectionComponent {
  readonly gradeScale = [
    { grade: 'A1', minPercentage: 91, label: 'Outstanding' },
    { grade: 'A2', minPercentage: 81, label: 'Excellent' },
    { grade: 'B1', minPercentage: 71, label: 'Very Good' },
    { grade: 'B2', minPercentage: 61, label: 'Good' },
    { grade: 'C1', minPercentage: 51, label: 'Satisfactory' },
    { grade: 'C2', minPercentage: 41, label: 'Needs Improvement' },
    { grade: 'D', minPercentage: 33, label: 'Pass' },
    { grade: 'E', minPercentage: 0, label: 'Below Standard' },
  ] as const;

  private readonly route = inject(ActivatedRoute);
  private readonly studentDashboardService = inject(StudentDashboardService);
  private readonly admitCardService = inject(AdmitCardService);
  private readonly resultPublishingService = inject(ResultPublishingService);
  private readonly timetableService = inject(TimetableService);
  private readonly studentThemeService = inject(StudentThemeService);
  private readonly apiBase = environment.apiBaseUrl.replace(/\/$/, '');
  private readonly apiOrigin = new URL(environment.apiBaseUrl, window.location.origin).origin;
  private readonly apiPath = this.extractPath(environment.apiBaseUrl);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly vm = signal<StudentDashboardResponse | null>(null);
  readonly admitApi = signal<MyAdmitCardResponse | null>(null);
  readonly admitLoading = signal(false);
  readonly admitActionLoading = signal(false);
  readonly resultActionLoading = signal(false);
  readonly timetableActionLoading = signal(false);
  readonly section = computed(() => (this.route.snapshot.data['section'] as StudentSectionKey) || 'admit-card');
  readonly title = computed(() => (this.route.snapshot.data['title'] as string) || 'Student Section');
  readonly subtitle = computed(() => {
    const section = this.section();
    const subtitles: Record<StudentSectionKey, string> = {
      'admit-card': 'Check your latest exam admit card status and download it when published.',
      fee: 'Review your fee summary, payment progress, and pending balance.',
      result: 'See your latest published result details and exam performance.',
      timetable: 'Track your class schedule with subject, period, and teacher details.',
      'academic-history': 'View your year-wise class, section, and academic progression.',
      'attendance-history': 'Check month-wise attendance figures and overall percentage.',
    };

    return subtitles[section];
  });
  readonly sectionAccent = computed(() => {
    const accents: Record<StudentSectionKey, string> = {
      'admit-card': 'Exam Access',
      fee: 'Fee Visibility',
      result: 'Academic Trust',
      timetable: 'Daily Rhythm',
      'academic-history': 'Growth Story',
      'attendance-history': 'Attendance Trace',
    };

    return accents[this.section()];
  });
  readonly heroMetrics = computed(() => {
    const data = this.vm();
    if (!data) {
      return [];
    }

    const map: Record<StudentSectionKey, Array<{ label: string; value: string }>> = {
      'admit-card': [
        { label: 'Exam', value: this.admitExamName() },
        { label: 'Status', value: this.admitStatusLabel() },
        { label: 'Published', value: this.admitPublishedAt() },
      ],
      fee: [
        { label: 'Total Fee', value: data.fee_summary.total_fee.toFixed(2) },
        { label: 'Pending', value: data.fee_summary.pending_amount.toFixed(2) },
        { label: 'Receipt', value: data.fee_summary.last_receipt_number || '-' },
      ],
      result: [
        { label: 'Exam', value: data.result_section.latest_result?.exam_name || '-' },
        { label: 'Grade', value: data.result_section.latest_result?.grade || '-' },
        { label: 'Percent', value: data.result_section.latest_result ? data.result_section.latest_result.percentage.toFixed(2) : '-' },
      ],
      timetable: [
        { label: 'Days', value: String(data.timetable.days.length) },
        { label: 'Slots', value: String(data.timetable.slots.length) },
        { label: 'Rows', value: String(data.timetable.items.length) },
      ],
      'academic-history': [
        { label: 'Years', value: String(data.academic_history.items.length) },
        { label: 'Current Class', value: data.profile_summary.class || '-' },
        { label: 'Section', value: data.profile_summary.section || '-' },
      ],
      'attendance-history': [
        { label: 'Months', value: String(data.attendance_history.items.length) },
        { label: 'Current %', value: data.quick_stats.attendance_percent.toFixed(2) },
        { label: 'Month', value: data.attendance_overview.month },
      ],
    };

    return map[this.section()];
  });

  readonly sectionWidgetMap: Record<StudentSectionKey, string> = {
    'admit-card': 'admit_card',
    fee: 'fee',
    result: 'results',
    timetable: 'timetable',
    'academic-history': 'academic_history',
    'attendance-history': 'attendance_history',
  };

  readonly timetableMatrix = computed(() => {
    const timetable = this.vm()?.timetable;
    if (!timetable) {
      return [];
    }

    return (timetable.slots || []).map((slot) => ({
      slot,
      days: (timetable.days || []).map((day) => ({
        day,
        cell: (timetable.items || []).find((row) => row.day_key === day.value && row.time_slot_id === slot.id) || null,
      })),
    }));
  });

  ngOnInit() {
    this.load();

    if (this.section() === 'admit-card') {
      this.loadAdmit();
    }
  }

  hasPermission(): boolean {
    const data = this.vm();
    if (!data) {
      return false;
    }

    const widgetKey = this.sectionWidgetMap[this.section()];
    return !!data.widgets?.[widgetKey]?.enabled;
  }

  private load() {
    this.loading.set(true);
    this.error.set(null);

    this.studentDashboardService
      .getDashboard()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.vm.set(response);

          if (this.section() === 'admit-card') {
            const academicYearId = response.scope?.academic_year_id ?? undefined;
            this.loadAdmit(academicYearId);
          }
        },
        error: (err) => {
          // Keep the admit page usable even when the aggregate dashboard call fails.
          if (this.section() !== 'admit-card') {
            this.error.set(err?.error?.message || 'Unable to load student section.');
            return;
          }

          const admitError = this.admitApi()
            ? null
            : (err?.error?.message || 'Unable to load student section.');
          this.error.set(admitError);
        },
      });
  }

  private loadAdmit(academicYearId?: number) {
    this.admitLoading.set(true);

    this.admitCardService
      .myLatest({ academic_year_id: academicYearId })
      .pipe(finalize(() => this.admitLoading.set(false)))
      .subscribe({
        next: (response: MyAdmitCardResponse) => this.admitApi.set(response),
        error: (err: any) => {
          if (!this.vm()) {
            this.error.set(err?.error?.message || 'Unable to load admit card status.');
          }
        },
      });
  }

  hasAdmitData(): boolean {
    return this.section() === 'admit-card' && !!this.admitApi();
  }

  canDownloadAdmit(): boolean {
    const fromApi = this.admitApi();
    if (fromApi?.state === 'published' && fromApi?.admit_card?.id) {
      return true;
    }

    const dashboardAdmit = this.vm()?.admit_card;
    return dashboardAdmit?.status === 'published' && !!dashboardAdmit?.admit_card_id;
  }

  admitStatusLabel(): string {
    const state = this.admitApi()?.state || this.vm()?.admit_card?.status || 'not_generated';
    return state.replace(/_/g, ' ');
  }

  admitExamName(): string {
    return this.admitApi()?.admit_card?.exam_name || this.vm()?.admit_card?.exam_name || '-';
  }

  admitMessage(): string {
    return this.admitApi()?.message || this.vm()?.admit_card?.message || '-';
  }

  admitPublishedAt(): string {
    return this.admitApi()?.admit_card?.published_at || this.vm()?.admit_card?.published_at || '-';
  }

  isAdmitHidden(): boolean {
    const state = this.admitApi()?.state;
    const status = this.vm()?.admit_card?.status;
    return state === 'blocked' || status === 'blocked';
  }

  printAdmitCard() {
    const apiAdmit = this.admitApi()?.admit_card;
    const dashboardAdmit = this.vm()?.admit_card;
    const admitId = apiAdmit?.id ?? dashboardAdmit?.admit_card_id;
    const downloadUrl = apiAdmit?.download_url ?? dashboardAdmit?.download_url;

    if (!admitId && !downloadUrl) {
      this.error.set('No published admit card found.');
      return;
    }

    this.admitActionLoading.set(true);
    const request$ = downloadUrl
      ? this.admitCardService.downloadPaperByUrl(downloadUrl)
      : this.admitCardService.downloadPaper(admitId as number);

    request$
      .pipe(finalize(() => this.admitActionLoading.set(false)))
      .subscribe({
        next: (blob: Blob) => this.saveBlob(blob, `admit-${admitId ?? 'card'}.pdf`),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to download admit card.'),
      });
  }

  canDownloadResult(): boolean {
    const section = this.vm()?.result_section;
    return !!section?.download_available && !!section?.latest_result?.student_result_id;
  }

  downloadResultPaper() {
    const resultId = this.vm()?.result_section.latest_result?.student_result_id;
    if (!resultId) {
      this.error.set('No published result found.');
      return;
    }

    this.resultActionLoading.set(true);
    this.error.set(null);

    this.resultPublishingService
      .getResultPaper(resultId)
      .pipe(finalize(() => this.resultActionLoading.set(false)))
      .subscribe({
        next: async (payload) => {
          try {
            const blob = await this.buildResultPdfBlob(payload);
            this.saveBlob(blob, this.buildPaperFileName(payload));
          } catch (error) {
            this.error.set(error instanceof Error ? error.message : 'Unable to prepare result PDF.');
          }
        },
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to download result paper.'),
      });
  }

  isDarkMode(): boolean {
    return this.studentThemeService.isDark();
  }

  attendanceHistoryAverage(): string {
    const items = this.vm()?.attendance_history.items ?? [];
    if (!items.length) {
      return '0.00';
    }

    const total = items.reduce((sum, row) => sum + row.attendance_percentage, 0);
    return (total / items.length).toFixed(2);
  }

  downloadTimetable() {
    const academicYearId = this.vm()?.scope?.academic_year_id ?? undefined;
    this.timetableActionLoading.set(true);
    this.error.set(null);

    this.timetableService
      .downloadStudentTimetablePdf({ academic_year_id: academicYearId })
      .pipe(finalize(() => this.timetableActionLoading.set(false)))
      .subscribe({
        next: (blob: Blob) => this.saveBlob(blob, 'student-timetable.pdf'),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to download timetable.'),
      });
  }

  private saveBlob(blob: Blob, filename: string): void {
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

  private async buildResultPdfBlob(payload: PublishedResultPaperResponse): Promise<Blob> {
    const doc = await this.buildPaperPdf(payload);
    return doc.output('blob');
  }

  private gradeLabel(grade: string): string {
    const normalized = grade.trim().toUpperCase();
    return this.gradeScale.find((item) => item.grade === normalized)?.label || 'Performance Grade';
  }

  private isAbsentSubject(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): boolean {
    return Boolean(subject.is_absent)
      || (['A', 'ABS'].includes((subject.grade || '').trim().toUpperCase()) && Number(subject.obtained_marks) === 0);
  }

  private gradeForSubject(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): string {
    if (this.isAbsentSubject(subject)) {
      return 'ABS';
    }

    const explicitGrade = (subject.grade || '').trim().toUpperCase();
    if (explicitGrade) {
      return explicitGrade;
    }

    const maxMarks = Number(subject.max_marks);
    const obtainedMarks = Number(subject.obtained_marks);
    const percentage = maxMarks > 0 ? (obtainedMarks / maxMarks) * 100 : 0;

    return this.resolveGrade(null, percentage);
  }

  private formatSubjectMarks(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): string {
    if (this.isAbsentSubject(subject)) {
      return 'ABS';
    }

    return String(subject.obtained_marks);
  }

  private async buildPaperPdf(payload: PublishedResultPaperResponse) {
    const { jsPDF } = await import('jspdf');
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const paper = payload.result_paper;
    const school = payload.school;
    const logoImage = await this.loadPaperImageAsDataUrl(school.logo_data_url || school.logo_url || undefined);
    const watermarkLogoImage = await this.loadPaperImageAsDataUrl(
      school.watermark_logo_data_url || school.watermark_logo_url || school.logo_data_url || school.logo_url || undefined
    );
    const studentImage = await this.loadPaperImageAsDataUrl(paper.photo_data_url || paper.photo_url || undefined);
    const qrImage = await this.loadPaperImageAsDataUrl(
      paper.qr_verify_url
        ? `https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=${encodeURIComponent(paper.qr_verify_url)}`
        : undefined
    );
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 26;
    const contentWidth = pageWidth - margin * 2;
    const palette = {
      navy: [15, 39, 71] as const,
      blue: [29, 78, 216] as const,
      gold: [202, 165, 90] as const,
      slate: [71, 85, 105] as const,
      line: [213, 221, 232] as const,
      soft: [246, 249, 255] as const,
      white: [255, 255, 255] as const,
      ink: [15, 23, 42] as const,
      green: [22, 101, 52] as const,
      red: [185, 28, 28] as const,
    };
    const setFill = (color: readonly [number, number, number]) => doc.setFillColor(color[0], color[1], color[2]);
    const setDraw = (color: readonly [number, number, number]) => doc.setDrawColor(color[0], color[1], color[2]);
    const setText = (color: readonly [number, number, number]) => doc.setTextColor(color[0], color[1], color[2]);
    let y = margin;

    const ensurePageSpace = (height: number) => {
      if (y + height <= pageHeight - margin) {
        return;
      }

      doc.addPage();
      this.drawWatermark(doc, pageWidth, pageHeight, watermarkLogoImage);
      y = margin;
    };

    this.drawWatermark(doc, pageWidth, pageHeight, watermarkLogoImage);

    setFill(palette.white);
    setDraw(palette.line);
    doc.roundedRect(margin, margin, contentWidth, pageHeight - margin * 2, 18, 18, 'FD');

    setFill(palette.navy);
    doc.roundedRect(margin, margin, contentWidth, 108, 18, 18, 'F');
    doc.setFillColor(255, 255, 255, 0.08);
    doc.circle(pageWidth - 54, margin + 26, 42, 'F');
    doc.circle(pageWidth - 92, margin + 82, 24, 'F');

    if (logoImage) {
      const fittedLogo = await this.fitImage(logoImage, 70, 70);
      doc.addImage(
        logoImage,
        this.detectImageFormat(logoImage),
        margin + 16,
        margin + 16,
        fittedLogo.width,
        fittedLogo.height
      );
    }

    const schoolLeft = margin + 100;
    doc.setFont('helvetica', 'bold');
    setText(palette.white);
    doc.setFontSize(24);
    doc.text((school.name || 'School').slice(0, 52), schoolLeft, margin + 36);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10.5);
    doc.text(doc.splitTextToSize((school.address || '-').slice(0, 120), contentWidth - 220), schoolLeft, margin + 55);
    doc.text(`Phone: ${school.phone || school.mobile_number_1 || '-'}`, schoolLeft, margin + 78);
    doc.text(`Website: ${school.website || '-'}`, schoolLeft, margin + 93);

    const rightX = pageWidth - margin - 134;
    setFill(palette.gold);
    doc.roundedRect(rightX, margin + 16, 118, 32, 10, 10, 'F');
    doc.setFont('helvetica', 'bold');
    setText(palette.navy);
    doc.setFontSize(9.5);
    doc.text('PUBLISHED RESULT', rightX + 59, margin + 36, { align: 'center' });
    doc.setFont('helvetica', 'normal');
    setText(palette.white);
    doc.setFontSize(9);
    doc.text(`Exam: ${(paper.exam_name || '-').slice(0, 20)}`, rightX, margin + 63);
    doc.text(`Year: ${(paper.academic_year || '-').slice(0, 18)}`, rightX, margin + 78);
    doc.text(`Published: ${this.formatPaperDate(paper.published_at)}`, rightX, margin + 93);

    y = margin + 124;
    const overallGrade = this.resolveGrade(paper.grade, paper.percentage, paper.result_status);
    const toneColor = overallGrade.startsWith('A')
      ? palette.gold
      : overallGrade.startsWith('B')
        ? palette.green
        : paper.result_status?.toLowerCase() === 'fail'
          ? palette.red
          : palette.blue;

    setFill(palette.soft);
    setDraw(palette.line);
    doc.roundedRect(margin, y, contentWidth, 136, 18, 18, 'FD');

    const photoBoxX = margin + 16;
    const photoBoxY = y + 16;
    const photoBoxSize = 94;
    setFill(palette.white);
    setDraw(palette.line);
    doc.roundedRect(photoBoxX, photoBoxY, photoBoxSize, photoBoxSize, 18, 18, 'FD');
    if (studentImage) {
      const fittedPhoto = await this.fitImage(studentImage, photoBoxSize - 12, photoBoxSize - 12);
      doc.addImage(
        studentImage,
        this.detectImageFormat(studentImage),
        photoBoxX + ((photoBoxSize - fittedPhoto.width) / 2),
        photoBoxY + ((photoBoxSize - fittedPhoto.height) / 2),
        fittedPhoto.width,
        fittedPhoto.height
      );
    } else {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(10);
      setText(palette.slate);
      doc.text('PHOTO', photoBoxX + (photoBoxSize / 2), photoBoxY + 46, { align: 'center' });
      doc.text('N/A', photoBoxX + (photoBoxSize / 2), photoBoxY + 60, { align: 'center' });
    }

    const infoX = photoBoxX + photoBoxSize + 18;
    const infoWidth = contentWidth - photoBoxSize - 152;
    setFill(palette.white);
    doc.roundedRect(infoX, photoBoxY, infoWidth, 102, 16, 16, 'F');

    doc.setFont('helvetica', 'bold');
    setText(palette.navy);
    doc.setFontSize(22);
    doc.text((paper.student_name || 'Student').slice(0, 36), infoX + 16, photoBoxY + 28);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    setText(palette.slate);
    doc.text(`Parent: ${(paper.parents_name || '-').slice(0, 42)}`, infoX + 16, photoBoxY + 48);
    doc.text(`Class: ${(paper.class_name || '-').slice(0, 20)}`, infoX + 16, photoBoxY + 64);
    doc.text(`Roll No: ${paper.roll_number || paper.enrollment_number || '-'}`, infoX + 16, photoBoxY + 80);
    doc.text(`Registration: ${(paper.registration_number || '-').slice(0, 24)}`, infoX + 16, photoBoxY + 96);

    const gradePillX = margin + contentWidth - 106;
    setFill(toneColor);
    doc.roundedRect(gradePillX, photoBoxY, 90, 34, 14, 14, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    setText(palette.white);
    doc.text(`GRADE ${overallGrade}`, gradePillX + 45, photoBoxY + 21, { align: 'center' });

    setFill(palette.white);
    setDraw(palette.line);
    doc.roundedRect(gradePillX, photoBoxY + 44, 90, 58, 14, 14, 'FD');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    setText(toneColor);
    doc.text(`${paper.percentage.toFixed(2)}%`, gradePillX + 45, photoBoxY + 68, { align: 'center' });
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    setText(palette.slate);
    doc.text(this.gradeLabel(overallGrade), gradePillX + 45, photoBoxY + 84, { align: 'center' });
    doc.text((paper.result_status || 'pending').toUpperCase(), gradePillX + 45, photoBoxY + 96, { align: 'center' });

    y += 152;
    ensurePageSpace(152);

    const metaRows: Array<[string, string]> = [
      ['Serial Number', String(paper.serial_number)],
      ['Exam Name', paper.exam_name || '-'],
      ['Academic Year', paper.academic_year || '-'],
      ['Enrollment No.', String(paper.enrollment_number || '-')],
      ['Published At', this.formatPaperDate(paper.published_at)],
      ['Address', paper.address || '-'],
    ];

    setFill(palette.soft);
    doc.roundedRect(margin + 12, y + 12, contentWidth - 24, 108, 14, 14, 'F');
    doc.setFontSize(11);
    const leftLabelX = margin + 24;
    const leftValueX = margin + 118;
    const rightLabelX = margin + 294;
    const rightValueX = margin + 392;
    y += 30;
    for (let i = 0; i < metaRows.length; i += 2) {
      const left = metaRows[i];
      const right = metaRows[i + 1];
      doc.setFont('helvetica', 'bold');
      setText(palette.slate);
      doc.text(`${left[0]}:`, leftLabelX, y);
      doc.setFont('helvetica', 'normal');
      setText(palette.ink);
      doc.text(doc.splitTextToSize(left[1], 155).slice(0, 1), leftValueX, y);

      if (right) {
        doc.setFont('helvetica', 'bold');
        setText(palette.slate);
        doc.text(`${right[0]}:`, rightLabelX, y);
        doc.setFont('helvetica', 'normal');
        setText(palette.ink);
        doc.text(doc.splitTextToSize(right[1], 130).slice(0, 1), rightValueX, y);
      }

      y += 20;
    }
    y += 10;
    const columns = [
      { label: '#', width: 24, headerLines: ['#'] },
      { label: 'Subject', width: 160, headerLines: ['Subject'] },
      { label: 'Code', width: 72, headerLines: ['Code'] },
      { label: 'Total Marks', width: 64, headerLines: ['Total', 'Marks'] },
      { label: 'Passing Marks', width: 68, headerLines: ['Passing', 'Marks'] },
      { label: 'Obtained Marks', width: 78, headerLines: ['Obtained', 'Marks'] },
      { label: 'Grade', width: 56, headerLines: ['Grade'] },
    ];
    const rowHeight = 20;
    const headerRowHeight = 26;

    const drawTableHeader = () => {
      let x = margin;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9.5);
      columns.forEach((column) => {
        const headerLines = column.headerLines || [column.label];
        doc.setFillColor(255, 255, 255);
        doc.setTextColor(15, 39, 71);
        doc.setDrawColor(190, 204, 224);
        doc.roundedRect(x, y - 11, column.width, headerRowHeight, 2, 2, 'FD');
        const lineY = headerLines.length > 1 ? [y - 1, y + 8] : [y + 4];
        headerLines.forEach((line, index) => {
          doc.text(line, x + (column.width / 2), lineY[index] ?? y + 4, { align: 'center' });
        });
        x += column.width;
      });
      y += headerRowHeight;
    };

    drawTableHeader();

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    paper.subjects.forEach((subject, index) => {
      if (y > pageHeight - 146) {
        doc.addPage();
        this.drawWatermark(doc, pageWidth, pageHeight, watermarkLogoImage);
        y = 34;
        drawTableHeader();
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(10);
      }

      const values = [
        String(index + 1),
        subject.subject_name || '-',
        subject.subject_code || '-',
        String(subject.max_marks),
        String(subject.passing_marks ?? 0),
        this.formatSubjectMarks(subject),
        this.gradeForSubject(subject),
      ];

      let x = margin;
      const isEven = index % 2 === 0;
      columns.forEach((column, columnIndex) => {
        const cellFill = isEven ? palette.white : ([248, 251, 255] as const);
        setFill(cellFill);
        setDraw(palette.line);
        doc.rect(x, y - 11, column.width, rowHeight, 'FD');
        const maxLength = columnIndex === 1 ? 30 : 12;
        if (columnIndex === 0) {
          setText(palette.slate);
        } else {
          setText(palette.ink);
        }
        const align = columnIndex === 1 || columnIndex === 2 ? 'left' : 'center';
        const textX = align === 'center' ? x + (column.width / 2) : x + 4;
        doc.text(values[columnIndex].slice(0, maxLength), textX, y + 1, { align: align as 'left' | 'center' });
        x += column.width;
      });
      y += rowHeight + 1;
    });

    ensurePageSpace(172);
    y += 14;
    const summaryTop = y;
    const qrBoxSize = 68;
    const gap = 8;
    const summaryWidth = contentWidth - qrBoxSize - gap;
    const summaryCardWidth = (summaryWidth - (gap * 2)) / 3;
    const summaryCardY = summaryTop;

    [
      { label: 'Total Marks', value: String(paper.total_max_marks), note: 'Maximum score' },
      { label: 'Obtained', value: String(paper.total_marks), note: `${paper.percentage}% secured` },
      { label: 'Grade', value: overallGrade, note: `${this.gradeLabel(overallGrade)} performance` },
    ].forEach((item, index) => {
      const x = margin + (summaryCardWidth + gap) * index;
      setFill(palette.white);
      setDraw(palette.line);
      doc.roundedRect(x, summaryCardY, summaryCardWidth, 62, 12, 12, 'FD');
      setFill(index === 2 ? toneColor : palette.soft);
      doc.roundedRect(x + 8, summaryCardY + 8, summaryCardWidth - 16, 18, 8, 8, 'F');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8.5);
      setText(index === 2 ? palette.white : palette.slate);
      doc.text(item.label.toUpperCase(), x + 16, summaryCardY + 20);
      doc.setFontSize(16);
      setText(palette.ink);
      doc.text(item.value, x + 16, summaryCardY + 40);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      setText(palette.slate);
      doc.text(item.note, x + 16, summaryCardY + 52);
    });

    setFill(palette.white);
    setDraw(palette.line);
    const qrX = pageWidth - margin - qrBoxSize;
    const qrY = summaryTop;
    doc.roundedRect(qrX, qrY, qrBoxSize, 62, 12, 12, 'FD');
    if (qrImage) {
      doc.addImage(qrImage, this.detectImageFormat(qrImage), qrX + 6, qrY + 6, qrBoxSize - 12, qrBoxSize - 12);
    } else {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8.5);
      setText(palette.slate);
      doc.text('QR', qrX + qrBoxSize / 2, qrY + 28, { align: 'center' });
      doc.text('N/A', qrX + qrBoxSize / 2, qrY + 39, { align: 'center' });
    }

    y = summaryTop + 72;
    ensurePageSpace(130);

    setFill(palette.white);
    setDraw(palette.line);
    doc.roundedRect(margin, y, contentWidth, 104, 12, 12, 'FD');
    setFill(palette.soft);
    doc.roundedRect(margin + 8, y + 8, contentWidth - 16, 16, 8, 8, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    setText(palette.navy);
    doc.text('EIGHT POINT GRADING SCALE', margin + 14, y + 19);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    setText(palette.ink);
    doc.text('A1 (91-100), A2 (81-90), B1 (71-80), B2 (61-70),', margin + 14, y + 34);
    doc.text('C1 (51-60), C2 (41-50), D (33-40), E (0-32)', margin + 14, y + 45);

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    setText(palette.navy);
    doc.text("CLASS TEACHER'S REMARKS", margin + 14, y + 61);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8.5);
    setText(palette.ink);
    doc.text('Promotion granted', margin + 14, y + 74);
    doc.text('Date: 20/03/2026', margin + 14, y + 86);

    const signatureTop = y + 82;
    const signatureWidth = 138;
    const teacherSignatureX = margin + 210;
    const principalSignatureX = pageWidth - margin - signatureWidth;
    setDraw(palette.slate);
    doc.line(teacherSignatureX, signatureTop, teacherSignatureX + signatureWidth, signatureTop);
    doc.line(principalSignatureX, signatureTop, principalSignatureX + signatureWidth, signatureTop);
    doc.setFontSize(8);
    setText(palette.slate);
    doc.text('Signature Of Teacher', teacherSignatureX, signatureTop + 10);
    doc.text('Signature Of Principal', principalSignatureX, signatureTop + 10);

    y += 114;

    const footerNote = 'Disclaimer: This is a computer generated statement and hence no signature is required. The school is not responsible for any inadvertent error that may have crept in while generating the certificate.';
    const footerLines = doc.splitTextToSize(footerNote, contentWidth - 18);
    setDraw(palette.line);
    doc.line(margin, y, pageWidth - margin, y);
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    setText(palette.slate);
    doc.text(footerLines, margin + 9, y + 14);

    const footerMetaY = y + 14 + (footerLines.length * 9) + 6;
    doc.setFontSize(8.5);
    doc.text(`Passing Marks: ${paper.total_passing_marks ?? 0}   |   Rank: ${paper.rank ?? '-'}`, margin + 9, footerMetaY);
    doc.text('Verified digital result statement', pageWidth - margin, footerMetaY, { align: 'right' });

    return doc;
  }

  private drawWatermark(doc: any, pageWidth: number, pageHeight: number, watermarkLogo?: string | null) {
    if (watermarkLogo) {
      const size = Math.min(pageWidth, pageHeight) * 0.18;
      const xPositions = [42, (pageWidth - size) / 2, pageWidth - size - 42];
      const yPositions = [96, 260, 424, 588];
      doc.saveGraphicsState?.();
      const gStateCtor = (doc as any).GState;
      if (typeof gStateCtor === 'function' && typeof doc.setGState === 'function') {
        doc.setGState(new gStateCtor({ opacity: 0.03 }));
      }
      yPositions.forEach((positionY) => {
        xPositions.forEach((positionX) => {
          doc.addImage(watermarkLogo, this.detectImageFormat(watermarkLogo), positionX, positionY, size, size);
        });
      });
      doc.restoreGraphicsState?.();
    }
  }

  private async loadPaperImageAsDataUrl(url: string | null | undefined): Promise<string | null> {
    if (!url) {
      return null;
    }

    if (url.startsWith('data:')) {
      return url;
    }

    const candidates = this.buildImageCandidates(url);
    const authHeaders = this.getAuthHeaders();

    for (const candidate of candidates) {
      try {
        const shouldUseAuth = candidate.startsWith(this.apiBase) || candidate.startsWith(this.apiPath);
        const response = await fetch(candidate, {
          mode: 'cors',
          credentials: shouldUseAuth ? 'include' : 'omit',
          headers: shouldUseAuth ? authHeaders : undefined
        });

        if (!response.ok) {
          continue;
        }

        const blob = await response.blob();
        if (blob.type && !blob.type.toLowerCase().startsWith('image/') && blob.type !== 'application/octet-stream') {
          continue;
        }

        const dataUrl = await this.blobToDataUrl(blob);
        if (dataUrl) {
          return dataUrl;
        }
      } catch {
        // Try next candidate.
      }
    }

    return null;
  }

  private buildImageCandidates(url: string): string[] {
    const values = new Set<string>();
    const normalized = url.trim();

    const proxiedInput = this.toProxiedPath(normalized);
    if (proxiedInput) {
      values.add(proxiedInput);
    }

    values.add(normalized);

    if (normalized.startsWith('/')) {
      values.add(`${this.apiOrigin}${normalized}`);
    } else if (!normalized.startsWith('http://') && !normalized.startsWith('https://')) {
      values.add(`${this.apiOrigin}/${normalized.replace(/^\/+/, '')}`);
    }

    if (normalized.includes('/public/storage/')) {
      values.add(normalized.replace('/public/storage/', '/storage/'));
    }

    if (normalized.includes('/storage/')) {
      values.add(normalized.replace('/storage/', '/public/storage/'));
    }

    const relativePath = normalized.replace(/^\/+/, '');
    if (relativePath.startsWith('public/storage/')) {
      const storagePath = relativePath.replace(/^public\/storage\//, '');
      values.add(`${this.apiOrigin}/public/storage/${storagePath}`);
      values.add(`${this.apiOrigin}/storage/${storagePath}`);
    } else if (relativePath.startsWith('storage/')) {
      const storagePath = relativePath.replace(/^storage\//, '');
      values.add(`${this.apiOrigin}/storage/${storagePath}`);
      values.add(`${this.apiOrigin}/public/storage/${storagePath}`);
    }

    return Array.from(values);
  }

  private toProxiedPath(url: string): string | null {
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      return url.startsWith('/') ? url : `/${url}`;
    }

    try {
      const parsed = new URL(url);
      if (parsed.origin !== this.apiOrigin) {
        return null;
      }

      return `${parsed.pathname}${parsed.search}`;
    } catch {
      return null;
    }
  }

  private extractPath(url: string): string {
    try {
      const parsed = new URL(url, window.location.origin);
      const path = parsed.pathname.replace(/\/$/, '');
      return path || '/';
    } catch {
      return '/api/v1';
    }
  }

  private async blobToDataUrl(blob: Blob | null | undefined): Promise<string | null> {
    if (!blob) {
      return null;
    }

    const dataUrl = await new Promise<string | null>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve((reader.result as string) || null);
      reader.onerror = () => resolve(null);
      reader.readAsDataURL(blob);
    });

    if (!dataUrl) {
      return null;
    }

    const normalized = await this.normalizeImageDataUrl(dataUrl, blob);
    return this.ensurePdfCompatibleImageDataUrl(normalized);
  }

  private async normalizeImageDataUrl(dataUrl: string, blob: Blob): Promise<string> {
    if (!dataUrl.startsWith('data:application/octet-stream')) {
      return dataUrl;
    }

    const inferredMime = await this.inferImageMimeType(blob);
    return inferredMime
      ? dataUrl.replace('data:application/octet-stream', `data:${inferredMime}`)
      : dataUrl;
  }

  private async ensurePdfCompatibleImageDataUrl(dataUrl: string): Promise<string> {
    if (dataUrl.startsWith('data:image/png') || dataUrl.startsWith('data:image/jpeg')) {
      return dataUrl;
    }

    try {
      return await this.rasterizeImageDataUrl(dataUrl, 'image/png');
    } catch {
      return dataUrl;
    }
  }

  private rasterizeImageDataUrl(dataUrl: string, targetMimeType: 'image/png' | 'image/jpeg'): Promise<string> {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => {
        try {
          const canvas = document.createElement('canvas');
          canvas.width = image.naturalWidth || image.width || 1;
          canvas.height = image.naturalHeight || image.height || 1;

          const context = canvas.getContext('2d');
          if (!context) {
            reject(new Error('Canvas context unavailable'));
            return;
          }

          if (targetMimeType === 'image/jpeg') {
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
          }

          context.drawImage(image, 0, 0, canvas.width, canvas.height);
          resolve(canvas.toDataURL(targetMimeType));
        } catch (error) {
          reject(error);
        }
      };
      image.onerror = () => reject(new Error('Image load failed'));
      image.src = dataUrl;
    });
  }

  private async inferImageMimeType(blob: Blob): Promise<string | null> {
    const bytes = new Uint8Array(await blob.slice(0, 16).arrayBuffer());

    if (bytes.length >= 4 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
      return 'image/png';
    }

    if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
      return 'image/jpeg';
    }

    if (
      bytes.length >= 12 &&
      bytes[0] === 0x52 &&
      bytes[1] === 0x49 &&
      bytes[2] === 0x46 &&
      bytes[3] === 0x46 &&
      bytes[8] === 0x57 &&
      bytes[9] === 0x45 &&
      bytes[10] === 0x42 &&
      bytes[11] === 0x50
    ) {
      return 'image/webp';
    }

    if (bytes.length >= 4 && bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x38) {
      return 'image/gif';
    }

    return null;
  }

  private getAuthHeaders(): Record<string, string> {
    try {
      const raw = localStorage.getItem('sms_auth_session');
      if (!raw) {
        return {};
      }

      const parsed = JSON.parse(raw) as { token?: string };
      return parsed?.token ? { Authorization: `Bearer ${parsed.token}` } : {};
    } catch {
      return {};
    }
  }

  private detectImageFormat(dataUrl: string): 'PNG' | 'JPEG' {
    if (dataUrl.startsWith('data:image/png')) {
      return 'PNG';
    }

    return 'JPEG';
  }

  private fitImage(dataUrl: string, maxWidth: number, maxHeight: number): Promise<{ width: number; height: number }> {
    return new Promise((resolve) => {
      const image = new Image();
      image.onload = () => {
        const sourceWidth = image.naturalWidth || image.width || maxWidth;
        const sourceHeight = image.naturalHeight || image.height || maxHeight;
        const scale = Math.min(maxWidth / sourceWidth, maxHeight / sourceHeight);
        resolve({
          width: Math.max(1, sourceWidth * scale),
          height: Math.max(1, sourceHeight * scale),
        });
      };
      image.onerror = () => resolve({ width: maxWidth, height: maxHeight });
      image.src = dataUrl;
    });
  }

  private buildPaperFileName(payload: PublishedResultPaperResponse): string {
    const paper = payload.result_paper;
    const safe = [
      'result',
      paper.student_name,
      paper.exam_name,
      paper.class_name
    ]
      .join('_')
      .replace(/[^a-zA-Z0-9_-]+/g, '_')
      .replace(/_+/g, '_')
      .replace(/^_|_$/g, '')
      .toLowerCase();

    return `${safe || 'result-paper'}.pdf`;
  }

  private formatPaperDate(value: string | null | undefined): string {
    if (!value) {
      return '-';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
      return value;
    }

    return new Intl.DateTimeFormat('en-IN', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    }).format(parsed);
  }

  private resolveGrade(explicitGrade: string | null | undefined, percentage: number, resultStatus?: string | null): string {
    const normalizedGrade = (explicitGrade || '').trim().toUpperCase();
    if (normalizedGrade) {
      return normalizedGrade;
    }

    if ((resultStatus || '').trim().toLowerCase() === 'fail') {
      return 'F';
    }

    return this.gradeScale.find((item) => percentage >= item.minPercentage)?.grade || 'F';
  }

}
