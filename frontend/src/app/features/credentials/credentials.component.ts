import { NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { forkJoin } from 'rxjs';
import { finalize } from 'rxjs/operators';
import {
  EmailSystemStatus,
  SchoolCredentials,
  SchoolCredentialTestResult,
  SchoolDetailsService
} from '../../core/services/school-details.service';

@Component({
  selector: 'app-credentials',
  standalone: true,
  imports: [NgIf, ReactiveFormsModule],
  templateUrl: './credentials.component.html',
  styleUrl: './credentials.component.scss'
})
export class CredentialsComponent {
  private readonly schoolDetailsService = inject(SchoolDetailsService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly testing = signal(false);
  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly credentials = signal<SchoolCredentials | null>(null);
  readonly testResult = signal<SchoolCredentialTestResult | null>(null);
  readonly emailHealth = signal<EmailSystemStatus | null>(null);

  readonly form = this.fb.nonNullable.group({
    smtp_enabled: [false],
    smtp_host: [''],
    smtp_port: [587, [Validators.min(1), Validators.max(65535)]],
    smtp_username: [''],
    smtp_password: [''],
    smtp_encryption: ['tls' as 'none' | 'tls' | 'ssl'],
    smtp_from_address: ['', [Validators.email]],
    smtp_from_name: [''],
    smtp_reply_to_address: ['', [Validators.email]],
    smtp_reply_to_name: [''],
    test_email: ['', [Validators.email]],
  });

  ngOnInit() {
    this.load();
  }

  load() {
    this.loading.set(true);
    this.error.set(null);

    forkJoin({
      credentials: this.schoolDetailsService.getCredentials(),
      health: this.schoolDetailsService.getEmailHealth(),
    })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: ({ credentials, health }) => {
          this.credentials.set(credentials);
          this.emailHealth.set(health);
          this.patchForm(credentials);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load credentials.');
        }
      });
  }

  save() {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      this.error.set('Please correct the highlighted email settings.');
      return;
    }

    const raw = this.form.getRawValue();
    const payload: SchoolCredentials = {
      smtp_enabled: !!raw.smtp_enabled,
      smtp_host: raw.smtp_host.trim() || null,
      smtp_port: raw.smtp_port ? Number(raw.smtp_port) : null,
      smtp_username: raw.smtp_username.trim() || null,
      smtp_password: raw.smtp_password || null,
      smtp_encryption: raw.smtp_encryption || 'none',
      smtp_from_address: raw.smtp_from_address.trim() || null,
      smtp_from_name: raw.smtp_from_name.trim() || null,
      smtp_reply_to_address: raw.smtp_reply_to_address.trim() || null,
      smtp_reply_to_name: raw.smtp_reply_to_name.trim() || null,
    };

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.schoolDetailsService
      .updateCredentials(payload)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.credentials.set(response.data);
          this.patchForm(response.data);
          if (!this.form.controls.test_email.value && response.data.smtp_from_address) {
            this.form.patchValue({ test_email: response.data.smtp_from_address }, { emitEvent: false });
          }
          this.message.set(response.message || 'Credentials updated successfully.');
          this.refreshEmailHealth();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save credentials.');
        }
      });
  }

  sendTestEmail() {
    const testEmail = this.form.controls.test_email.getRawValue().trim();

    if (!testEmail || this.form.controls.test_email.invalid) {
      this.form.controls.test_email.markAsTouched();
      this.error.set('Enter a valid test email address.');
      return;
    }

    this.testing.set(true);
    this.error.set(null);
    this.message.set(null);
    this.testResult.set(null);

    this.schoolDetailsService
      .testCredentials(testEmail)
      .pipe(finalize(() => this.testing.set(false)))
      .subscribe({
        next: (response) => {
          this.testResult.set(response.data);
          this.message.set(response.message || 'Test email sent successfully.');
          this.refreshEmailHealth();
        },
        error: (err) => {
          this.testResult.set(err?.error?.data || null);
          this.error.set(err?.error?.message || 'Unable to complete SMTP test.');
          this.refreshEmailHealth();
        }
      });
  }

  private patchForm(credentials: SchoolCredentials) {
    this.form.patchValue({
      smtp_enabled: credentials.smtp_enabled ?? false,
      smtp_host: credentials.smtp_host || '',
      smtp_port: credentials.smtp_port || 587,
      smtp_username: credentials.smtp_username || '',
      smtp_password: credentials.smtp_password || '',
      smtp_encryption: credentials.smtp_encryption || 'none',
      smtp_from_address: credentials.smtp_from_address || '',
      smtp_from_name: credentials.smtp_from_name || '',
      smtp_reply_to_address: credentials.smtp_reply_to_address || '',
      smtp_reply_to_name: credentials.smtp_reply_to_name || '',
      test_email: this.form.controls.test_email.value || credentials.smtp_from_address || '',
    }, { emitEvent: false });
  }

  private refreshEmailHealth() {
    this.schoolDetailsService.getEmailHealth().subscribe({
      next: (health) => this.emailHealth.set(health),
      error: () => this.emailHealth.set(null),
    });
  }
}
