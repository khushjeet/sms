import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { AcademicYear } from '../../models/academic-year';

@Injectable({
  providedIn: 'root'
})
export class AcademicYearsService {
  private readonly api = inject(ApiClient);

  list(params?: { status?: string; is_current?: boolean; page?: number; per_page?: number }) {
    return this.api.get<PaginatedResponse<AcademicYear>>('academic-years', params);
  }

  getCurrent() {
    return this.api.get<AcademicYear>('academic-years/current');
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: AcademicYear }>('academic-years', payload);
  }

  getById(id: number) {
    return this.api.get<AcademicYear>(`academic-years/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: AcademicYear }>(`academic-years/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`academic-years/${id}`);
  }

  setCurrent(id: number) {
    return this.api.post<{ message: string; data: AcademicYear }>(`academic-years/${id}/set-current`, {});
  }

  close(id: number, payload: { remarks?: string }) {
    return this.api.post<{ message: string; data: AcademicYear }>(`academic-years/${id}/close`, payload);
  }
}
