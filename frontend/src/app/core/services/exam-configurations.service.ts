import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import { ExamConfiguration } from '../../models/exam-configuration';

@Injectable({
  providedIn: 'root'
})
export class ExamConfigurationsService {
  private readonly api = inject(ApiClient);

  list(params: { academic_year_id: number; active_only?: boolean }) {
    return this.api.get<{ data: ExamConfiguration[] }>('exam-configurations', params);
  }

  create(payload: { academic_year_id: number; name: string; sequence?: number; is_active?: boolean }) {
    return this.api.post<{ message: string; data: ExamConfiguration }>('exam-configurations', payload);
  }

  update(id: number, payload: { name?: string; sequence?: number; is_active?: boolean }) {
    return this.api.put<{ message: string; data: ExamConfiguration }>(`exam-configurations/${id}`, payload);
  }

  delete(id: number) {
    return this.api.delete<{ message: string }>(`exam-configurations/${id}`);
  }
}

