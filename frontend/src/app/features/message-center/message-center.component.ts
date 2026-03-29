import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { ClassesService } from '../../core/services/classes.service';
import { BirthdaySettings, MessageBatchStatus, MessageCenterService } from '../../core/services/message-center.service';
import { EmailSystemStatus, SchoolCredentials, SchoolDetailsService } from '../../core/services/school-details.service';
import { SectionsService } from '../../core/services/sections.service';
import { StudentsService } from '../../core/services/students.service';
import { ClassModel } from '../../models/class';
import { Section } from '../../models/section';
import { Student } from '../../models/student';

@Component({
  selector: 'app-message-center',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './message-center.component.html',
  styleUrl: './message-center.component.scss'
})
export class MessageCenterComponent {
  private readonly fb = inject(FormBuilder);
  private readonly classesService = inject(ClassesService);
  private readonly sectionsService = inject(SectionsService);
  private readonly schoolDetailsService = inject(SchoolDetailsService);
  private readonly studentsService = inject(StudentsService);
  private readonly messageCenterService = inject(MessageCenterService);

  readonly loading = signal(true);
  readonly credentials = signal<SchoolCredentials | null>(null);
  readonly emailHealth = signal<EmailSystemStatus | null>(null);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly recipientStudents = signal<Student[]>([]);
  readonly recipientLoading = signal(false);
  readonly recipientPagination = signal({ current_page: 1, last_page: 1, total: 0 });
  readonly selectedRecipientIds = signal<number[]>([]);
  readonly sendingMessage = signal(false);
  readonly savingBirthday = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);
  readonly birthdayMessage = signal<string | null>(null);
  readonly batchStatus = signal<MessageBatchStatus | null>(null);
  private batchPollHandle: number | null = null;

  readonly messageForm = this.fb.nonNullable.group({
    language: ['english' as 'english' | 'hindi', Validators.required],
    channel: ['email' as 'email' | 'sms' | 'whatsapp', Validators.required],
    audience: ['parents' as 'students' | 'parents' | 'both', Validators.required],
    subject: [''],
    message: ['', [Validators.required, Validators.maxLength(5000)]],
    schedule_enabled: [false],
    schedule_at: [''],
  });

  readonly birthdayForm = this.fb.nonNullable.group({
    enabled: [false],
    audience: ['parents' as 'students' | 'parents' | 'both', Validators.required],
    subject: ['Happy Birthday from School'],
    message: ['Wishing you a very happy birthday and a wonderful year ahead.', [Validators.required, Validators.maxLength(5000)]],
    send_time: ['08:00', Validators.required],
  });

  readonly recipientFilterForm = this.fb.nonNullable.group({
    search: [''],
    all_classes: [true],
    class_id: [''],
    section_id: [''],
    per_page: [25],
  });

  readonly filteredSections = computed(() => {
    const allClasses = this.recipientFilterForm.controls.all_classes.value;
    const classId = Number(this.recipientFilterForm.controls.class_id.value || 0);

    return this.sections().filter((section) => allClasses || !classId || Number(section.class_id) === classId);
  });

  readonly emailChannelEnabled = computed(() => {
    const credentials = this.credentials();
    return !!credentials?.smtp_enabled
      && !!credentials.smtp_host
      && !!credentials.smtp_port
      && !!credentials.smtp_from_address;
  });

  readonly selectedRecipientsCount = computed(() => this.selectedRecipientIds().length);

  readonly allVisibleRecipientsSelected = computed(() => {
    const students = this.recipientStudents();
    const selected = new Set(this.selectedRecipientIds());

    return students.length > 0 && students.every((student) => selected.has(student.id));
  });

  ngOnInit() {
    this.loadBootstrap();
    this.loadRecipients();
  }

  ngOnDestroy() {
    this.stopBatchPolling();
  }

  loadBootstrap() {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      classes: this.classesService.list({ per_page: 250, status: 'active' }),
      sections: this.sectionsService.list({ per_page: 400, status: 'active' }),
      credentials: this.schoolDetailsService.getCredentials(),
      health: this.schoolDetailsService.getEmailHealth(),
      birthday: this.messageCenterService.getBirthdaySettings(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ classes, sections, credentials, health, birthday }) => {
          this.classes.set(classes.data || []);
          this.sections.set(sections.data || []);
          this.credentials.set(credentials);
          this.emailHealth.set(health);
          this.birthdayForm.patchValue(birthday, { emitEvent: false });
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load send message settings.');
        }
      });
  }

  loadRecipients(page = 1) {
    const raw = this.recipientFilterForm.getRawValue();
    this.recipientLoading.set(true);
    this.error.set(null);

    this.studentsService.list({
      search: raw.search.trim() || undefined,
      class_id: raw.all_classes || !raw.class_id ? undefined : Number(raw.class_id),
      section_id: raw.all_classes || !raw.section_id ? undefined : Number(raw.section_id),
      per_page: Number(raw.per_page) || 25,
      page,
      status: 'active',
    }).subscribe({
      next: (response) => {
        this.recipientStudents.set(response.data || []);
        this.recipientPagination.set({
          current_page: response.current_page,
          last_page: response.last_page,
          total: response.total,
        });
        this.recipientLoading.set(false);
      },
      error: (err) => {
        this.recipientLoading.set(false);
        this.error.set(err?.error?.message || 'Unable to load students for messaging.');
      }
    });
  }

  onRecipientClassModeChange(checked: boolean) {
    this.recipientFilterForm.patchValue({
      all_classes: checked,
      class_id: checked ? '' : this.recipientFilterForm.controls.class_id.value,
      section_id: '',
    }, { emitEvent: false });
    this.loadRecipients();
  }

  onRecipientClassChange(value: string) {
    this.recipientFilterForm.patchValue({ class_id: value, section_id: '' }, { emitEvent: false });
    this.loadRecipients();
  }

  applyRecipientFilters() {
    this.loadRecipients(1);
  }

  previousRecipientPage() {
    const page = this.recipientPagination().current_page;
    if (page > 1) {
      this.loadRecipients(page - 1);
    }
  }

  nextRecipientPage() {
    const page = this.recipientPagination().current_page;
    const last = this.recipientPagination().last_page;
    if (page < last) {
      this.loadRecipients(page + 1);
    }
  }

  toggleRecipient(studentId: number, checked: boolean) {
    const next = new Set(this.selectedRecipientIds());
    if (checked) {
      next.add(studentId);
    } else {
      next.delete(studentId);
    }
    this.selectedRecipientIds.set(Array.from(next));
  }

  toggleVisibleRecipients(checked: boolean) {
    const next = new Set(this.selectedRecipientIds());
    for (const student of this.recipientStudents()) {
      if (checked) {
        next.add(student.id);
      } else {
        next.delete(student.id);
      }
    }
    this.selectedRecipientIds.set(Array.from(next));
  }

  isRecipientSelected(studentId: number) {
    return this.selectedRecipientIds().includes(studentId);
  }

  sendMessage() {
    this.error.set(null);
    this.success.set(null);
    this.birthdayMessage.set(null);
    this.batchStatus.set(null);
    this.stopBatchPolling();

    if (this.messageForm.invalid) {
      this.messageForm.markAllAsTouched();
      this.error.set('Write the message first before sending.');
      return;
    }

    const raw = this.messageForm.getRawValue();
    if (raw.channel !== 'email') {
      this.error.set(raw.channel === 'sms'
        ? 'SMS credentials are not configured yet.'
        : 'WhatsApp credentials are not configured yet.');
      return;
    }

    if (!this.emailChannelEnabled()) {
      this.error.set('Email credentials are not configured. Please enable SMTP first.');
      return;
    }

    if (!this.selectedRecipientIds().length) {
      this.error.set('Select at least one student from the list.');
      return;
    }

    if (raw.schedule_enabled && !raw.schedule_at) {
      this.error.set('Select date and time for the scheduled special email.');
      return;
    }

    this.sendingMessage.set(true);

    this.messageCenterService.send({
      language: raw.language,
      channel: raw.channel,
      audience: raw.audience,
      subject: raw.subject.trim() || null,
      message: raw.message.trim(),
      student_ids: this.selectedRecipientIds(),
      schedule_at: raw.schedule_enabled ? raw.schedule_at : null,
    })
      .pipe(finalize(() => this.sendingMessage.set(false)))
      .subscribe({
        next: (response) => {
          const stats = response.data;
          if (stats.scheduled) {
            this.success.set(`Special email scheduled for ${stats.scheduled_for || 'the selected date and time'}.`);
            return;
          }

          this.batchStatus.set({
            batch_id: stats.batch_id || '',
            total_count: stats.recipient_count,
            queued_count: stats.queued_count,
            delivered_count: stats.delivered_count,
            failed_count: stats.failed_count,
            finished: stats.queued_count === 0,
            cancelled: false,
          });
          this.success.set(
            `${response.message} In queue: ${stats.queued_count}. Delivered: ${stats.delivered_count}.`
          );
          if (stats.batch_id) {
            this.startBatchPolling(stats.batch_id);
          }
          this.refreshEmailHealth();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to send message.');
        }
      });
  }

  saveBirthdaySettings() {
    this.birthdayMessage.set(null);
    this.error.set(null);

    if (this.birthdayForm.invalid) {
      this.birthdayForm.markAllAsTouched();
      this.error.set('Complete the birthday wish subject, message, and send time.');
      return;
    }

    this.savingBirthday.set(true);

    const raw = this.birthdayForm.getRawValue();
    const payload: BirthdaySettings = {
      enabled: raw.enabled,
      audience: raw.audience,
      subject: raw.subject.trim(),
      message: raw.message.trim(),
      send_time: raw.send_time,
    };

    this.messageCenterService.saveBirthdaySettings(payload)
      .pipe(finalize(() => this.savingBirthday.set(false)))
      .subscribe({
        next: (response) => {
          this.birthdayForm.patchValue(response.data, { emitEvent: false });
          this.birthdayMessage.set(response.message || 'Birthday wish settings saved.');
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save birthday wish settings.');
        }
      });
  }

  trackByStudent(_: number, student: Student) {
    return student.id;
  }

  getStudentName(student: Student): string {
    const user = student.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }

  getStudentClass(student: Student): string {
    return (student as any)?.currentEnrollment?.section?.class?.name
      || (student as any)?.latestEnrollment?.section?.class?.name
      || (student as any)?.profile?.class?.name
      || '-';
  }

  getStudentSection(student: Student): string {
    return (student as any)?.currentEnrollment?.section?.name
      || (student as any)?.latestEnrollment?.section?.name
      || '-';
  }

  getEnrollmentId(student: Student): string {
    return String((student as any)?.currentEnrollment?.id || (student as any)?.latestEnrollment?.id || '-');
  }

  getRecipientEmailSummary(student: Student): string {
    const emails = [
      student.user?.email,
      student.profile?.father_email,
      student.profile?.mother_email,
    ].filter((value) => !!value);

    return emails.length ? emails.join(', ') : 'No email found';
  }

  private startBatchPolling(batchId: string) {
    this.stopBatchPolling();

    const poll = () => {
      this.messageCenterService.status(batchId).subscribe({
        next: (status) => {
          this.batchStatus.set(status);
          this.success.set(`In queue: ${status.queued_count}. Delivered: ${status.delivered_count}. Failed: ${status.failed_count}.`);

          if (status.finished || status.cancelled || status.queued_count === 0) {
            this.stopBatchPolling();
          }
        },
        error: () => {
          this.stopBatchPolling();
        }
      });
    };

    this.batchPollHandle = window.setInterval(poll, 3000);
    poll();
  }

  private stopBatchPolling() {
    if (this.batchPollHandle !== null) {
      window.clearInterval(this.batchPollHandle);
      this.batchPollHandle = null;
    }
  }

  private refreshEmailHealth() {
    this.schoolDetailsService.getEmailHealth().subscribe({
      next: (health) => this.emailHealth.set(health),
      error: () => this.emailHealth.set(null),
    });
  }
}
