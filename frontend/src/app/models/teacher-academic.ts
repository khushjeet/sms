export interface TeacherAssignment {
  id: number;
  subject_id: number;
  section_id: number | null;
  class_id: number;
  academic_year_id: number;
  subject_name: string;
  subject_code?: string | null;
  section_name: string | null;
  class_name: string;
  academic_year_name: string;
  mapped_max_marks?: number | null;
  mapped_pass_marks?: number | null;
  mapped_exam_configuration_id?: number | null;
  mapped_exam_configuration_name?: string | null;
}

export interface TeacherAttendanceRow {
  enrollment_id: number;
  student_id: number;
  roll_number?: number | null;
  student_name?: string | null;
  status: 'present' | 'absent' | 'leave' | 'half_day' | 'not_marked';
  remarks?: string | null;
  is_locked?: boolean;
}

export interface TeacherMarksRow {
  enrollment_id: number;
  student_id: number;
  roll_number?: number | null;
  student_name?: string | null;
  marks_obtained?: number | null;
  max_marks?: number | null;
  remarks?: string | null;
}

export interface TeacherTimetableRow {
  id: number;
  academic_year_id: number;
  academic_year_name?: string | null;
  section_id: number;
  day_of_week: string;
  day_label?: string;
  time_slot_id: number;
  subject_id?: number | null;
  teacher_id?: number | null;
  room_number?: string | null;
  time_slot_name?: string | null;
  time_slot_order?: number | null;
  start_time?: string | null;
  end_time?: string | null;
  time_range?: string | null;
  is_break?: boolean;
  subject_name?: string | null;
  subject_code?: string | null;
  teacher_name?: string | null;
  class_name?: string | null;
  section_name?: string | null;
}

export interface TeacherTimetableResponse {
  meta: {
    teacher_id: number;
    teacher_name: string;
    academic_year_id?: number | null;
    academic_year_name?: string | null;
  };
  academic_year_options: Array<{ id: number; name: string; is_current: boolean }>;
  days: Array<{ value: string; label: string }>;
  slots: Array<{
    id: number;
    name: string;
    start_time: string;
    end_time: string;
    time_range?: string;
    is_break: boolean;
    slot_order: number;
  }>;
  rows: TeacherTimetableRow[];
  matrix: Array<{
    slot: {
      id: number;
      name: string;
      start_time: string;
      end_time: string;
      time_range?: string;
      is_break: boolean;
      slot_order: number;
    };
    days: Record<string, TeacherTimetableRow | null | undefined>;
  }>;
}
