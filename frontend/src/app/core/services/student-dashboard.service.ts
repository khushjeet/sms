import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { StudentDashboardResponse } from '../../models/student-dashboard';

@Injectable({
  providedIn: 'root'
})
export class StudentDashboardService {
  private readonly api = inject(ApiClient);

  getDashboard(params?: { academic_year_id?: number; month?: string }) {
    return this.api.get<StudentDashboardResponse>('dashboard/student', params);
  }
}

