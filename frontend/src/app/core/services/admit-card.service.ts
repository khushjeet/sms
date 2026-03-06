import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { AdmitPaperResponse, AdmitSessionCard, MyAdmitCardResponse } from '../../models/admit-card';
import { PaginatedResponse } from '../../models/pagination';

@Injectable({ providedIn: 'root' })
export class AdmitCardService {
  private readonly api = inject(ApiClient);

  myLatest(params?: { academic_year_id?: number }) {
    return this.api.get<MyAdmitCardResponse>('admits/me', params);
  }

  getPaper(admitCardId: number) {
    return this.api.get<AdmitPaperResponse>(`admits/${admitCardId}/paper`);
  }

  listSessions(params?: {
    class_id?: number;
    academic_year_id?: number;
    exam_configuration_id?: number;
    status?: string;
    per_page?: number;
    page?: number;
  }) {
    return this.api.get<PaginatedResponse<{
      id: number;
      name: string;
      status: string;
      class_id: number;
      academic_year_id: number;
      exam_configuration_id?: number | null;
      active_admit_count: number;
      published_admit_count: number;
      class_model?: { id: number; name: string };
      academic_year?: { id: number; name: string };
      exam_configuration?: { id: number; name: string };
    }>>('admits/sessions', params);
  }

  listSessionCards(sessionId: number, params?: { per_page?: number; page?: number }) {
    return this.api.get<PaginatedResponse<AdmitSessionCard>>(`admits/sessions/${sessionId}/cards`, params);
  }

  bulkPaper(sessionId: number) {
    return this.api.getBlob(`admits/sessions/${sessionId}/paper`);
  }

  downloadPaper(admitCardId: number) {
    return this.api.getBlob(`admits/${admitCardId}/paper/download`);
  }

  generate(payload: {
    exam_session_id: number;
    reason?: string;
    center_name?: string;
    seat_prefix?: string;
    schedule?: {
      subjects: Array<{
        subject_id: number;
        subject_name?: string | null;
        subject_code?: string | null;
        exam_date?: string | null;
        exam_shift?: '1st Shift' | '2nd Shift' | null;
        start_time?: string | null;
        end_time?: string | null;
        room_number?: string | null;
        max_marks?: number | null;
      }>;
    };
  }) {
    return this.api.post<{ message: string; exam_session_id: number; summary: { total_students: number } }>('admits/generate', payload);
  }

  publishSession(sessionId: number, payload?: { reason?: string }) {
    return this.api.post<{ message: string; published_count: number }>(`admits/sessions/${sessionId}/publish`, payload || {});
  }

  setVisibility(admitCardId: number, payload: { visibility_status: 'visible' | 'withheld' | 'under_review' | 'disciplinary_hold'; reason?: string }) {
    return this.api.post<{ message: string }>(`admits/${admitCardId}/visibility`, payload);
  }
}
