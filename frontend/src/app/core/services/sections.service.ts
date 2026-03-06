import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { Section } from '../../models/section';

@Injectable({
  providedIn: 'root'
})
export class SectionsService {
  private readonly api = inject(ApiClient);

  list(params?: { class_id?: number; academic_year_id?: number; status?: string; search?: string; page?: number; per_page?: number }) {
    return this.api.get<PaginatedResponse<Section>>('sections', params);
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Section }>('sections', payload);
  }

  getById(id: number) {
    return this.api.get<Section>(`sections/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: Section }>(`sections/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`sections/${id}`);
  }
}
