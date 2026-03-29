export interface PublishedResultRow {
  id: number;
  serial_number: number;
  student_name: string;
  enrollment_number?: string | number | null;
  registration_number?: string | null;
  exam_name?: string | null;
  class_name?: string | null;
  academic_year?: string | null;
  percentage: number;
  grade?: string | null;
  result_status: 'pass' | 'fail' | 'compartment' | string;
  version: number;
  published_at?: string | null;
  visibility_status: 'visible' | 'withheld' | 'under_review' | 'disciplinary_hold' | string;
}

export interface PublishedResultPaper {
  serial_number: number;
  student_result_id: number;
  student_name: string;
  parents_name?: string | null;
  address?: string | null;
  photo_url?: string | null;
  photo_data_url?: string | null;
  roll_number?: string | number | null;
  enrollment_number?: string | number | null;
  registration_number?: string | null;
  class_name?: string | null;
  exam_name?: string | null;
  academic_year?: string | null;
  published_at?: string | null;
  total_marks: number;
  total_passing_marks?: number;
  total_max_marks: number;
  percentage: number;
  grade?: string | null;
  rank?: number | null;
  result_status: string;
  version: number;
  qr_verify_url: string;
  subjects: Array<{
    subject_id: number;
    subject_name?: string | null;
    subject_code?: string | null;
    is_absent?: boolean;
    obtained_marks: number;
    passing_marks?: number;
    max_marks: number;
    grade?: string | null;
  }>;
}

export interface PublishedResultPaperResponse {
  school: {
    name: string;
    logo_url?: string | null;
    logo_data_url?: string | null;
    address?: string | null;
    phone?: string | null;
    mobile_number_1?: string | null;
    mobile_number_2?: string | null;
    website?: string | null;
    registration_number?: string | null;
    udise_code?: string | null;
    watermark_text?: string | null;
    watermark_logo_url?: string | null;
    watermark_logo_data_url?: string | null;
  };
  result_paper: PublishedResultPaper;
}

export interface MissingPublishedStudent {
  enrollment_id: number;
  roll_number?: string | number | null;
  admission_number?: string | null;
  student_name: string;
  section_name?: string | null;
  missing_subjects?: Array<{
    subject_id: number;
    subject_name?: string | null;
    subject_code?: string | null;
  }>;
}
