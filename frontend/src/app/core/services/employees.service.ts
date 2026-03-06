import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import {
  Employee,
  EmployeeMetadata,
  StaffAttendanceHistoryRow,
  StaffPayoutHistoryRow
} from '../../models/employee';

@Injectable({
  providedIn: 'root'
})
export class EmployeesService {
  private readonly api = inject(ApiClient);

  metadata() {
    return this.api.get<EmployeeMetadata>('employees/metadata');
  }

  list(params?: {
    status?: string;
    employee_type?: string;
    role?: string;
    search?: string;
    per_page?: number;
    page?: number;
  }) {
    return this.api.get<PaginatedResponse<Employee>>('employees', params);
  }

  create(payload: Record<string, unknown> | FormData) {
    return this.api.post<{ message: string; data: Employee }>('employees', payload);
  }

  getById(id: number) {
    return this.api.get<Employee>(`employees/${id}`);
  }

  update(id: number, payload: Record<string, unknown> | FormData) {
    if (payload instanceof FormData) {
      payload.set('_method', 'PUT');
      return this.api.post<{ message: string; data: Employee }>(`employees/${id}`, payload);
    }
    return this.api.put<{ message: string; data: Employee }>(`employees/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`employees/${id}`);
  }

  downloadDocument(id: number, documentId: number) {
    return this.api.getBlob(`employees/${id}/documents/${documentId}/file`);
  }

  attendanceHistory(id: number, params?: {
    start_date?: string;
    end_date?: string;
    status?: 'present' | 'absent' | 'half_day' | 'leave';
    page?: number;
    per_page?: number;
  }) {
    return this.api.get<PaginatedResponse<StaffAttendanceHistoryRow>>(`employees/${id}/attendance-history`, params);
  }

  downloadAttendanceHistoryExcel(id: number, params?: {
    start_date?: string;
    end_date?: string;
    status?: 'present' | 'absent' | 'half_day' | 'leave';
  }) {
    return this.api.getBlob(`employees/${id}/attendance-history/download`, params);
  }

  payoutHistory(id: number, params?: {
    year?: number;
    month?: number;
    status?: 'generated' | 'finalized' | 'paid';
    page?: number;
    per_page?: number;
  }) {
    return this.api.get<PaginatedResponse<StaffPayoutHistoryRow>>(`employees/${id}/payout-history`, params);
  }

  downloadPayoutHistoryExcel(id: number, params?: {
    year?: number;
    month?: number;
    status?: 'generated' | 'finalized' | 'paid';
  }) {
    return this.api.getBlob(`employees/${id}/payout-history/download`, params);
  }
}
