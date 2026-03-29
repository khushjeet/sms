import { ExamConfiguration } from './exam-configuration';

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
  compiled_is_absent?: boolean;
  is_finalized?: boolean;
}

export interface AdminMarksScope {
  class_id: number;
  class_name: string;
  section_id?: number | null;
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

export interface AdminMarksFilterSubjectOption {
  id: number;
  name: string;
  subject_code?: string | null;
  code?: string | null;
  max_marks?: number | null;
  pass_marks?: number | null;
  academic_year_exam_config_id?: number | null;
}

export interface AdminMarksFilterAcademicYear {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  is_current?: boolean;
}

export interface AdminMarksFiltersResponse {
  class_id: number;
  class_name: string;
  academic_year_id?: number | null;
  section_id?: number | null;
  academic_year?: AdminMarksFilterAcademicYear | null;
  sections: Array<{
    id: number;
    name: string;
    class_id: number;
    academic_year_id?: number | null;
    status?: string;
    academic_year_name?: string | null;
  }>;
  subjects: AdminMarksFilterSubjectOption[];
  exam_configurations: ExamConfiguration[];
  messages?: {
    sections?: string;
    academic_year?: string;
    subjects?: string;
    exam_configurations?: string;
  };
}

export interface AdminMarksSheetResponse {
  marked_on: string;
  scope: AdminMarksScope;
  teachers: AdminMarksTeacherColumn[];
  rows: AdminMarksRow[];
  is_finalized: boolean;
  empty_state_message?: string | null;
}
