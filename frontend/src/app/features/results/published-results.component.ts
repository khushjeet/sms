import { DatePipe, DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ClassesService } from '../../core/services/classes.service';
import { AuthService } from '../../core/services/auth.service';
import { ResultPublishingService } from '../../core/services/result-publishing.service';
import { AuditDownloadsService } from '../../core/services/audit-downloads.service';
import {
  MissingPublishedStudent,
  PublishedResultPaperResponse,
  PublishedResultRow
} from '../../models/result-publishing';
import { ClassModel } from '../../models/class';

@Component({
  selector: 'app-published-results',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule, DatePipe, DecimalPipe],
  templateUrl: './published-results.component.html',
  styleUrl: './published-results.component.scss'
})
export class PublishedResultsComponent {
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

  readonly eightPointGradeScale = [
    'A1 (91-100)',
    'A2 (81-90)',
    'B1 (71-80)',
    'B2 (61-70)',
    'C1 (51-60)',
    'C2 (41-50)',
    'D (33-40)',
    'E (0-32)',
  ] as const;

  private readonly auth = inject(AuthService);
  private readonly resultPublishingService = inject(ResultPublishingService);
  private readonly classesService = inject(ClassesService);
  private readonly auditDownloadsService = inject(AuditDownloadsService);
  private readonly router = inject(Router);
  private readonly apiBase = environment.apiBaseUrl.replace(/\/$/, '');
  private readonly apiOrigin = new URL(environment.apiBaseUrl, window.location.origin).origin;
  private readonly apiPath = this.extractPath(environment.apiBaseUrl);

  readonly isSuperAdmin = computed(() => this.auth.user()?.role === 'super_admin');
  readonly isTeacher = computed(() => this.auth.user()?.role === 'teacher');
  readonly canPublish = computed(() => this.isSuperAdmin());
  readonly showGradeBands = computed(() => this.isSuperAdmin() || this.isTeacher());

