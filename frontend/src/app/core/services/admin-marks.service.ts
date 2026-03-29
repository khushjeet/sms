import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { AdminMarksFiltersResponse, AdminMarksSheetResponse } from '../../models/admin-marks';

@Injectable({
  providedIn: 'root'
})
export class AdminMarksService {
  private readonly api = inject(ApiClient);

  filters(params: { class_id: number; academic_year_id: number; section_id?: number }) {
    return this.api.get<AdminMarksFiltersResponse>('admin-marks/filters', params);
  }

  sheet(params: { class_id?: number; academic_year_id?: number; section_id?: number; subject_id?: number; subject_code?: string; marked_on?: string; exam_configuration_id: number }) {
    return this.api.get<AdminMarksSheetResponse>('admin-marks/sheet', params);
  }

  compile(payload: {
    class_id?: number;
    academic_year_id?: number;
    section_id?: number;
    subject_id?: number;
    subject_code?: string;
    marked_on: string;
    exam_configuration_id: number;
    rows: Array<{ enrollment_id: number; marks_obtained?: number | null; max_marks?: number | null; remarks?: string }>;
  }) {
    return this.api.post<{ message: string }>('admin-marks/compile', payload);
  }

  finalize(payload: {
    class_id?: number;
    academic_year_id?: number;
    section_id?: number;
    subject_id?: number;
    subject_code?: string;
    marked_on: string;
    exam_configuration_id: number;
  }) {
    return this.api.post<{ message: string; rows_finalized: number; rows_total: number }>('admin-marks/finalize', payload);
  }
}
