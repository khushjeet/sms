import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { Student, StudentFinancialSummary } from '../../models/student';

@Injectable({
  providedIn: 'root'
})
export class StudentsService {
  private readonly api = inject(ApiClient);

  list(params?: { status?: string; class_id?: number; search?: string; per_page?: number; page?: number }) {
    return this.api.get<PaginatedResponse<Student>>('students', params);
  }

  create(payload: Record<string, unknown> | FormData) {
    return this.api.post<{ message: string; data: Student }>('students', payload);
  }

  getById(id: number) {
    return this.api.get<Student>(`students/${id}`);
  }

  update(id: number, payload: Record<string, unknown> | FormData) {
    if (payload instanceof FormData) {
      payload.set('_method', 'PUT');
      return this.api.post<{ message: string; data: Student }>(`students/${id}`, payload);
    }
    return this.api.put<{ message: string; data: Student }>(`students/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`students/${id}`);
  }

  academicHistory(id: number) {
    return this.api.get<unknown[]>(`students/${id}/academic-history`);
  }

  financialSummary(id: number) {
    return this.api.get<StudentFinancialSummary>(`students/${id}/financial-summary`);
  }
}
