import { AuthUser } from './auth';

export type TeacherDocumentType = 'resume' | 'identity' | 'certificate' | 'pan_card' | 'other';

export interface TeacherDocument {
  id: number;
  document_type: TeacherDocumentType;
  file_name: string;
  original_name: string;
  mime_type?: string | null;
  extension?: string | null;
  size_bytes?: number;
  file_path: string;
  uploaded_by?: number | null;
  created_at?: string;
}

export interface Teacher {
  id: number;
  user_id: number;
  user?: AuthUser;
  employee_id: string;
  joining_date: string;
  employee_type: 'teaching' | 'non_teaching';
  designation: string;
  department?: string | null;
  qualification?: string | null;
  salary?: string | number | null;
  date_of_birth: string;
  gender: 'male' | 'female' | 'other';
  address?: string | null;
  emergency_contact?: string | null;
  aadhar_number?: string | null;
  pan_number?: string | null;
  status: 'active' | 'on_leave' | 'resigned' | 'terminated';
  resignation_date?: string | null;
  documents?: TeacherDocument[];
}

