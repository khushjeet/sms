import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';

export interface SchoolDetails {
  name: string;
  address?: string | null;
  phone?: string | null;
  website?: string | null;
  registration_number?: string | null;
  udise_code?: string | null;
  watermark_text?: string | null;
  watermark_logo_url?: string | null;
  watermark_logo_data_url?: string | null;
  logo_url?: string | null;
  logo_data_url?: string | null;
}

export interface SchoolCredentials {
  smtp_enabled: boolean;
  smtp_host?: string | null;
  smtp_port?: number | null;
  smtp_username?: string | null;
  smtp_password?: string | null;
  smtp_encryption?: 'none' | 'tls' | 'ssl' | null;
  smtp_from_address?: string | null;
  smtp_from_name?: string | null;
  smtp_reply_to_address?: string | null;
  smtp_reply_to_name?: string | null;
}

export interface SchoolCredentialTestResult {
  connectivity: boolean;
  delivery: boolean;
  host?: string;
  port?: number;
  recipient?: string;
}

export interface EmailSystemStatus {
  smtp_enabled: boolean;
  smtp_ready: boolean;
  queue_connection: string;
  worker_required: boolean;
  queue_pending_count: number;
  queue_failed_count: number;
  queue_oldest_pending_seconds?: number | null;
  queue_is_backed_up: boolean;
  status: 'healthy' | 'warning' | 'critical';
  message: string;
}

@Injectable({
  providedIn: 'root'
})
export class SchoolDetailsService {
  private readonly api = inject(ApiClient);

  get() {
    return this.api.get<SchoolDetails>('school/details');
  }

  update(payload: SchoolDetails | FormData) {
    if (payload instanceof FormData) {
      if (!payload.has('_method')) {
        payload.append('_method', 'PUT');
      }

      return this.api.post<{ message: string; data: SchoolDetails }>('school/details', payload);
    }

    return this.api.put<{ message: string; data: SchoolDetails }>('school/details', payload);
  }

  getCredentials() {
    return this.api.get<SchoolCredentials>('school/credentials');
  }

  updateCredentials(payload: SchoolCredentials) {
    return this.api.put<{ message: string; data: SchoolCredentials }>('school/credentials', payload);
  }

  getEmailHealth() {
    return this.api.get<EmailSystemStatus>('school/credentials/status');
  }

  testCredentials(test_email: string) {
    return this.api.post<{ message: string; data: SchoolCredentialTestResult }>('school/credentials/test', {
      test_email,
    });
  }
}
