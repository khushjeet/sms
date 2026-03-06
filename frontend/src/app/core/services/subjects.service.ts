import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { Subject, SubjectTeacherAssignment } from '../../models/subject';

@Injectable({
  providedIn: 'root'
})
export class SubjectsService {
  private readonly api = inject(ApiClient);

  list(params?: {
    search?: string;
    status?: string;
    type?: string;
    is_active?: boolean;
    class_id?: number;
    academic_year_id?: number;
    page?: number;
    per_page?: number;
  }) {
    return this.api.get<PaginatedResponse<Subject>>('subjects', params);
  }

  create(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; data: Subject }>('subjects', payload);
  }

  getById(id: number) {
    return this.api.get<Subject>(`subjects/${id}`);
  }

  update(id: number, payload: Record<string, unknown>) {
    return this.api.put<{ message: string; data: Subject }>(`subjects/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`subjects/${id}`);
  }

  upsertClassMapping(
    subjectId: number,
    payload: {
      class_id: number;
      academic_year_id: number;
      academic_year_exam_config_id: number;
      max_marks: number;
      pass_marks: number;
      is_mandatory: boolean;
    }
  ) {
    return this.api.post<{ message: string }>(`subjects/${subjectId}/class-mappings`, payload);
  }

  removeClassMapping(subjectId: number, classId: number, academicYearId: number) {
    return this.api.delete<{ message: string }>(
      `subjects/${subjectId}/class-mappings/${classId}/${academicYearId}`
    );
  }

  listTeacherAssignments(
    subjectId: number,
    params?: { academic_year_id?: number; section_id?: number; teacher_id?: number }
  ) {
    return this.api.get<SubjectTeacherAssignment[]>(`subjects/${subjectId}/teacher-assignments`, params);
  }

  assignTeachers(
    subjectId: number,
    payload: { teacher_ids: number[]; section_id: number; academic_year_id: number; academic_year_exam_config_id: number }
  ) {
    return this.api.post<{ message: string }>(`subjects/${subjectId}/teacher-assignments`, payload);
  }

  removeTeacherAssignment(subjectId: number, assignmentId: number) {
    return this.api.delete<{ message: string }>(`subjects/${subjectId}/teacher-assignments/${assignmentId}`);
  }
}
