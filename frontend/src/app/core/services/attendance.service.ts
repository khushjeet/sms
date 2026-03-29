import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  AttendanceListItem,
  AttendanceLiveSearchItem,
  AttendanceMarkItem,
  AttendanceReportStudent,
  AttendanceSummary,
  BulkMonthlyAttendanceResponse
} from '../../models/attendance';

@Injectable({
  providedIn: 'root'
})
export class AttendanceService {
  private readonly api = inject(ApiClient);

  getSectionAttendance(params: { class_id: number; section_id?: number; date: string }) {
    return this.api.get<AttendanceListItem[]>('attendance/section', params);
  }

  markAttendance(payload: { class_id: number; section_id?: number; date: string; attendances: AttendanceMarkItem[] }) {
    return this.api.post<{ message: string }>('attendance/mark', payload);
  }

  lockAttendance(payload: { class_id: number; section_id?: number; date: string }) {
    return this.api.post<{ message: string }>('attendance/lock', payload);
  }

  getStudentAttendance(studentId: number, params: { academic_year_id: number; start_date?: string; end_date?: string }) {
    return this.api.get<AttendanceSummary>(`attendance/student/${studentId}`, params);
  }

  getSectionStatistics(params: { class_id: number; section_id?: number; start_date: string; end_date: string }) {
    return this.api.get<unknown[]>('attendance/section/statistics', params);
  }

  searchStudentsForReports(params: { student_id: string }) {
    return this.api.get<AttendanceReportStudent[]>('attendance/reports/search', params);
  }

  downloadMonthlyReport(params: { student_ids: string; month: number; academic_year_id: number }) {
    return this.api.getBlob('attendance/reports/monthly/download', params);
  }

  downloadSessionWiseReport(params: { student_ids: string; academic_year_id?: number }) {
    return this.api.getBlob('attendance/reports/session/download', params);
  }

  liveSearch(params: {
    q: string;
    academic_year_id?: number;
    class_ids?: string;
    month?: number;
  }) {
    return this.api.get<AttendanceLiveSearchItem[]>('attendance/reports/live-search', params);
  }

  getBulkMonthlyData(params: {
    class_ids: string;
    academic_year_id: number;
    month: number;
  }) {
    return this.api.get<BulkMonthlyAttendanceResponse>('attendance/reports/bulk/monthly', params);
  }

  downloadBulkMonthlyExcel(params: {
    class_ids: string;
    academic_year_id: number;
    month: number;
  }) {
    return this.api.getBlob('attendance/reports/bulk/monthly/download', params);
  }
}
