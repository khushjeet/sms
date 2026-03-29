import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';

export interface SchoolSignatures {
  principal_signature_path?: string | null;
  director_signature_path?: string | null;
}

@Injectable({
  providedIn: 'root'
})
export class SchoolSignaturesService {
  private readonly api = inject(ApiClient);

  get() {
    return this.api.get<SchoolSignatures>('school/signatures');
  }

  update(payload: FormData) {
    return this.api.post<{ message: string; data: SchoolSignatures }>('school/signatures', payload);
  }

  delete(slot: 'principal' | 'director') {
    return this.api.delete<{ message: string; data: SchoolSignatures }>(`school/signatures/${slot}`);
  }
}