  readonly loading = signal(false);
  readonly loadingPaper = signal(false);
  readonly generatingPdf = signal(false);
  readonly loadingSessions = signal(false);
  readonly publishingClass = signal(false);
  readonly rows = signal<PublishedResultRow[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sessions = signal<Array<{ id: number; name: string; class_id: number; academic_year_id: number; class_name?: string | null; status: string; latest_marked_on?: string | null; finalized_compiled_rows?: number }>>([]);
  readonly selectedClassId = signal<string>('');
  readonly selectedSessionId = signal<string>('');
  readonly markedOn = signal<string>(new Date().toISOString().slice(0, 10));
  readonly publishReason = signal<string>('');
  readonly search = signal('');
  readonly selectedPaper = signal<PublishedResultPaperResponse | null>(null);
  readonly visibilityActionLoadingIds = signal<number[]>([]);
  readonly missingStudents = signal<MissingPublishedStudent[]>([]);
  readonly missingExamConfigurationId = signal<number | null>(null);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly hiddenResultNotice = signal<string | null>(null);

  ngOnInit() {
    if (this.isSuperAdmin()) {
      this.loadClasses();
    }

    this.loadSessions();
    this.loadPublished();
  }

  loadClasses() {
    this.classesService.list({ per_page: 300 }).subscribe({
      next: (response) => this.classes.set(response.data || []),
      error: () => {
        // keep screen usable even if classes fail
      }
    });
  }

  onClassChange(value: string) {
    this.selectedClassId.set(value);
    this.selectedSessionId.set('');
    this.loadSessions(Number(value) || undefined);
    this.loadPublished();
  }

  loadSessions(classId?: number) {
    this.loadingSessions.set(true);
    this.resultPublishingService.listPublishedSessions({
      class_id: classId,
    }).subscribe({
      next: (response) => {
        const data = response.data || [];
        this.sessions.set(data.map((item) => ({
          id: item.id,
          name: item.name,
          class_id: item.class_id,
          academic_year_id: item.academic_year_id,
          class_name: item.class_name,
          status: item.status,
          latest_marked_on: item.latest_marked_on,
          finalized_compiled_rows: item.finalized_compiled_rows,
        })));

        if (this.isTeacher()) {
          const classMap = new Map<number, ClassModel>();
          data.forEach((item) => {
            const classId = Number(item.class_id || 0);
            if (!classId || classMap.has(classId)) {
              return;
            }

            classMap.set(classId, {
              id: classId,
              name: item.class_name || `Class ${classId}`,
            } as ClassModel);
          });
          this.classes.set(Array.from(classMap.values()));
        }

        this.loadingSessions.set(false);
      },
      error: (err) => {
        this.loadingSessions.set(false);
        this.error.set(err?.error?.message || 'Unable to load exam sessions from backend.');
      }
    });
  }

  refreshSessions() {
    this.loadSessions(Number(this.selectedClassId()) || undefined);
  }

  onSessionChange(value: string) {
    this.selectedSessionId.set(value);
    this.loadPublished();
  }

  publishClassWise() {
    const classId = Number(this.selectedClassId());
    const sessionId = Number(this.selectedSessionId());
    if (!classId || !sessionId) {
      this.error.set('Select class and exam session to publish class-wise result.');
      return;
    }

    this.publishingClass.set(true);
    this.error.set(null);
    this.message.set(null);
    this.missingStudents.set([]);
    this.missingExamConfigurationId.set(null);

    this.resultPublishingService.publishClassWise({
      class_id: classId,
      exam_session_id: sessionId,
      marked_on: this.markedOn() || undefined,
      reason: this.publishReason().trim() || undefined
    }).subscribe({
      next: (response) => {
        this.publishingClass.set(false);
        this.message.set(response.message || 'Class-wise result published.');
        this.loadPublished();
      },
      error: (err) => {
        this.publishingClass.set(false);
        this.error.set(err?.error?.message || 'Unable to publish class-wise result.');
        this.missingStudents.set(err?.error?.missing_students || []);
        this.missingExamConfigurationId.set(Number(err?.error?.exam_configuration_id || 0) || null);
      }
    });
  }

  loadPublished() {
    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);
    this.hiddenResultNotice.set(null);
    this.missingStudents.set([]);
    this.missingExamConfigurationId.set(null);

    this.resultPublishingService.listPublished({
      class_id: Number(this.selectedClassId()) || undefined,
      exam_session_id: Number(this.selectedSessionId()) || undefined,
      search: this.search().trim() || undefined,
      per_page: 100,
    }).subscribe({
      next: (response) => {
        this.rows.set(response.data || []);
        this.hiddenResultNotice.set(response.hidden_result_notice || null);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load published results.');
      }
    });
  }

  fixMissingStudentMarks(student: MissingPublishedStudent, subjectId?: number) {
    const classId = Number(this.selectedClassId() || 0);
    const sessionId = Number(this.selectedSessionId() || 0);
    const examConfigurationId = this.missingExamConfigurationId();
    if (!classId || !examConfigurationId || !subjectId) {
      this.error.set('Missing class, exam, or subject context for mark correction.');
      return;
    }

    this.router.navigate(['/admin/assign-marks'], {
      queryParams: {
        class_id: classId,
        academic_year_id: this.sessions().find((session) => session.id === sessionId)?.academic_year_id,
        subject_id: subjectId,
        exam_configuration_id: examConfigurationId,
        marked_on: this.markedOn(),
        enrollment_ids: String(student.enrollment_id),
      }
    });
  }

  viewPaper(row: PublishedResultRow) {
    this.loadingPaper.set(true);
    this.error.set(null);
    this.message.set(null);

    this.resultPublishingService.getResultPaper(row.id).subscribe({
      next: (response) => {
        this.selectedPaper.set(response);
        this.loadingPaper.set(false);
      },
      error: (err) => {
        this.loadingPaper.set(false);
        this.error.set(err?.error?.message || 'Unable to load result paper.');
      }
    });
  }

