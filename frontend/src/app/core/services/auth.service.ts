import { inject, Injectable, signal } from '@angular/core';
import { tap } from 'rxjs/operators';
import { ApiClient } from './api-client.service';
import { AuthSession, AuthUser, LoginResponse } from '../../models/auth';
import { environment } from '../../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly storageKey = 'sms_auth_session';

  private readonly userSignal = signal<AuthUser | null>(null);
  private readonly tokenSignal = signal<string | null>(null);
  private readonly expiresAtSignal = signal<string | null>(null);

  constructor() {
    this.loadFromStorage();
  }

  user() {
    return this.userSignal();
  }

  token() {
    return this.tokenSignal();
  }

  expiresAt() {
    return this.expiresAtSignal();
  }

  isAuthenticated() {
    return !!this.userSignal() && !!this.tokenSignal();
  }

  login(credentials: { email: string; password: string }) {
    return this.api.post<LoginResponse>('login', credentials).pipe(
      tap((response) => this.setSession(response))
    );
  }

  requestPasswordReset(email: string) {
    return this.api.post<{ status: string }>('forgot-password', { email });
  }

  resetPassword(payload: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) {
    return this.api.post<{ status: string }>('reset-password', payload);
  }

  logout() {
    return this.api.post<{ message: string }>('logout', {}).pipe(
      tap(() => this.clearSession())
    );
  }

  revokeAllTokens() {
    return this.api.post<{ message: string }>('revoke-all-tokens', {}).pipe(
      tap(() => this.clearSession())
    );
  }

  refreshUser() {
    return this.api.get<{ user: AuthUser }>('user').pipe(
      tap((response) => this.userSignal.set(response.user))
    );
  }

  clearSession() {
    this.userSignal.set(null);
    this.tokenSignal.set(null);
    this.expiresAtSignal.set(null);
    localStorage.removeItem(this.storageKey);
  }

  private setSession(response: LoginResponse) {
    this.userSignal.set(response.user);
    this.tokenSignal.set(response.token);
    this.expiresAtSignal.set(response.expires_at);

    const session: AuthSession = {
      token: response.token,
      expires_at: response.expires_at,
      user: response.user
    };
    localStorage.setItem(this.storageKey, JSON.stringify(session));
  }

  private loadFromStorage() {
    const raw = localStorage.getItem(this.storageKey);
    if (!raw) {
      return;
    }
    try {
      const session = JSON.parse(raw) as AuthSession;
      if (session.expires_at && new Date(session.expires_at) <= new Date()) {
        this.clearSession();
        return;
      }
      this.userSignal.set(session.user ?? null);
      this.tokenSignal.set(session.token ?? null);
      this.expiresAtSignal.set(session.expires_at ?? null);
    } catch {
      this.clearSession();
    }
  }
}
