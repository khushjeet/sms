import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { SchoolEventDetail, SchoolEventItem } from '../../models/event';

@Injectable({
  providedIn: 'root'
})
export class EventsService {
  private readonly api = inject(ApiClient);

  list(params?: {
    academic_year_id?: number;
    search?: string;
    page?: number;
    per_page?: number;
  }) {
    return this.api.get<PaginatedResponse<SchoolEventItem>>('events', params);
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: SchoolEventItem }>('events', payload);
  }

  getById(id: number) {
    return this.api.get<SchoolEventDetail>(`events/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: SchoolEventItem }>(`events/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`events/${id}`);
  }

  syncParticipants(id: number, participants: Array<Record<string, unknown>>) {
    return this.api.put<{ message: string; data: SchoolEventDetail }>(`events/${id}/participants`, { participants });
  }

  downloadCertificate(participantId: number, type: 'participant' | 'winner') {
    return this.api.getBlob(`events/participants/${participantId}/certificate`, { type });
  }
}
