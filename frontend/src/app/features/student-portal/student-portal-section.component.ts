import { DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AdmitCardService } from '../../core/services/admit-card.service';
import { StudentDashboardService } from '../../core/services/student-dashboard.service';
import { AdmitPaperResponse, MyAdmitCardResponse } from '../../models/admit-card';
import { StudentDashboardResponse } from '../../models/student-dashboard';

type StudentSectionKey = 'admit-card' | 'fee' | 'result' | 'timetable' | 'academic-history' | 'attendance-history';

@Component({
  selector: 'app-student-portal-section',
  standalone: true,
  imports: [NgIf, NgFor, DecimalPipe],
  templateUrl: './student-portal-section.component.html',
  styleUrl: './student-portal-section.component.scss'
})
export class StudentPortalSectionComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly studentDashboardService = inject(StudentDashboardService);
  private readonly admitCardService = inject(AdmitCardService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly vm = signal<StudentDashboardResponse | null>(null);
  readonly admitApi = signal<MyAdmitCardResponse | null>(null);
  readonly admitLoading = signal(false);
  readonly admitActionLoading = signal(false);
  readonly section = computed(() => (this.route.snapshot.data['section'] as StudentSectionKey) || 'admit-card');
  readonly title = computed(() => (this.route.snapshot.data['title'] as string) || 'Student Section');

  readonly sectionWidgetMap: Record<StudentSectionKey, string> = {
    'admit-card': 'admit_card',
    fee: 'fee',
    result: 'results',
    timetable: 'timetable',
    'academic-history': 'academic_history',
    'attendance-history': 'attendance_history',
  };

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
        next: (response) => this.vm.set(response),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load student section.'),
      });
  }

  private loadAdmit() {
    this.admitLoading.set(true);

    this.admitCardService
      .myLatest()
      .pipe(finalize(() => this.admitLoading.set(false)))
      .subscribe({
        next: (response: MyAdmitCardResponse) => this.admitApi.set(response),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load admit card status.'),
      });
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
    const admitId = this.admitApi()?.admit_card?.id ?? this.vm()?.admit_card?.admit_card_id;
    if (!admitId) {
      this.error.set('No published admit card found.');
      return;
    }

    this.admitActionLoading.set(true);
    this.admitCardService
      .getPaper(admitId)
      .pipe(finalize(() => this.admitActionLoading.set(false)))
      .subscribe({
        next: (payload: AdmitPaperResponse) => this.openPrintWindow(payload),
        error: (err: any) => this.error.set(err?.error?.message || 'Unable to load admit card.'),
      });
  }

  private openPrintWindow(payload: AdmitPaperResponse) {
    const card = payload.admit_card;
    const schoolName = (payload.school?.name || 'INDIAN PUBLIC SCHOOL').toUpperCase();
    const schoolAddress = payload.school?.address || 'Naugawa Chowk, Yogapatti-845452';
    const schoolPhone = payload.school?.phone || '+919771782335 +919931482335';
    const schoolWebsite = payload.school?.website || 'https://ipsyogapatti.com';
    const phoneLine = this.escapeHtml(schoolPhone);
    const schoolLogo = payload.school?.logo_url || 'storage/assets/ips.png';
    const popup = window.open('', '_blank', 'noopener,noreferrer,width=900,height=700');
    if (!popup) {
      this.error.set('Popup blocked. Please allow popups to print admit card.');
      return;
    }

    const scheduleRows = (card.schedule || []).map((row, index) => `
      <tr>
        <td>${index + 1}</td>
        <td>${this.escapeHtml(row.subject_name || '-')}</td>
        <td>${this.escapeHtml(row.subject_code || '-')}</td>
        <td>${this.escapeHtml(row.exam_date || '-')}</td>
        <td>${this.escapeHtml(row.exam_shift || '-')}</td>
        <td>${this.escapeHtml(row.start_time || '-')} - ${this.escapeHtml(row.end_time || '-')}</td>
        <td>${this.escapeHtml(row.room_number || '-')}</td>
      </tr>
    `).join('');

    popup.document.write(`
      <html>
      <head>
        <title>Admit Card - ${this.escapeHtml(card.student_name)}</title>
        <style>
          @page { size: A4 portrait; margin: 8mm; }
          * { box-sizing: border-box; font-weight: 700 !important; }
          body { font-family: Arial, sans-serif; margin: 0; color: #111827; position: relative; font-weight: 700; }
          .watermark {
            position: fixed;
            top: 36%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.1;
            z-index: 0;
            text-align: center;
            width: 100%;
          }
          .watermark img { width: 300px; height: 300px; object-fit: contain; }
          .watermark-text { font-size: 72px; font-weight: 700; color: #8b97a7; transform: rotate(-28deg); display: inline-block; }
          .content { position: relative; z-index: 2; }
          .ips-header { border: 1px solid #b7bcc4; background: #f3f4f6; padding: 10px 12px; margin-bottom: 12px; }
          .ips-header-main { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
          .ips-right { width: 110px; text-align: center; }
          .ips-logo { width: 96px; height: 96px; border: 1px solid #d1d5db; background: #fff; object-fit: contain; padding: 2px; }
          .ips-center { flex: 1; text-align: center; }
          .ips-name { margin: 0; font-size: 24px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #123f4a; line-height: 1.15; }
          .ips-address { margin-top: 4px; font-size: 13px; font-weight: 700; color: #111827; }
          .ips-contact { margin-top: 2px; font-size: 12px; color: #111827; font-weight: 700; line-height: 1.25; }
          .phone-line { white-space: nowrap; }
          .ips-title-row { margin-top: 8px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #c8ccd1; padding-top: 6px; }
          .title { font-size: 21px; margin: 0; }
          .badge { border: 1px solid #cbd5f5; padding: 4px 10px; border-radius: 999px; font-size: 11px; text-transform: capitalize; background: #eef2ff; }
          .meta { margin: 10px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 6px 16px; }
          .meta div { font-size: 13px; color: #111827; font-weight: 700; }
          .photo-cell { display: flex; align-items: center; justify-content: flex-end; justify-self: end; width: 100%; gap: 8px; text-align: right; }
          .student-photo { width: 60px; height: 72px; border: 1px solid #9ca3af; object-fit: cover; background: #ffffff; }
          table { width: 100%; border-collapse: collapse; margin-top: 12px; }
          th, td { border: 1px solid #9ca3af; padding: 6px; font-size: 11px; text-align: left; color: #111827; font-weight: 700; }
          th { background: #d9dde2; font-weight: 700; }
          td { background: #f8fafc; }
          .signatures { margin-top: 24px; width: 100%; display: table; table-layout: fixed; }
          .signature-box { display: table-cell; width: 50%; text-align: center; padding: 0 20px; }
          .signature-line { border-top: 1px solid #111827; margin-bottom: 6px; }
          .signature-label { font-size: 12px; color: #111827; font-weight: 700; }
          .content-body { font-size: 12px; }
        </style>
      </head>
      <body>
        <div class="watermark">
          ${schoolLogo ? `<img src="${this.escapeHtml(schoolLogo)}" alt="Watermark Logo" />` : `<span class="watermark-text">${this.escapeHtml(schoolName)}</span>`}
        </div>
        <div class="content">
        <div class="ips-header">
          <div class="ips-header-main">
            <div class="ips-center">
              <h2 class="ips-name">${this.escapeHtml(schoolName)}</h2>
              <div class="ips-address">${this.escapeHtml(schoolAddress)}</div>
              <div class="ips-contact phone-line">Mob. ${phoneLine || '-'}</div>
              <div class="ips-contact">${this.escapeHtml(schoolWebsite)}</div>
            </div>
            <div class="ips-right">
              <img class="ips-logo" src="${this.escapeHtml(schoolLogo)}" alt="School Logo" />
            </div>
          </div>
          <div class="ips-title-row">
            <div>
              <h1 class="title">Admit Card</h1>
              <p style="margin:0;">${this.escapeHtml(card.exam_name || '-')} | ${this.escapeHtml(card.academic_year || '-')}</p>
            </div>
            <div class="badge">${this.escapeHtml(card.status || 'published')}</div>
          </div>
        </div>
        <div class="content-body">
          <div class="meta">
            <div><strong>Student:</strong> ${this.escapeHtml(card.student_name)}</div>
            <div class="photo-cell">
              <strong>Profile Image:</strong>
              ${card.photo_url
                ? `<img class="student-photo" src="${this.escapeHtml(card.photo_url)}" alt="Student Photo" />`
                : '<span>-</span>'}
            </div>
            <div><strong>Father Name:</strong> ${this.escapeHtml(card.father_name || '-')}</div>
            <div><strong>Mother Name:</strong> ${this.escapeHtml(card.mother_name || '-')}</div>
            <div><strong>DOB:</strong> ${this.escapeHtml(card.dob || '-')}</div>
            <div><strong>Class:</strong> ${this.escapeHtml(card.class_name || '-')}</div>
            <div><strong>Roll Number:</strong> ${this.escapeHtml(card.roll_number || '-')}</div>
            <div><strong>Seat Number:</strong> ${this.escapeHtml(card.seat_number || '-')}</div>
            <div><strong>Center:</strong> ${this.escapeHtml(card.center_name || '-')}</div>
            <div><strong>Version:</strong> ${card.version}</div>
          </div>
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Subject</th>
                <th>Code</th>
                <th>Date</th>
                <th>Exam Session</th>
                <th>Time</th>
                <th>Room</th>
              </tr>
            </thead>
            <tbody>${scheduleRows || '<tr><td colspan="7">No schedule available.</td></tr>'}</tbody>
          </table>
          <div class="signatures">
            <div class="signature-box">
              <div class="signature-line"></div>
              <div class="signature-label">Class Teacher Signature</div>
            </div>
            <div class="signature-box">
              <div class="signature-line"></div>
              <div class="signature-label">Principal Signature</div>
            </div>
          </div>
        </div>
        </div>
      </body>
      </html>
    `);
    popup.document.close();
    popup.focus();
    popup.print();
  }

  private escapeHtml(value: string): string {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}
