import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { ClassModel } from '../../models/class';

@Injectable({
  providedIn: 'root'
})
export class ClassesService {
  private readonly api = inject(ApiClient);

  list(params?: { status?: string; page?: number; per_page?: number }) {
    return this.api.get<PaginatedResponse<ClassModel>>('classes', params);
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: ClassModel }>('classes', payload);
  }

  getById(id: number) {
    return this.api.get<ClassModel>(`classes/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: ClassModel }>(`classes/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`classes/${id}`);
  }
}