  closePaper() {
    this.selectedPaper.set(null);
  }

  hideResult(row: PublishedResultRow) {
    this.setResultVisibility(row, 'withheld');
  }

  showResult(row: PublishedResultRow) {
    this.setResultVisibility(row, 'visible');
  }

  isVisibilityActionLoading(studentResultId: number): boolean {
    return this.visibilityActionLoadingIds().includes(studentResultId);
  }

  async downloadPaperPdf() {
    try {
      const payload = await this.resolvePaperPayload();
      const doc = await this.generatePaperPdf(payload);
      const fileName = this.buildPaperFileName(payload);
      const blob = doc.output('blob');
      this.downloadBlob(blob, fileName);
      this.logPaperDownload(payload, fileName, blob);
    } catch (error) {
      this.handlePaperPdfError(error, 'Unable to generate result PDF.');
    }
  }

  async printPaperPdf() {
    try {
      const payload = await this.resolvePaperPayload();
      const doc = await this.generatePaperPdf(payload);
      const blob = doc.output('blob');
      const url = URL.createObjectURL(blob);
      const frame = document.createElement('iframe');
      frame.style.position = 'fixed';
      frame.style.width = '0';
      frame.style.height = '0';
      frame.style.border = '0';
      frame.src = url;
      document.body.appendChild(frame);
      frame.onload = () => {
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
        setTimeout(() => {
          URL.revokeObjectURL(url);
          document.body.removeChild(frame);
        }, 1000);
      };
    } catch (error) {
      this.handlePaperPdfError(error, 'Unable to prepare print PDF.');
    }
  }

  async downloadRowPaperPdf(row: PublishedResultRow) {
    try {
      const payload = await this.loadPaperPayload(row.id);
      this.selectedPaper.set(payload);
      const doc = await this.generatePaperPdf(payload);
      const fileName = this.buildPaperFileName(payload);
      const blob = doc.output('blob');
      this.downloadBlob(blob, fileName);
      this.logPaperDownload(payload, fileName, blob);
    } catch (error) {
      this.handlePaperPdfError(error, 'Unable to generate result PDF.');
    }
  }

  async printRowPaperPdf(row: PublishedResultRow) {
    try {
      const payload = await this.loadPaperPayload(row.id);
      this.selectedPaper.set(payload);
      const doc = await this.generatePaperPdf(payload);
      const blob = doc.output('blob');
      const url = URL.createObjectURL(blob);
      const frame = document.createElement('iframe');
      frame.style.position = 'fixed';
      frame.style.width = '0';
      frame.style.height = '0';
      frame.style.border = '0';
      frame.src = url;
      document.body.appendChild(frame);
      frame.onload = () => {
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
        setTimeout(() => {
          URL.revokeObjectURL(url);
          document.body.removeChild(frame);
        }, 1000);
      };
    } catch (error) {
      this.handlePaperPdfError(error, 'Unable to prepare print PDF.');
    }
  }

  gradeForRow(row: PublishedResultRow): string {
    return this.resolveGrade(row.grade, row.percentage, row.result_status);
  }

  gradeForPaper(): string {
    const paper = this.selectedPaper()?.result_paper;
    if (!paper) {
      return '-';
    }

    return this.resolveGrade(paper.grade, paper.percentage, paper.result_status);
  }

  gradeLabel(grade: string): string {
    const normalized = grade.trim().toUpperCase();
    return this.gradeScale.find((item) => item.grade === normalized)?.label || 'Performance Grade';
  }

  eightPointGradeScaleText(): string {
    return this.eightPointGradeScale.join(', ');
  }

