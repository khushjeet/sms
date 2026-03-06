import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { Teacher } from '../../models/teacher';

@Injectable({
  providedIn: 'root'
})
export class TeachersService {
  private readonly api = inject(ApiClient);

  list(params?: { status?: string; search?: string; per_page?: number; page?: number }) {
    return this.api.get<PaginatedResponse<Teacher>>('teachers', params);
  }

  create(payload: Record<string, unknown> | FormData) {
    return this.api.post<{ message: string; data: Teacher }>('teachers', payload);
  }

  getById(id: number) {
    return this.api.get<Teacher>(`teachers/${id}`);
  }

  update(id: number, payload: Record<string, unknown> | FormData) {
    if (payload instanceof FormData) {
      payload.set('_method', 'PUT');
      return this.api.post<{ message: string; data: Teacher }>(`teachers/${id}`, payload);
    }
    return this.api.put<{ message: string; data: Teacher }>(`teachers/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`teachers/${id}`);
  }

  uploadDocuments(id: number, payload: FormData) {
    return this.api.post<{ message: string; data: Teacher }>(`teachers/${id}/documents`, payload);
  }

  downloadDocument(id: number, documentId: number) {
    return this.api.getBlob(`teachers/${id}/documents/${documentId}/file`);
  }
}
