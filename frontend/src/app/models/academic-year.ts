export interface AcademicYear {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  description?: string | null;
  status?: string;
  is_current?: boolean;
}
