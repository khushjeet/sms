import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { NgIf } from '@angular/common';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss'
})
export class LoginComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly resetMode = signal(false);
  readonly resetLoading = signal(false);
  readonly resetError = signal<string | null>(null);
  readonly resetSuccess = signal<string | null>(null);

  readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]]
  });

  readonly resetForm = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]]
  });

  submit() {
    if (this.form.invalid || this.loading()) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    this.auth.login(this.form.getRawValue()).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/']);
      },
      error: (err) => {
        this.loading.set(false);
        const message = err?.error?.message || err?.error?.email?.[0] || 'Login failed.';
        this.error.set(message);
      }
    });
  }

  toggleResetMode() {
    this.resetMode.update((value) => !value);
    this.resetError.set(null);
    this.resetSuccess.set(null);
    this.resetForm.patchValue({ email: this.form.controls.email.value });
  }

  sendResetLink() {
    if (this.resetForm.invalid || this.resetLoading()) {
      this.resetForm.markAllAsTouched();
      return;
    }

    this.resetLoading.set(true);
    this.resetError.set(null);
    this.resetSuccess.set(null);

    this.auth.requestPasswordReset(this.resetForm.controls.email.getRawValue()).subscribe({
      next: (response) => {
        this.resetLoading.set(false);
        this.resetSuccess.set(response.status || 'Password reset link sent.');
      },
      error: (err) => {
        this.resetLoading.set(false);
        const message = err?.error?.message || err?.error?.email?.[0] || 'Unable to send reset link.';
        this.resetError.set(message);
      }
    });
  }
}
