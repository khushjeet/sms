import { AcademicYear } from './academic-year';
import { ClassModel } from './class';
import { Section } from './section';
import { Student } from './student';

export interface Enrollment {
  id: number;
  student_id: number;
  academic_year_id: number;
  class_id?: number | null;
  section_id?: number | null;
  roll_number?: number | null;
  enrollment_date: string;
  status: string;
  is_locked?: boolean;
  remarks?: string | null;
  student?: Student;
  academicYear?: AcademicYear;
  classModel?: ClassModel;
  section?: Section;
}

export interface EnrollmentHistoryItem {
  id: number;
  academic_year: string | null;
  academic_year_id: number;
  class: string;
  section: string;
  roll_number?: number | null;
  status: string;
  enrollment_date: string;
  is_locked: boolean;
  remarks?: string | null;
  promoted_from_enrollment_id?: number | null;
}

export interface EnrollmentHistoryResponse {
  current_enrollment_id: number;
  history: EnrollmentHistoryItem[];
  total_enrollments: number;
}
