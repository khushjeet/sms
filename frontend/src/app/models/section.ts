import { AcademicYear } from './academic-year';
import { ClassModel } from './class';

export interface Section {
  id: number;
  class_id?: number;
  academic_year_id?: number;
  name: string;
  capacity?: number;
  room_number?: string | null;
  status?: string;
  class?: ClassModel;
  academicYear?: AcademicYear;
}
