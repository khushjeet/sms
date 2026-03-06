import { AuthUser } from './auth';
import { AcademicYear } from './academic-year';
import { ClassModel } from './class';
import { Section } from './section';

export interface EnrollmentSummary {
  id: number;
  class_id?: number | null;
  academic_year_id?: number;
  status?: string;
  classModel?: ClassModel;
  section?: Section & { class?: ClassModel };
  academic_year?: AcademicYear;
}

export interface StudentProfileSummary {
  user_id?: number | null;
  avatar_url?: string | null;
  academic_year_id?: number | null;
  class_id?: number | null;
  roll_number?: string | null;
  father_name?: string | null;
  father_email?: string | null;
  father_mobile_number?: string | null;
  father_occupation?: string | null;
  mother_name?: string | null;
  mother_email?: string | null;
  mother_mobile_number?: string | null;
  mother_occupation?: string | null;
  bank_account_number?: string | null;
  bank_account_holder?: string | null;
  ifsc_code?: string | null;
  permanent_address?: string | null;
  current_address?: string | null;
  academic_year?: AcademicYear;
  class?: ClassModel;
}

export interface Student {
  id: number;
  user_id?: number;
  avatar_url?: string | null;
  admission_number: string;
  admission_date: string;
  date_of_birth: string;
  gender: string;
  status: string;
  user: AuthUser;
  currentEnrollment?: EnrollmentSummary | null;
  profile?: StudentProfileSummary | null;
  remarks?: string | null;
  blood_group?: string | null;
  address?: string | null;
  city?: string | null;
  state?: string | null;
  pincode?: string | null;
  nationality?: string | null;
  religion?: string | null;
  category?: string | null;
  aadhar_number?: string | null;
  medical_info?: unknown;
}

export interface StudentFinancialSummary {
  total_fees: number;
  total_paid: number;
  pending_dues: number;
  by_year: Array<{
    academic_year: string;
    total_fee: number;
    total_paid: number;
    pending: number;
  }>;
}
