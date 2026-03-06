export interface ExamConfiguration {
  id: number;
  academic_year_id: number;
  name: string;
  sequence: number;
  is_active: boolean;
  created_by?: number | null;
  created_at?: string;
  updated_at?: string;
  academic_year?: {
    id: number;
    name: string;
  };
}

