export type TimetableDay = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday';

export interface TimeSlot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  time_range?: string;
  is_break: boolean;
  slot_order: number;
}

export interface TimetableRow {
  id: number;
  academic_year_id: number;
  academic_year_name?: string | null;
  section_id: number;
  day_of_week: TimetableDay;
  day_label?: string;
  time_slot_id: number;
  subject_id?: number | null;
  teacher_id?: number | null;
  room_number?: string | null;
  time_slot_name?: string | null;
  time_slot_order?: number | null;
  start_time?: string | null;
  end_time?: string | null;
  time_range?: string | null;
  is_break?: boolean;
  subject_name?: string | null;
  subject_code?: string | null;
  teacher_name?: string | null;
  class_name?: string | null;
  section_name?: string | null;
}

export interface SectionTimetableResponse {
  meta: {
    academic_year_id: number;
    academic_year_name?: string | null;
    section_id: number;
    class_id: number;
    section_name: string;
    class_name?: string | null;
  };
  days: Array<{ value: TimetableDay; label: string }>;
  slots: TimeSlot[];
  rows: TimetableRow[];
  matrix: Array<{
    slot: TimeSlot;
    days: Partial<Record<TimetableDay, TimetableRow | null>>;
  }>;
}
