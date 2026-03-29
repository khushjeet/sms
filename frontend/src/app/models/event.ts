import { AcademicYear } from './academic-year';
import { Enrollment } from './enrollment';
import { Student } from './student';

export interface SchoolEventItem {
  id: number;
  academic_year_id?: number | null;
  academicYear?: AcademicYear | null;
  title: string;
  event_date?: string | null;
  venue?: string | null;
  description?: string | null;
  status: 'draft' | 'published' | 'archived';
  certificate_prefix?: string | null;
  participants_count?: number;
  ranked_count?: number;
}

export interface SchoolEventParticipant {
  id: number;
  school_event_id: number;
  student_id: number;
  enrollment_id?: number | null;
  rank?: number | null;
  achievement_title?: string | null;
  remarks?: string | null;
  student?: Student;
  enrollment?: Enrollment | null;
}

export interface SchoolEventDetail extends SchoolEventItem {
  participants: SchoolEventParticipant[];
}
