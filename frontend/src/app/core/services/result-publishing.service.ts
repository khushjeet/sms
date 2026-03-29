import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { PaginatedResponse } from '../../models/pagination';
import { PublishedResultPaperResponse, PublishedResultRow } from '../../models/result-publishing';

@Injectable({
  providedIn: 'root'
})
export class ResultPublishingService {
  private readonly api = inject(ApiClient);

  listPublished(params?: { exam_session_id?: number; class_id?: number; search?: string; per_page?: number; page?: number }) {
    return this.api.get<PaginatedResponse<PublishedResultRow> & { hidden_result_notice?: string | null }>('results/published', params);
  }

  listSessions(params?: { class_id?: number; academic_year_id?: number; status?: string; per_page?: number }) {
    return this.api.get<PaginatedResponse<{
      id: number;
      name: string;
      class_id: number;
      academic_year_id: number;
      status: string;
    }>>('results/sessions', params);
  }

  listPublishedSessions(params?: { class_id?: number; academic_year_id?: number }) {
    return this.api.get<{
      data: Array<{
        id: number;
        name: string;
        class_id: number;
        class_name?: string | null;
        academic_year_id: number;
        academic_year_name?: string | null;
        status: string;
        published_results_count: number;
        finalized_compiled_rows?: number;
        latest_marked_on?: string | null;
      }>
    }>('results/published/sessions', params);
  }

  publishClassWise(payload: { exam_session_id: number; class_id: number; marked_on?: string; reason?: string }) {
    return this.api.post<{ message: string; published_count: number }>('results/publish/class-wise', payload);
  }

  getResultPaper(studentResultId: number) {
    return this.api.get<PublishedResultPaperResponse>(`results/${studentResultId}/paper`);
  }

  setVisibility(
    studentResultId: number,
    payload: { visibility_status: 'visible' | 'withheld' | 'under_review' | 'disciplinary_hold'; reason?: string }
  ) {
    return this.api.post<{ message: string }>(`results/${studentResultId}/visibility`, payload);
  }
}
