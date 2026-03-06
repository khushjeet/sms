import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  DashboardNotificationsResponse,
  MarkSelfAttendanceResponse,
  SelfAttendanceStatusResponse
} from '../../models/self-attendance';

@Injectable({
  providedIn: 'root'
})
export class SelfAttendanceService {
  private readonly api = inject(ApiClient);

  notifications() {
    return this.api.get<DashboardNotificationsResponse>('dashboard/notifications');
  }

  status() {
    return this.api.get<SelfAttendanceStatusResponse>('dashboard/self-attendance/status');
  }

  markAttendance(payload: FormData) {
    return this.api.post<MarkSelfAttendanceResponse>('dashboard/self-attendance/mark', payload);
  }
}
