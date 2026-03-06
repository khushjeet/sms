export interface AttendanceMarkItem {
  enrollment_id: number;
  status: 'present' | 'absent' | 'leave' | 'half_day';
  remarks?: string;
}

export interface AttendanceListItem {
  enrollment_id: number;
  roll_number?: number | null;
  student_name: string;
  status: string;
  remarks?: string | null;
  marked_by?: string | null;
  marked_at?: string | null;
  is_locked?: boolean;
}

export interface AttendanceSummary {
  total_days: number;
  present: number;
  absent: number;
  leave: number;
  half_day: number;
  percentage: number;
  details: Array<{ date: string; status: string; remarks?: string | null }>;
}

export interface AttendanceReportStudent {
  student_id: number;
  admission_number?: string | null;
  student_name: string;
  class?: string | null;
  section?: string | null;
  session?: string | null;
  sessions_count: number;
}

export interface AttendanceLiveSearchItem {
  enrollment_id: number;
  student_id: number;
  admission_number?: string | null;
  student_name?: string | null;
  class?: string | null;
  section?: string | null;
  session?: string | null;
}

export interface BulkMonthlyAttendanceRow {
  enrollment_id: number;
  student_id: number;
  admission_number?: string | null;
  student_name: string;
  class: string;
  section: string;
  session: string;
  month: string;
  year: number;
  counts: {
    present: number;
    absent: number;
    leave: number;
    half_day: number;
    not_marked: number;
  };
  daily_codes: string[];
}

export interface BulkMonthlyAttendanceResponse {
  meta: {
    month: number;
    month_name: string;
    year: number;
    academic_year_id: number;
    days: number[];
    total_students: number;
  };
  rows: BulkMonthlyAttendanceRow[];
}
