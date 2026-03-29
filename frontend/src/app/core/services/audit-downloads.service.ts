import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  AuditDownloadCatalogResponse,
  AuditDownloadLogPayload,
  AuditDownloadLogResponse,
} from '../../models/audit-downloads';

@Injectable({
  providedIn: 'root'
})
export class AuditDownloadsService {
  private readonly api = inject(ApiClient);

  catalog() {
    return this.api.get<AuditDownloadCatalogResponse>('audit-downloads/catalog');
  }

  logs(params?: { module?: string; page?: number; per_page?: number }) {
    return this.api.get<AuditDownloadLogResponse>('audit-downloads/logs', params);
  }

  exportLogsCsv(params?: Record<string, string | number | boolean | null | undefined>) {
    return this.api.getBlob('audit-downloads/logs/export', params);
  }

  archiveLogs(params?: Record<string, string | number | boolean | null | undefined>) {
    return this.api.getBlob('audit-downloads/logs/archive', params);
  }

  logDownload(payload: AuditDownloadLogPayload) {
    return this.api.post<{ message: string; log_id: number }>('audit-downloads/logs', payload);
  }
}
