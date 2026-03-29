import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { SectionTimetableResponse, TimeSlot, TimetableDay } from '../../models/timetable';

@Injectable({
  providedIn: 'root'
})
export class TimetableService {
  private readonly api = inject(ApiClient);

  listTimeSlots() {
    return this.api.get<TimeSlot[]>('timetable/time-slots');
  }

  createTimeSlot(payload: {
    name: string;
    start_time: string;
    end_time: string;
    is_break?: boolean;
    slot_order: number;
  }) {
    return this.api.post<{ message: string; data: TimeSlot }>('timetable/time-slots', payload);
  }

  updateTimeSlot(id: number, payload: Partial<{
    name: string;
    start_time: string;
    end_time: string;
    is_break: boolean;
    slot_order: number;
  }>) {
    return this.api.put<{ message: string; data: TimeSlot }>(`timetable/time-slots/${id}`, payload);
  }

  deleteTimeSlot(id: number) {
    return this.api.delete<{ message: string }>(`timetable/time-slots/${id}`);
  }

  getSectionTimetable(params: { academic_year_id: number; section_id: number }) {
    return this.api.get<SectionTimetableResponse>('timetable/section', params);
  }

  downloadSectionTimetablePdf(params: { academic_year_id: number; section_id: number }) {
    return this.api.getBlob('timetable/section/download', params);
  }

  getStudentTimetable(params?: { academic_year_id?: number }) {
    return this.api.get<SectionTimetableResponse>('timetable/student/me', params);
  }

  downloadStudentTimetablePdf(params?: { academic_year_id?: number }) {
    return this.api.getBlob('timetable/student/me/download', params);
  }

  saveSectionTimetable(payload: {
    academic_year_id: number;
    section_id: number;
    entries: Array<{
      day_of_week: TimetableDay;
      time_slot_id: number;
      subject_id?: number | null;
      teacher_id?: number | null;
      room_number?: string | null;
    }>;
  }) {
    return this.api.post<{ message: string }>('timetable/section', payload);
  }
}
