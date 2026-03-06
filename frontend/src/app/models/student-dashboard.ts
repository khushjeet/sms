export interface StudentDashboardYearOption {
  id: number;
  name: string;
  is_current: boolean;
  status: string;
}

export interface StudentDashboardResponse {
  dashboard: 'student';
  generated_at: string;
  scope: {
    student_id: number;
    academic_year_id: number | null;
    month: string;
  };
  academic_year_options: StudentDashboardYearOption[];
  profile_summary: {
    full_name: string | null;
    admission_number: string | null;
    roll_number: number | string | null;
    class: string | null;
    section: string | null;
    academic_year: string | null;
    profile_photo: string | null;
    house: string | null;
    blood_group: string | null;
  };
  quick_stats: {
    attendance_percent: number;
    pending_fee: number;
    upcoming_exam: string | null;
    assignments_due: number;
  };
  academic_overview: {
    current_academic_year: string | null;
    current_term: string | null;
    upcoming_exam: {
      name: string | null;
      status: string | null;
      published_at: string | null;
      term: string | null;
      exam_session_id?: number | null;
    };
    academic_status: string | null;
  };
  attendance_overview: {
    month: string;
    monthly_percentage: number;
    total_present: number;
    total_absent: number;
    total_leave: number;
    total_half_day: number;
    last_7_days: Array<{
      date: string | null;
      status: string | null;
    }>;
    source: string;
  };
  result_section: {
    state: 'available' | 'blocked' | 'not_published';
    message: string | null;
    latest_result: {
      student_result_id: number;
      exam_name: string | null;
      class_name: string | null;
      academic_year: string | null;
      percentage: number;
      grade: string | null;
      result_status: string | null;
      published_at: string | null;
    } | null;
  };
  fee_summary: {
    total_fee: number;
    paid_amount: number;
    pending_amount: number;
    last_payment_date: string | null;
    last_receipt_number: string | null;
    receipt_download_url: string | null;
    receipt_download_available: boolean;
    source?: string;
  };
  admit_card: {
    status: 'not_generated' | 'generated_not_published' | 'published' | 'blocked';
    exam_name: string | null;
    download_url: string | null;
    message: string | null;
    version?: number | null;
    admit_card_id?: number | null;
    published_at?: string | null;
  };
  notice_board: {
    source: string;
    items: Array<{ title: string; published_at: string; scope: string; expires_at?: string | null; body?: string | null }>;
  };
  assignments: {
    source: string;
    items: Array<{ subject: string; title: string; submission_date: string; status: 'Pending' | 'Submitted' | 'Late' }>;
  };
  timetable: {
    source: string;
    items: Array<{ day: string; period: string; time: string; subject: string; teacher: string }>;
  };
  academic_history: {
    source: string;
    items: Array<{
      enrollment_id: number;
      academic_year_id: number;
      academic_year: string | null;
      class: string | null;
      section: string | null;
      roll_number: number | string | null;
      status: string;
      enrollment_date: string | null;
      is_locked: boolean;
    }>;
  };
  attendance_history: {
    source: string;
    items: Array<{
      month: string;
      roll_number: number | string | null;
      present: number;
      absent: number;
      leave: number;
      half_day: number;
      total: number;
      attendance_percentage: number;
    }>;
  };
  widgets: Record<string, { enabled: boolean; permission: string }>;
}
