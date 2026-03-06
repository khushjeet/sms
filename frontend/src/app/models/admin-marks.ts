export interface AdminMarksTeacherColumn {
  id: number;
  name: string;
}

export interface AdminTeacherMarkValue {
  marks_obtained?: number | null;
  max_marks?: number | null;
  remarks?: string | null;
}

export interface AdminMarksRow {
  enrollment_id: number;
  student_id: number;
  roll_number?: number | null;
  student_name?: string | null;
  teacher_marks: Record<string, AdminTeacherMarkValue>;
  compiled_marks_obtained?: number | null;
  compiled_max_marks?: number | null;
  compiled_remarks?: string | null;
  is_finalized?: boolean;
}

export interface AdminMarksScope {
  class_id: number;
  class_name: string;
  section_id: number;
  section_name: string;
  subject_id: number;
  subject_name: string;
  subject_code?: string | null;
  academic_year_id: number;
  academic_year_name: string;
  exam_configuration_id?: number;
  exam_configuration_name?: string | null;
  mapped_max_marks?: number | null;
}

export interface AdminMarksSheetResponse {
  marked_on: string;
  scope: AdminMarksScope;
  teachers: AdminMarksTeacherColumn[];
  rows: AdminMarksRow[];
  is_finalized: boolean;
}