  gradeTone(grade: string, resultStatus?: string | null): 'excellent' | 'good' | 'average' | 'risk' {
    const normalizedGrade = grade.trim().toUpperCase();
    const normalizedStatus = (resultStatus || '').trim().toLowerCase();

    if (normalizedStatus === 'fail' || normalizedGrade === 'F' || normalizedGrade === 'D') {
      return 'risk';
    }

    if (normalizedGrade === 'A1' || normalizedGrade === 'A2') {
      return 'excellent';
    }

    if (normalizedGrade === 'B1' || normalizedGrade === 'B2') {
      return 'good';
    }

    return 'average';
  }

  statusLabel(status: string | null | undefined): string {
    const normalized = (status || '').trim();
    if (!normalized) {
      return 'Pending';
    }

    return normalized
      .split('_')
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ');
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

  private escapeHtml(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  private async resolvePaperPayload(): Promise<PublishedResultPaperResponse> {
    const payload = this.selectedPaper();
    if (!payload) {
      throw new Error('No result paper selected.');
    }

    return payload;
  }

  private async loadPaperPayload(studentResultId: number): Promise<PublishedResultPaperResponse> {
    this.loadingPaper.set(true);
    this.error.set(null);
    this.message.set(null);

    try {
      return await firstValueFrom(this.resultPublishingService.getResultPaper(studentResultId));
    } finally {
      this.loadingPaper.set(false);
    }
  }

  //============Startin Download the Paper====================//
  private logPaperDownload(payload: PublishedResultPaperResponse, fileName: string, blob?: Blob) {
    const paper = payload.result_paper;
    this.buildChecksum(blob).then((checksum) => {
      this.auditDownloadsService.logDownload({
        module: 'published_results',
        report_key: 'result_paper',
        report_label: 'Result Paper',
        format: 'pdf',
        file_name: fileName,
        file_checksum: checksum,
        row_count: paper.subjects?.length || 0,
        filters: {
          student_result_id: paper.student_result_id,
          class_name: paper.class_name,
          exam_name: paper.exam_name,
          academic_year: paper.academic_year,
        },
        context: {
          student_name: paper.student_name,
          enrollment_number: paper.enrollment_number,
          serial_number: paper.serial_number,
        },
      }).subscribe({ error: () => void 0 });
    });
  }

  private downloadBlob(blob: Blob, filename: string) {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }

  private async buildChecksum(blob?: Blob): Promise<string | null> {
    if (!blob || !window.crypto?.subtle) {
      return null;
    }

    const buffer = await blob.arrayBuffer();
    const digest = await window.crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(digest)).map((value) => value.toString(16).padStart(2, '0')).join('');
  }

  private async generatePaperPdf(payload: PublishedResultPaperResponse) {
    this.generatingPdf.set(true);
    this.error.set(null);

    try {
      return await this.buildPaperPdf(payload);
    } finally {
      this.generatingPdf.set(false);
    }
  }

  private handlePaperPdfError(error: unknown, fallbackMessage: string) {
    const message = error instanceof Error && error.message ? error.message : fallbackMessage;
    this.error.set(message);
    this.generatingPdf.set(false);
    this.loadingPaper.set(false);
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
    const ensurePageSpace = (requiredHeight: number) => {
      if (y + requiredHeight <= pageHeight - 42) {
        return;
      }

      doc.addPage();
      this.drawWatermark(doc, school.watermark_text || school.name || 'School', pageWidth, pageHeight, watermarkLogoImage);
      y = 34;
    };
    let y = 26;
    const overallGrade = this.resolveGrade(paper.grade, paper.percentage, paper.result_status);
    const gradeTone = this.gradeTone(overallGrade, paper.result_status);
    const publishDate = this.formatPaperDate(paper.published_at);
    const toneColor = gradeTone === 'excellent'
      ? palette.gold
      : gradeTone === 'good'
        ? palette.green
        : gradeTone === 'risk'
          ? palette.red
          : palette.blue;

    // Watermark is intentionally very light so it never competes with marks text.
    this.drawWatermark(doc, school.watermark_text || school.name || 'School', pageWidth, pageHeight, watermarkLogoImage);

    const headerTop = y;
    const headerHeight = 132;
    const logoBox = { x: margin + 14, y: headerTop + 24, w: 62, h: 62 };
    const photoBox = { x: pageWidth - margin - 86, y: headerTop + 22, w: 72, h: 84 };
    const titleLeft = logoBox.x + logoBox.w + 14;
    const titleRight = photoBox.x - 14;
    const titleWidth = Math.max(120, titleRight - titleLeft);

    setFill(palette.navy);
    setDraw(palette.navy);
    doc.roundedRect(margin, headerTop, contentWidth, headerHeight, 18, 18, 'FD');
    doc.setFillColor(24, 58, 104);
    doc.roundedRect(margin + 12, headerTop + 12, contentWidth - 24, headerHeight - 24, 14, 14, 'F');

    setFill(palette.white);
    doc.roundedRect(logoBox.x - 6, logoBox.y - 6, logoBox.w + 12, logoBox.h + 12, 12, 12, 'F');

    if (logoImage) {
      const fit = await this.fitImage(logoImage, logoBox.w, logoBox.h);
      doc.addImage(
        logoImage,
        this.detectImageFormat(logoImage),
        logoBox.x + (logoBox.w - fit.width) / 2,
        logoBox.y + (logoBox.h - fit.height) / 2,
        fit.width,
        fit.height
      );
    } else {
      doc.setFillColor(243, 246, 255);
      doc.roundedRect(logoBox.x, logoBox.y, logoBox.w, logoBox.h, 8, 8, 'F');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(10);
      setText(palette.navy);
      doc.text('LOGO', logoBox.x + logoBox.w / 2, logoBox.y + 37, { align: 'center' });
    }

    setText(palette.white);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8.5);
    if (school.registration_number) {
      doc.text(`Reg No: ${school.registration_number}`, margin + 18, headerTop + 16);
    }
    if (school.udise_code) {
      doc.text(`UDISE: ${school.udise_code}`, pageWidth - margin - 18, headerTop + 16, { align: 'right' });
    }

    doc.setFontSize(19);
    doc.text((school.name || 'School').toUpperCase(), pageWidth / 2, headerTop + 34, { align: 'center', maxWidth: titleWidth });
    doc.setFontSize(11);
    doc.setTextColor(245, 230, 194);
    doc.text('PREMIUM PUBLISHED RESULT', pageWidth / 2, headerTop + 52, { align: 'center' });
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9.5);
    doc.setTextColor(230, 237, 247);
    const headerLine = [
      school.address,
      school.phone,
      school.mobile_number_1,
      school.mobile_number_2,
      school.website
    ].filter(Boolean).join('  |  ') || '-';
    doc.text(doc.splitTextToSize(headerLine, titleWidth), pageWidth / 2, headerTop + 71, { align: 'center', maxWidth: titleWidth });

