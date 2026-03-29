import { NgFor, NgIf } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { FormBuilder, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { EnrollmentsService } from '../../core/services/enrollments.service';
import { EventsService } from '../../core/services/events.service';
import { AcademicYear } from '../../models/academic-year';
import { Enrollment } from '../../models/enrollment';
import { SchoolEventDetail, SchoolEventItem } from '../../models/event';

interface ParticipantDraft {
  id?: number;
  student_id: number;
  enrollment_id?: number | null;
  student_name: string;
  admission_number: string;
  class_section: string;
  rank?: number | null;
  achievement_title?: string;
  remarks?: string;
}

@Component({
  selector: 'app-events',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule, FormsModule],
  templateUrl: './events.component.html',
  styleUrl: './events.component.scss'
})
export class EventsComponent {
  private readonly eventsService = inject(EventsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly fb = inject(FormBuilder);
  private readonly sanitizer = inject(DomSanitizer);

  readonly loading = signal(false);
  readonly selectingEventId = signal<number | null>(null);
  readonly savingEvent = signal(false);
  readonly savingParticipants = signal(false);
  readonly searchLoading = signal(false);
  readonly deletingEvent = signal(false);
  readonly certificateLoadingKey = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly events = signal<SchoolEventItem[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly selectedEvent = signal<SchoolEventDetail | null>(null);
  readonly enrollmentResults = signal<Enrollment[]>([]);
  readonly participantDrafts = signal<ParticipantDraft[]>([]);
  readonly participantsDirty = signal(false);
  readonly previewUrl = signal<SafeResourceUrl | null>(null);
  readonly previewFileUrl = signal<string | null>(null);
  readonly previewTitle = signal<string | null>(null);
  readonly previewFileName = signal<string | null>(null);

  readonly canSaveParticipants = computed(() => !!this.selectedEvent());

  readonly eventForm = this.fb.nonNullable.group({
    academic_year_id: [''],
    title: ['', Validators.required],
    event_date: [''],
    venue: [''],
    description: [''],
    status: ['draft'],
    certificate_prefix: ['EVT']
  });

  readonly searchForm = this.fb.nonNullable.group({
    search: ['', Validators.required]
  });

  ngOnInit(): void {
    this.loadAcademicYears();
    this.loadEvents();
  }

  loadAcademicYears(): void {
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => this.academicYears.set(response.data)
    });
  }

