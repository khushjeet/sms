import { DecimalPipe, NgFor, NgIf } from '@angular/common';
import { Component, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../core/services/auth.service';
import { SelfAttendanceService } from '../../core/services/self-attendance.service';
import { StudentDashboardService } from '../../core/services/student-dashboard.service';
import { StudentDashboardResponse, StudentDashboardYearOption } from '../../models/student-dashboard';
import {
  DashboardNotificationItem,
  SelfAttendanceStatusResponse
} from '../../models/self-attendance';

@Component({
  selector: 'app-dashboard',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule, DecimalPipe, RouterLink],
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss'
})
export class DashboardComponent {
  private readonly auth = inject(AuthService);
  private readonly studentDashboardService = inject(StudentDashboardService);
  private readonly selfAttendanceService = inject(SelfAttendanceService);
  private readonly fb = inject(FormBuilder);
  private mediaStream: MediaStream | null = null;
  private capturedBlob: Blob | null = null;

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly data = signal<StudentDashboardResponse | null>(null);
  readonly notifications = signal<DashboardNotificationItem[]>([]);
  readonly selfAttendance = signal<SelfAttendanceStatusResponse | null>(null);
  readonly cameraError = signal<string | null>(null);
  readonly cameraReady = signal(false);
  readonly capturePreview = signal<string | null>(null);
  readonly actionBusy = signal(false);
  readonly viewerImage = signal<string | null>(null);

  readonly filters = this.fb.nonNullable.group({
    academic_year_id: [''],
    month: [new Date().toISOString().slice(0, 7)]
  });

  readonly isStudent = computed(() => this.auth.user()?.role === 'student');
  readonly widgetMap = computed(() => this.data()?.widgets ?? {});
  readonly yearOptions = computed<StudentDashboardYearOption[]>(() => this.data()?.academic_year_options ?? []);

  @ViewChild('cameraVideo') cameraVideo?: ElementRef<HTMLVideoElement>;
  @ViewChild('cameraCanvas') cameraCanvas?: ElementRef<HTMLCanvasElement>;

  ngOnInit() {
    if (this.isStudent()) {
      this.loadStudentDashboard();
      return;
    }

    this.loadNotifications();
    this.loadSelfAttendanceStatus();
  }

  ngOnDestroy() {
    this.stopCamera();
    this.revokePreview();
  }

  reload() {
    if (!this.isStudent()) {
      return;
    }

    this.loadStudentDashboard();
  }

  widgetEnabled(key: string): boolean {
    const widget = this.widgetMap()?.[key];
    return !!widget?.enabled;
  }

  loadNotifications() {
    this.selfAttendanceService.notifications().subscribe({
      next: (response) => this.notifications.set(response.items ?? []),
      error: (err) => this.error.set(err?.error?.message || 'Unable to load notifications.')
    });
  }

  loadSelfAttendanceStatus() {
    this.selfAttendanceService.status().subscribe({
      next: (response) => this.selfAttendance.set(response),
      error: (err) => this.error.set(err?.error?.message || 'Unable to load self-attendance status.')
    });
  }

  async startCamera() {
    this.cameraError.set(null);

    if (!navigator.mediaDevices?.getUserMedia) {
      this.cameraError.set('Camera is not supported in this browser.');
      return;
    }

    try {
      this.stopCamera();
      this.mediaStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user' },
        audio: false
      });

      const video = this.cameraVideo?.nativeElement;
      if (!video) {
        return;
      }
      video.srcObject = this.mediaStream;
      await video.play();
      this.cameraReady.set(true);
    } catch {
      this.cameraError.set('Unable to open camera. Please allow camera access.');
      this.cameraReady.set(false);
    }
  }

  captureSelfie() {
    this.cameraError.set(null);
    const video = this.cameraVideo?.nativeElement;
    const canvas = this.cameraCanvas?.nativeElement;
    if (!video || !canvas || !this.mediaStream) {
      this.cameraError.set('Start camera before capturing selfie.');
      return;
    }

    const width = video.videoWidth || 640;
    const height = video.videoHeight || 480;
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      this.cameraError.set('Unable to capture image frame.');
      return;
    }
    ctx.drawImage(video, 0, 0, width, height);
    canvas.toBlob((blob) => {
      if (!blob) {
        this.cameraError.set('Unable to capture selfie image.');
        return;
      }
      this.capturedBlob = blob;
      this.revokePreview();
      this.capturePreview.set(URL.createObjectURL(blob));
    }, 'image/jpeg', 0.9);
  }

  clearCapturedSelfie() {
    this.capturedBlob = null;
    this.revokePreview();
  }

  async markSelfAttendance(punchType: 'in' | 'out') {
    if (this.actionBusy()) {
      return;
    }
    if (!this.capturedBlob) {
      this.cameraError.set('Capture selfie before marking attendance.');
      return;
    }

    this.actionBusy.set(true);
    this.error.set(null);
    this.cameraError.set(null);

    try {
      const location = await this.resolveLocation();
      const file = new File([this.capturedBlob], `selfie-${Date.now()}.jpg`, { type: 'image/jpeg' });
      const payload = new FormData();
      payload.append('punch_type', punchType);
      payload.append('selfie', file);
      payload.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
      if (location?.latitude !== undefined) {
        payload.append('latitude', String(location.latitude));
      }
      if (location?.longitude !== undefined) {
        payload.append('longitude', String(location.longitude));
      }
      if (location?.accuracy !== undefined) {
        payload.append('location_accuracy_meters', String(Math.round(location.accuracy)));
      }

      this.selfAttendanceService.markAttendance(payload).subscribe({
        next: (response) => {
          this.error.set(null);
          this.cameraError.set(response.message);
          this.clearCapturedSelfie();
          this.loadSelfAttendanceStatus();
          this.loadNotifications();
          this.actionBusy.set(false);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save self attendance.');
          this.actionBusy.set(false);
        },
      });
    } catch {
      this.actionBusy.set(false);
      this.error.set('Location access failed. Please allow location and try again.');
    }
  }

  openViewer(imageUrl: string | null) {
    if (!imageUrl) {
      return;
    }
    this.viewerImage.set(imageUrl);
  }

  closeViewer() {
    this.viewerImage.set(null);
  }

  private async resolveLocation(): Promise<{ latitude: number; longitude: number; accuracy: number } | null> {
    if (!navigator.geolocation) {
      return null;
    }

    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy
          });
        },
        () => reject(new Error('Location permission denied')),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
      );
    });
  }

  private loadStudentDashboard() {
    const raw = this.filters.getRawValue();
    const academicYearId = raw.academic_year_id ? Number(raw.academic_year_id) : undefined;

    this.loading.set(true);
    this.error.set(null);

    this.studentDashboardService
      .getDashboard({
        academic_year_id: academicYearId,
        month: raw.month || undefined
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.data.set(response);

          const scopedYearId = response.scope?.academic_year_id;
          if (scopedYearId && !raw.academic_year_id) {
            this.filters.patchValue({ academic_year_id: String(scopedYearId) }, { emitEvent: false });
          }
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load student dashboard.');
        }
      });
  }

  private stopCamera() {
    this.mediaStream?.getTracks().forEach((track) => track.stop());
    this.mediaStream = null;
    const video = this.cameraVideo?.nativeElement;
    if (video) {
      video.srcObject = null;
    }
    this.cameraReady.set(false);
  }

  private revokePreview() {
    const currentPreview = this.capturePreview();
    if (currentPreview) {
      URL.revokeObjectURL(currentPreview);
    }
    this.capturePreview.set(null);
  }
}