    setFill(palette.white);
    doc.roundedRect(photoBox.x - 4, photoBox.y - 4, photoBox.w + 8, photoBox.h + 8, 14, 14, 'F');
    setFill([247, 249, 252]);
    setDraw(palette.line);
    doc.roundedRect(photoBox.x, photoBox.y, photoBox.w, photoBox.h, 10, 10, 'FD');
    if (studentImage) {
      const photoPadding = 6;
      const fit = await this.fitImage(studentImage, photoBox.w - (photoPadding * 2), photoBox.h - (photoPadding * 2));
      doc.addImage(
        studentImage,
        this.detectImageFormat(studentImage),
        photoBox.x + photoPadding + (photoBox.w - (photoPadding * 2) - fit.width) / 2,
        photoBox.y + photoPadding + (photoBox.h - (photoPadding * 2) - fit.height) / 2,
        fit.width,
        fit.height
      );
    } else {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9);
      setText(palette.navy);
      doc.text('STUDENT', photoBox.x + photoBox.w / 2, photoBox.y + 33, { align: 'center' });
      doc.text('PHOTO', photoBox.x + photoBox.w / 2, photoBox.y + 46, { align: 'center' });
    }

    const ribbonWidth = Math.min(contentWidth - 120, 320);
    const ribbonX = pageWidth / 2 - (ribbonWidth / 2);
    setFill(toneColor);
    doc.roundedRect(ribbonX, headerTop + 92, ribbonWidth, 28, 14, 14, 'F');
    setDraw(palette.white);
    doc.setLineWidth(0.8);
    doc.roundedRect(ribbonX, headerTop + 92, ribbonWidth, 28, 14, 14, 'S');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    setText(palette.white);
    doc.text(`${paper.exam_name || 'Exam'}  |  ${paper.class_name || 'Class'}  |  Grade ${overallGrade}`, pageWidth / 2, headerTop + 110, { align: 'center' });

    y = headerTop + headerHeight + 16;

    const metaRows: Array<[string, string]> = [
      ['Serial No', String(paper.serial_number)],
      ['Parent Name', paper.parents_name || '-'],
      ['Student Name', paper.student_name || '-'],
      ['Address', paper.address || '-'],
      ['Roll Number', String(paper.roll_number ?? paper.enrollment_number ?? '-')],
      ['Enrollment No', String(paper.enrollment_number ?? '-')],
      ['Reg No', paper.registration_number || '-'],
      ['Class', paper.class_name || '-'],
      ['Exam', paper.exam_name || '-'],
      ['Rank', String(paper.rank ?? '-')],
    ];

    setFill(palette.white);
    setDraw(palette.line);
    doc.roundedRect(margin, y, contentWidth, 132, 18, 18, 'FD');
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
        this.drawWatermark(doc, school.watermark_text || school.name || 'School', pageWidth, pageHeight, watermarkLogoImage);
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

  private drawWatermark(doc: any, _schoolName: string, pageWidth: number, pageHeight: number, watermarkLogo?: string | null) {
    if (watermarkLogo) {
      // Repeat watermark softly across the full page without overpowering content.
      const size = Math.min(pageWidth, pageHeight) * 0.18;
      const xPositions = [42, (pageWidth - size) / 2, pageWidth - size - 42];
      const yPositions = [96, 260, 424, 588];
      doc.saveGraphicsState?.();
      const gStateCtor = (doc as any).GState;
      if (typeof gStateCtor === 'function' && typeof doc.setGState === 'function') {
        doc.setGState(new gStateCtor({ opacity: 0.03 }));
      }
      yPositions.forEach((y) => {
        xPositions.forEach((x) => {
          doc.addImage(watermarkLogo, this.detectImageFormat(watermarkLogo), x, y, size, size);
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
    // Try multiple storage/public variants because backend may persist relative paths.
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

  private setResultVisibility(row: PublishedResultRow, visibilityStatus: 'visible' | 'withheld') {
    if (!this.isSuperAdmin() || this.isVisibilityActionLoading(row.id)) {
      return;
    }

    this.error.set(null);
    this.message.set(null);
    this.visibilityActionLoadingIds.set([...this.visibilityActionLoadingIds(), row.id]);

    this.resultPublishingService.setVisibility(row.id, {
      visibility_status: visibilityStatus,
      reason: visibilityStatus === 'visible' ? 'Visible by super admin' : 'Hidden by super admin'
    }).subscribe({
      next: (response) => {
        this.rows.set(this.rows().map((item) => item.id === row.id
          ? { ...item, visibility_status: visibilityStatus }
          : item));
        this.message.set(response.message || (visibilityStatus === 'visible'
          ? 'Result is visible now.'
          : 'Result is hidden now.'));
        this.visibilityActionLoadingIds.set(this.visibilityActionLoadingIds().filter((id) => id !== row.id));
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Unable to update result visibility.');
        this.visibilityActionLoadingIds.set(this.visibilityActionLoadingIds().filter((id) => id !== row.id));
      }
    });
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

  isStudentOrParentPortal(): boolean {
    const role = this.auth.user()?.role;
    return role === 'student' || role === 'parent';
  }
}
