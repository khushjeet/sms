import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';

export interface SendMessagePayload {
  language: 'english' | 'hindi';
  channel: 'email' | 'sms' | 'whatsapp';
  audience: 'students' | 'parents' | 'both';
  subject?: string | null;
  message: string;
  student_ids: number[];
  schedule_at?: string | null;
}

export interface SendMessageResponse {
  message: string;
  data: {
    language: 'english' | 'hindi';
    channel: 'email' | 'sms' | 'whatsapp';
    audience: 'students' | 'parents' | 'both';
    scheduled: boolean;
    scheduled_for: string | null;
    scheduled_message_id: number | null;
    batch_id: string | null;
    students_count: number;
    recipient_count: number;
    queued_count: number;
    delivered_count: number;
    failed_count: number;
  };
}

export interface MessageBatchStatus {
  batch_id: string;
  total_count: number;
  queued_count: number;
  delivered_count: number;
  failed_count: number;
  finished: boolean;
  cancelled: boolean;
}

export interface BirthdaySettings {
  enabled: boolean;
  audience: 'students' | 'parents' | 'both';
  subject: string;
  message: string;
  send_time: string;
}

@Injectable({
  providedIn: 'root'
})
export class MessageCenterService {
  private readonly api = inject(ApiClient);

  send(payload: SendMessagePayload) {
    return this.api.post<SendMessageResponse>('message-center/send', payload);
  }

  status(batchId: string) {
    return this.api.get<MessageBatchStatus>(`message-center/status/${batchId}`);
  }

  getBirthdaySettings() {
    return this.api.get<BirthdaySettings>('message-center/birthday-settings');
  }

  saveBirthdaySettings(payload: BirthdaySettings) {
    return this.api.put<{ message: string; data: BirthdaySettings }>('message-center/birthday-settings', payload);
  }
}
