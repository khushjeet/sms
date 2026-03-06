export interface DashboardNotificationItem {
  id: number;
  title: string;
  message: string;
  type: string;
  target_audience: string;
  sent_at: string | null;
  is_read: boolean;
}

export interface DashboardNotificationsResponse {
  items: DashboardNotificationItem[];
}

export interface SelfAttendanceEvent {
  id: number;
  punch_type: 'in' | 'out' | 'auto_out' | string;
  punched_at: string | null;
  latitude: number | null;
  longitude: number | null;
  location_accuracy_meters: number | null;
  selfie_url: string | null;
  source?: string;
}

export interface SelfAttendanceSession {
  id: number;
  attendance_date: string;
  punch_in_at: string | null;
  punch_out_at: string | null;
  duration_minutes: number | null;
  review_status: 'pending' | 'approved' | 'rejected' | string;
  punch_in_selfie_url: string | null;
  punch_out_selfie_url: string | null;
}

export interface SelfAttendanceStatusResponse {
  can_mark: boolean;
  message: string | null;
  session: SelfAttendanceSession | null;
  can_punch_in: boolean;
  can_punch_out: boolean;
  recent_events: SelfAttendanceEvent[];
}

export interface MarkSelfAttendanceResponse {
  message: string;
  event: SelfAttendanceEvent;
}
