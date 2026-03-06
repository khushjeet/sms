import { DatePipe, DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ClassesService } from '../../core/services/classes.service';
import { AuthService } from '../../core/services/auth.service';
import { ResultPublishingService } from '../../core/services/result-publishing.service';
import { PublishedResultPaperResponse, PublishedResultRow } from '../../models/result-publishing';
import { ClassModel } from '../../models/class';

@Component({
  selector: 'app-published-results',
  standalone: true,
  imports: [NgIf, NgFor, FormsModule, DatePipe, DecimalPipe],
  templateUrl: './published-results.component.html',
  styleUrl: './published-results.component.scss'
})
export class PublishedResultsComponent {
  private readonly auth = inject(AuthService);
  private readonly resultPublishingService = inject(ResultPublishingService);
  private readonly classesService = inject(ClassesService);

  readonly isSuperAdmin = computed(() => this.auth.user()?.role === 'super_admin');
  readonly isTeacher = computed(() => this.auth.user()?.role === 'teacher');
  readonly canPublish = computed(() => this.isSuperAdmin());

  readonly loading = signal(false);
  readonly loadingPaper = signal(false);
  readonly loadingSessions = signal(false);
  readonly publishingClass = signal(false);
  readonly rows = signal<PublishedResultRow[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sessions = signal<Array<{ id: number; name: string; class_id: number; class_name?: string | null; status: string; latest_marked_on?: string | null; finalized_compiled_rows?: number }>>([]);
  readonly selectedClassId = signal<string>('');
  readonly selectedSessionId = signal<string>('');
  readonly markedOn = signal<string>(new Date().toISOString().slice(0, 10));
  readonly publishReason = signal<string>('');
  readonly search = signal('');
  readonly selectedPaper = signal<PublishedResultPaperResponse | null>(null);
  readonly visibilityActionLoadingIds = signal<number[]>([]);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

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
      }
    });
  }

  loadPublished() {
    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.resultPublishingService.listPublished({
      class_id: Number(this.selectedClassId()) || undefined,
      exam_session_id: Number(this.selectedSessionId()) || undefined,
      search: this.search().trim() || undefined,
      per_page: 100,
    }).subscribe({
      next: (response) => {
        this.rows.set(response.data || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load published results.');
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

  printPaper() {
    const payload = this.selectedPaper();
    if (!payload) {
      this.error.set('No result paper selected.');
      return;
    }

    const paper = payload.result_paper;
    const school = payload.school;

    const subjectRows = paper.subjects
      .map((subject, idx) => `
        <tr>
          <td>${idx + 1}</td>
          <td>${this.escapeHtml(subject.subject_name || '')}</td>
          <td>${this.escapeHtml(subject.subject_code || '')}</td>
          <td>${this.escapeHtml(this.formatSubjectMarks(subject))}</td>
          <td>${subject.max_marks}</td>
          <td>${this.escapeHtml(this.formatSubjectGrade(subject))}</td>
        </tr>
      `)
      .join('');

    const popup = window.open('', '_blank', 'width=1100,height=800');
    if (!popup) {
      this.error.set('Popup blocked. Please allow popups to print result paper.');
      return;
    }

    popup.document.write(`
      <html>
      <head>
        <title>Published Result - ${this.escapeHtml(paper.student_name)}</title>
        <style>
          body { font-family: Arial, sans-serif; margin: 20px; color: #0f172a; }
          .header { display:flex; gap:16px; align-items:center; border-bottom:2px solid #0f172a; padding-bottom:12px; margin-bottom:16px; }
          .logo { width:72px; height:72px; object-fit:contain; border:1px solid #cbd5e1; border-radius:8px; }
          .title h1 { margin:0; font-size:22px; text-transform:uppercase; }
          .title p { margin:4px 0 0; font-size:12px; color:#334155; }
          .meta { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px 20px; margin-bottom:16px; font-size:13px; }
          .meta div { padding:6px 8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; }
          table { width:100%; border-collapse:collapse; margin-top:10px; }
          th, td { border:1px solid #cbd5e1; padding:8px; font-size:12px; }
          th { background:#e2e8f0; text-align:left; }
          .legend { margin-top:8px; font-size:12px; color:#334155; }
          .summary { margin-top:16px; display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap:10px; }
          .summary div { border:1px solid #cbd5e1; border-radius:6px; padding:8px; background:#f8fafc; }
          .qr { margin-top:16px; display:flex; justify-content:space-between; align-items:flex-start; gap:20px; }
          .qr .hint { font-size:12px; color:#334155; }
          .qr img { width:120px; height:120px; border:1px solid #cbd5e1; border-radius:8px; }
        </style>
      </head>
      <body>
        <div class="header">
          ${school.logo_url ? `<img class="logo" src="${this.escapeHtml(school.logo_url)}" alt="School Logo" />` : '<div class="logo"></div>'}
          <div class="title">
            <h1>${this.escapeHtml(school.name || 'School')}</h1>
            <p>${this.escapeHtml(school.address || '')}</p>
            <p>Published Result</p>
          </div>
        </div>
        <div class="meta">
          <div><strong>Serial No:</strong> ${paper.serial_number}</div>
          <div><strong>Student Name:</strong> ${this.escapeHtml(paper.student_name)}</div>
          <div><strong>Parent Name:</strong> ${this.escapeHtml(paper.parents_name || '-')}</div>
          <div><strong>Address:</strong> ${this.escapeHtml(paper.address || '-')}</div>
          <div><strong>Enrollment No:</strong> ${this.escapeHtml(String(paper.enrollment_number ?? '-'))}</div>
          <div><strong>Reg No:</strong> ${this.escapeHtml(paper.registration_number || '-')}</div>
          <div><strong>Class:</strong> ${this.escapeHtml(paper.class_name || '-')}</div>
          <div><strong>Exam:</strong> ${this.escapeHtml(paper.exam_name || '-')}</div>
          <div><strong>Academic Year:</strong> ${this.escapeHtml(paper.academic_year || '-')}</div>
          <div><strong>Published At:</strong> ${this.escapeHtml(paper.published_at || '-')}</div>
        </div>
        <table>
          <thead>
            <tr><th>#</th><th>Subject</th><th>Code</th><th>Marks</th><th>Max</th><th>Grade</th></tr>
          </thead>
          <tbody>${subjectRows}</tbody>
        </table>
        <p class="legend"><strong>A</strong> = Absent</p>
        <div class="summary">
          <div><strong>Total:</strong> ${paper.total_marks}/${paper.total_max_marks}</div>
          <div><strong>Percentage:</strong> ${paper.percentage}%</div>
          <div><strong>Result:</strong> ${this.escapeHtml(paper.result_status)} | <strong>Grade:</strong> ${this.escapeHtml(paper.grade || '-')}</div>
        </div>
        <div class="qr">
          <div class="hint">
            <div><strong>Scan to verify authenticity</strong></div>
            <div>${this.escapeHtml(paper.qr_verify_url)}</div>
          </div>
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=${encodeURIComponent(paper.qr_verify_url)}" alt="QR Verification" />
        </div>
      </body>
      </html>
    `);
    popup.document.close();
    popup.focus();
    popup.print();
  }

  private isAbsentSubject(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): boolean {
    return Boolean(subject.is_absent) || ((subject.grade || '').trim().toUpperCase() === 'A' && Number(subject.obtained_marks) === 0);
  }

  private formatSubjectMarks(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): string {
    if (this.isAbsentSubject(subject)) {
      return 'A';
    }

    return String(subject.obtained_marks);
  }

  private formatSubjectGrade(subject: PublishedResultPaperResponse['result_paper']['subjects'][number]): string {
    if (this.isAbsentSubject(subject)) {
      return '-';
    }

    return subject.grade || '';
  }

  private escapeHtml(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
}
