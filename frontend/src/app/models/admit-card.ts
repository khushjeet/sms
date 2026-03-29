export interface AdmitCardItem {
  id: number;
  status: 'draft' | 'published' | 'blocked' | 'revoked' | string;
  exam_name: string | null;
  version: number;
  published_at: string | null;
  download_url: string | null;
}

export interface MyAdmitCardResponse {
  state: 'not_generated' | 'generated_not_published' | 'published' | 'blocked';
  message: string | null;
  admit_card: AdmitCardItem | null;
}

export interface AdmitPaperResponse {
  school: {
    name: string | null;
    logo_url: string | null;
    logo_data_url?: string | null;
    address: string | null;
    phone?: string | null;
    website?: string | null;
    reg_no?: string | null;
    udise?: string | null;
    watermark_text?: string | null;
    watermark_logo_url?: string | null;
    watermark_logo_data_url?: string | null;
  };
  admit_card: {
    id: number;
    student_name: string;
    parents_name?: string | null;
    father_name?: string | null;
    mother_name?: string | null;
    dob?: string | null;
    address?: string | null;
    photo_url?: string | null;
    enrollment_number?: string | null;
    registration_number?: string | null;
    class_name?: string | null;
    exam_name?: string | null;
    academic_year?: string | null;
    roll_number?: string | null;
    seat_number?: string | null;
    center_name?: string | null;
    status: string;
    version: number;
    published_at?: string | null;
    schedule_snapshot_version: number;
    schedule: Array<{
      subject_id: number;
      subject_name?: string | null;
      subject_code?: string | null;
      exam_date?: string | null;
      exam_shift?: '1st Shift' | '2nd Shift' | string | null;
      start_time?: string | null;
      end_time?: string | null;
      room_number?: string | null;
      max_marks?: number | null;
    }>;
  };
}

export interface AdmitSessionCard {
  id: number;
  student_name: string | null;
  roll_number: string | null;
  seat_number: string | null;
  status: string;
  version: number;
  published_at: string | null;
  visibility_status: 'visible' | 'withheld' | 'under_review' | 'disciplinary_hold' | string;
}

export interface BulkAdmitPaperResponse {
  school: {
    name: string | null;
    logo_url: string | null;
    logo_data_url?: string | null;
    address: string | null;
    phone?: string | null;
    website?: string | null;
    reg_no?: string | null;
    udise?: string | null;
    watermark_text?: string | null;
    watermark_logo_url?: string | null;
    watermark_logo_data_url?: string | null;
  };
  session: {
    id: number;
    name: string | null;
    status: string | null;
    class_name: string | null;
    academic_year: string | null;
    exam_configuration: string | null;
  };
  admitCards: AdmitPaperResponse['admit_card'][];
}


