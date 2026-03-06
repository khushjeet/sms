import { AuthUser } from './auth';

export type EmployeeDocumentType = 'resume' | 'identity' | 'certificate' | 'pan_card' | 'other';

export interface EmployeeDocument {
  id: number;
  document_type: EmployeeDocumentType;
  file_name: string;
  original_name: string;
  mime_type?: string | null;
  extension?: string | null;
  size_bytes?: number;
  file_path: string;
  uploaded_by?: number | null;
  created_at?: string;
}

export interface Employee {
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
  documents?: EmployeeDocument[];
}

export interface EmployeeMetadata {
  roles: string[];
  employee_types: string[];
  genders: string[];
  statuses: string[];
  document_types: EmployeeDocumentType[];
  designation_options: string[];
  department_options: string[];
}

export interface StaffAttendanceHistoryRow {
  id: number;
  staff_id: number;
  attendance_date: string;
  status: 'present' | 'absent' | 'half_day' | 'leave';
  late_minutes?: number | null;
  remarks?: string | null;
  override_reason?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface StaffPayoutHistoryRow {
  id: number;
  staff_id: number;
  payroll_batch_id: number;
  year: number;
  month: number;
  status: 'generated' | 'finalized' | 'paid';
  days_in_month: number;
  payable_days: string;
  leave_days: string;
  absent_days: string;
  gross_pay: string;
  total_deductions: string;
  net_pay: string;
  adjustment_total: string;
  net_after_adjustment: string;
  generated_at?: string | null;
  finalized_at?: string | null;
  paid_at?: string | null;
}
