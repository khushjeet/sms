import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { Enrollment, EnrollmentHistoryResponse } from '../../models/enrollment';

@Injectable({
  providedIn: 'root'
})
export class EnrollmentsService {
  private readonly api = inject(ApiClient);

  list(params?: {
    academic_year_id?: number;
    class_id?: number;
    section_id?: number;
    status?: string;
    student_id?: number;
    search?: string;
    page?: number;
    per_page?: number;
  }) {
    return this.api.get<PaginatedResponse<Enrollment>>('enrollments', params);
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Enrollment }>('enrollments', payload);
  }

  getById(id: number) {
    return this.api.get<Enrollment>(`enrollments/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: Enrollment }>(`enrollments/${id}`, payload);
  }

  promote(id: number, payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Enrollment }>(`enrollments/${id}/promote`, payload);
  }

  repeat(id: number, payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Enrollment }>(`enrollments/${id}/repeat`, payload);
  }

  transfer(id: number, payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Enrollment }>(`enrollments/${id}/transfer`, payload);
  }

  academicHistory(id: number) {
    return this.api.get<EnrollmentHistoryResponse>(`enrollments/${id}/academic-history`);
  }
}
