import { Component, inject, signal } from '@angular/core';
import { NgIf } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-password-reset',
  standalone: true,
  imports: [NgIf, ReactiveFormsModule, RouterLink],
  templateUrl: './password-reset.component.html',
  styleUrl: './password-reset.component.scss'
})
export class PasswordResetComponent {
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly success = signal<string | null>(null);

  readonly form = this.fb.nonNullable.group({
    email: [this.route.snapshot.queryParamMap.get('email') ?? '', [Validators.required, Validators.email]],
    password: ['', [Validators.required, Validators.minLength(8)]],
    password_confirmation: ['', [Validators.required, Validators.minLength(8)]]
  });

  readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  submit() {
    if (!this.token) {
      this.error.set('Reset token is missing or invalid.');
      return;
    }

    if (this.form.invalid || this.loading()) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.success.set(null);

    this.auth.resetPassword({
      token: this.token,
      ...this.form.getRawValue()
    }).subscribe({
      next: (response) => {
        this.loading.set(false);
        this.success.set(response.status || 'Password reset successful.');
        setTimeout(() => this.router.navigate(['/login']), 1200);
      },
      error: (err) => {
        this.loading.set(false);
        const message = err?.error?.message || err?.error?.email?.[0] || err?.error?.password?.[0] || 'Unable to reset password.';
        this.error.set(message);
      }
    });
  }
}
