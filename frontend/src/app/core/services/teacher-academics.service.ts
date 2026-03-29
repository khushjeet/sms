import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { TeacherAssignment, TeacherAttendanceRow, TeacherMarksRow, TeacherTimetableResponse } from '../../models/teacher-academic';

@Injectable({
  providedIn: 'root'
})
export class TeacherAcademicsService {
  private readonly api = inject(ApiClient);

  listAssignments() {
    return this.api.get<TeacherAssignment[]>('teacher-academics/assignments');
  }

  getTimetable(params?: { academic_year_id?: number }) {
    return this.api.get<TeacherTimetableResponse>('teacher-academics/timetable', params);
  }

  getAttendanceSheet(params: { assignment_id: number; date: string }) {
    return this.api.get<TeacherAttendanceRow[]>('teacher-academics/attendance-sheet', params);
  }

  saveAttendance(payload: {
    assignment_id: number;
    date: string;
    attendances: Array<{ enrollment_id: number; status: 'present' | 'absent' | 'leave' | 'half_day'; remarks?: string }>;
  }) {
    return this.api.post<{ message: string }>('teacher-academics/attendance', payload);
  }

  getMarksSheet(params: { assignment_id: number; marked_on?: string; exam_configuration_id: number }) {
    return this.api.get<{ marked_on: string; exam_configuration_id: number; rows: TeacherMarksRow[] }>('teacher-academics/marks-sheet', params);
  }

  saveMarks(payload: {
    assignment_id: number;
    marked_on: string;
    exam_configuration_id: number;
    marks: Array<{ enrollment_id: number; marks_obtained?: number | null; max_marks?: number | null; remarks?: string }>;
  }) {
    return this.api.post<{ message: string }>('teacher-academics/marks', payload);
  }
}
