export interface TeacherAssignment {
  id: number;
  subject_id: number;
  section_id: number;
  class_id: number;
  academic_year_id: number;
  subject_name: string;
  subject_code?: string | null;
  section_name: string;
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
