export interface SubjectClassPivot {
  academic_year_id: number;
  academic_year_exam_config_id?: number | null;
  academic_year_exam_config_name?: string | null;
  max_marks: number;
  pass_marks: number;
  is_mandatory: number | boolean;
}

export interface SubjectClassMapping {
  id: number;
  name: string;
  numeric_order?: number;
  pivot: SubjectClassPivot;
}

export interface Subject {
  id: number;
  name: string;
  code: string;
  subject_code?: string | null;
  short_name?: string | null;
  type: 'core' | 'elective' | 'optional';
  category?: string | null;
  description?: string | null;
  status: 'active' | 'inactive';
  is_active?: boolean;
  credits?: number | null;
  effective_from?: string | null;
  effective_to?: string | null;
  board_code?: string | null;
  lms_code?: string | null;
  erp_code?: string | null;
  classes?: SubjectClassMapping[];
}

export interface SubjectTeacherAssignment {
  id: number;
  subject_id: number;
  teacher_id: number;
  class_id: number;
  section_id?: number | null;
  academic_year_id: number;
  academic_year_exam_config_id?: number | null;
  academic_year_exam_config_name?: string | null;
  teacher_name: string;
  teacher_email?: string | null;
  section_name?: string | null;
  class_name: string;
  academic_year_name: string;
  created_at?: string;
}