  loadEvents(): void {
    this.loading.set(true);
    this.eventsService
      .list({ per_page: 100 })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.events.set(response.data);
          const selectedId = this.selectedEvent()?.id;
          if (selectedId) {
            const stillExists = response.data.some((item) => item.id === selectedId);
            if (!stillExists) {
              this.resetEditor();
            }
          }
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to load events.')
      });
  }

  startNew(): void {
    this.resetEditor();
    this.message.set(null);
    this.error.set(null);
  }

  selectEvent(id: number): void {
    this.message.set(null);
    this.error.set(null);
    this.selectingEventId.set(id);
    this.eventsService.getById(id)
      .pipe(finalize(() => this.selectingEventId.set(null)))
      .subscribe({
        next: (event) => {
          this.selectedEvent.set(event);
          this.eventForm.patchValue({
            academic_year_id: event.academic_year_id ? String(event.academic_year_id) : '',
            title: event.title,
            event_date: event.event_date ? String(event.event_date).slice(0, 10) : '',
            venue: event.venue || '',
            description: event.description || '',
            status: event.status || 'draft',
            certificate_prefix: event.certificate_prefix || 'EVT'
          }, { emitEvent: false });
          this.participantDrafts.set(
            (event.participants || []).map((participant) => ({
              id: participant.id,
              student_id: participant.student_id,
              enrollment_id: participant.enrollment_id ?? null,
              student_name: participant.student?.user?.full_name || this.studentName(participant.student) || 'Student',
              admission_number: participant.student?.admission_number || '-',
              class_section: this.classSection(participant.enrollment),
              rank: participant.rank ?? null,
              achievement_title: participant.achievement_title || '',
              remarks: participant.remarks || ''
            }))
          );
          this.participantsDirty.set(false);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to load event details.')
      });
  }

  saveEvent(): void {
    if (this.eventForm.invalid) {
      this.eventForm.markAllAsTouched();
      return;
    }

    const raw = this.eventForm.getRawValue();
    const payload = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : null,
      title: raw.title.trim(),
      event_date: raw.event_date || null,
      venue: raw.venue.trim() || null,
      description: raw.description.trim() || null,
      status: raw.status || 'draft',
      certificate_prefix: raw.certificate_prefix.trim() || null
    };

    this.savingEvent.set(true);
    this.message.set(null);
    this.error.set(null);

    const request = this.selectedEvent()
      ? this.eventsService.update(this.selectedEvent()!.id, payload)
      : this.eventsService.create(payload);

    request
      .pipe(finalize(() => this.savingEvent.set(false)))
      .subscribe({
        next: (response) => {
          this.message.set(response.message || 'Event saved.');
          this.loadEvents();
          this.selectEvent(response.data.id);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to save event.')
      });
  }

  deleteEvent(): void {
    const current = this.selectedEvent();
    if (!current) {
      return;
    }

    const confirmed = window.confirm(`Delete event "${current.title}"? This will remove participants and certificates for this event.`);
    if (!confirmed) {
      return;
    }

    this.deletingEvent.set(true);
    this.message.set(null);
    this.error.set(null);

    this.eventsService.delete(current.id)
      .pipe(finalize(() => this.deletingEvent.set(false)))
      .subscribe({
        next: (response) => {
          this.message.set(response.message || 'Event deleted.');
          this.resetEditor();
          this.loadEvents();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to delete event.')
      });
  }

  searchEnrollments(): void {
    if (this.searchForm.invalid) {
      this.searchForm.markAllAsTouched();
      return;
    }

    const raw = this.searchForm.getRawValue();
    const academicYearId = this.eventForm.controls.academic_year_id.value
      ? Number(this.eventForm.controls.academic_year_id.value)
      : undefined;

    this.searchLoading.set(true);
    this.enrollmentsService
      .list({
        academic_year_id: academicYearId,
        status: 'active',
        search: raw.search.trim(),
        per_page: 20
      })
      .pipe(finalize(() => this.searchLoading.set(false)))
      .subscribe({
        next: (response) => this.enrollmentResults.set(response.data),
        error: (err) => this.error.set(err?.error?.message || 'Unable to search active enrollments.')
      });
  }

  addEnrollment(enrollment: Enrollment): void {
    const student = enrollment.student;
    if (!student) {
      return;
    }

    const exists = this.participantDrafts().some((item) => item.student_id === student.id);
    if (exists) {
      this.message.set('Student is already added to this event.');
      return;
    }

    this.participantDrafts.update((current) => [
      ...current,
      {
        student_id: student.id,
        enrollment_id: enrollment.id,
        student_name: student.user?.full_name || this.studentName(student) || 'Student',
        admission_number: student.admission_number || '-',
        class_section: this.classSection(enrollment),
        rank: null,
        achievement_title: '',
        remarks: ''
      }
    ]);
    this.participantsDirty.set(true);
    this.message.set(`${student.user?.full_name || this.studentName(student) || 'Student'} added. Click "Save Participants" before generating certificates.`);
    this.error.set(null);
  }

  removeParticipant(index: number): void {
    this.participantDrafts.update((current) => current.filter((_, currentIndex) => currentIndex !== index));
    this.participantsDirty.set(true);
  }

  updateParticipantRank(index: number, value: string): void {
    this.participantDrafts.update((current) =>
      current.map((participant, currentIndex) =>
        currentIndex === index
          ? {
              ...participant,
              rank: value ? Number(value) : null,
            }
          : participant
      )
    );
    this.participantsDirty.set(true);
  }

  participantRankValue(participant: ParticipantDraft): string {
    return participant.rank === null || participant.rank === undefined ? '' : String(participant.rank);
  }

  saveParticipants(): void {
    this.persistParticipants();
  }

  private persistParticipants(onSuccess?: (eventId: number) => void): void {
    const current = this.selectedEvent();
    if (!current) {
      this.error.set('Create or select an event first.');
      return;
    }

    this.savingParticipants.set(true);
    this.message.set(null);
    this.error.set(null);

    const payload = this.participantDrafts().map((participant) => ({
      id: participant.id,
      student_id: participant.student_id,
      enrollment_id: participant.enrollment_id ?? null,
      rank: participant.rank ?? null,
      achievement_title: (participant.achievement_title || '').trim() || null,
      remarks: (participant.remarks || '').trim() || null
    }));

    this.eventsService
      .syncParticipants(current.id, payload)
      .pipe(finalize(() => this.savingParticipants.set(false)))
      .subscribe({
        next: (response) => {
          this.participantsDirty.set(false);
          this.message.set(response.message || 'Participants updated.');
          this.loadEvents();
          this.selectEvent(response.data.id);
          onSuccess?.(response.data.id);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to save participants.')
      });
  }

  downloadCertificate(participantId: number, type: 'participant' | 'winner', studentName: string): void {
    if (this.participantsDirty()) {
      this.message.set('Saving participant changes before generating certificate...');
      this.error.set(null);
      this.persistParticipants(() => this.downloadCertificate(participantId, type, studentName));
      return;
    }

    const loadingKey = this.certificateKey(participantId, type);
    this.certificateLoadingKey.set(loadingKey);
    this.message.set(type === 'winner' ? 'Generating winner certificate...' : 'Generating participant certificate...');
    this.error.set(null);

    this.eventsService.downloadCertificate(participantId, type)
      .pipe(finalize(() => this.certificateLoadingKey.set(null)))
      .subscribe({
        next: (blob) => {
          const fileName = `${studentName.replace(/\s+/g, '-').toLowerCase()}-${type}-certificate.pdf`;
          this.triggerBlobDownload(blob, fileName);
          this.message.set(type === 'winner' ? 'Winner certificate downloaded.' : 'Participant certificate downloaded.');
        },
        error: async (err) => this.error.set(await this.readErrorMessage(err, 'Unable to download certificate.'))
      });
  }

  previewCertificate(participantId: number, type: 'participant' | 'winner', studentName: string): void {
    if (this.participantsDirty()) {
      this.message.set('Saving participant changes before loading preview...');
      this.error.set(null);
      this.persistParticipants(() => this.previewCertificate(participantId, type, studentName));
      return;
    }

    const loadingKey = this.certificateKey(participantId, type);
    this.certificateLoadingKey.set(loadingKey);
    this.message.set(type === 'winner' ? 'Loading winner certificate preview...' : 'Loading participant certificate preview...');
    this.error.set(null);

    this.eventsService.downloadCertificate(participantId, type)
      .pipe(finalize(() => this.certificateLoadingKey.set(null)))
      .subscribe({
        next: (blob) => {
          const fileName = `${studentName.replace(/\s+/g, '-').toLowerCase()}-${type}-certificate.pdf`;
          this.setPreviewBlob(blob, fileName, `${studentName} - ${type === 'winner' ? 'Winner Certificate' : 'Participant Certificate'}`);
          this.message.set('Certificate preview is ready. Review it on the left, then download.');
        },
        error: async (err) => this.error.set(await this.readErrorMessage(err, 'Unable to download certificate.'))
      });
  }

  isCertificateLoading(participantId: number, type: 'participant' | 'winner'): boolean {
    return this.certificateLoadingKey() === this.certificateKey(participantId, type);
  }

  canDownloadParticipantCertificate(participant: ParticipantDraft): boolean {
    return !!participant.id && !this.savingParticipants() && !this.certificateLoadingKey();
  }

  canDownloadWinnerCertificate(participant: ParticipantDraft): boolean {
    return !!participant.id && !!participant.rank && !this.savingParticipants() && !this.certificateLoadingKey();
  }

  downloadPreview(): void {
    const fileUrl = this.previewFileUrl();
    const fileName = this.previewFileName();
    if (!fileUrl || !fileName) {
      return;
    }

    const link = document.createElement('a');
    link.href = fileUrl;
    link.download = fileName;
    link.click();
  }

  private resetEditor(): void {
    this.clearPreview();
    this.selectedEvent.set(null);
    this.eventForm.reset({
      academic_year_id: '',
      title: '',
      event_date: '',
      venue: '',
      description: '',
      status: 'draft',
      certificate_prefix: 'EVT'
    });
    this.participantDrafts.set([]);
    this.participantsDirty.set(false);
    this.enrollmentResults.set([]);
    this.searchForm.reset({ search: '' });
  }

  onParticipantFieldChange(index: number, field: 'achievement_title' | 'remarks', value: string): void {
    this.participantDrafts.update((current) =>
      current.map((participant, currentIndex) =>
        currentIndex === index
          ? {
              ...participant,
              [field]: value,
            }
          : participant
      )
    );
    this.participantsDirty.set(true);
  }

  private async readErrorMessage(err: unknown, fallback: string): Promise<string> {
    if (!(err instanceof HttpErrorResponse)) {
      return fallback;
    }

    if (typeof err.error === 'string' && err.error.trim()) {
      return err.error;
    }

    if (err.error instanceof Blob) {
      try {
        const text = await err.error.text();
        if (!text.trim()) {
          return fallback;
        }

        try {
          const parsed = JSON.parse(text) as { message?: string };
          return parsed.message || fallback;
        } catch {
          return text;
        }
      } catch {
        return fallback;
      }
    }

    return err.error?.message || fallback;
  }

  private certificateKey(participantId: number, type: 'participant' | 'winner'): string {
    return `${participantId}:${type}`;
  }

  private setPreviewBlob(blob: Blob, fileName: string, title: string): void {
    this.clearPreview();
    const objectUrl = URL.createObjectURL(blob);
    this.previewFileUrl.set(objectUrl);
    this.previewUrl.set(this.sanitizer.bypassSecurityTrustResourceUrl(objectUrl));
    this.previewFileName.set(fileName);
    this.previewTitle.set(title);
  }

  private triggerBlobDownload(blob: Blob, fileName: string): void {
    const objectUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = objectUrl;
    link.download = fileName;
    link.click();
    setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
  }

  private clearPreview(): void {
    const existingUrl = this.previewFileUrl();
    if (existingUrl) {
      URL.revokeObjectURL(existingUrl);
    }
    this.previewFileUrl.set(null);
    this.previewUrl.set(null);
    this.previewFileName.set(null);
    this.previewTitle.set(null);
  }

  private studentName(student: { user?: { first_name?: string; last_name?: string } } | undefined): string {
    return `${student?.user?.first_name || ''} ${student?.user?.last_name || ''}`.trim();
  }

  private classSection(enrollment?: Enrollment | null): string {
    const className = enrollment?.section?.class?.name || enrollment?.classModel?.name || 'N/A';
    const sectionName = enrollment?.section?.name || 'N/A';
    return `${className} / ${sectionName}`;
  }
}
