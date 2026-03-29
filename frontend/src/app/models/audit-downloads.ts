import { PaginatedResponse } from './pagination';

export interface AuditDownloadCatalogReport {
  key: string;
  label: string;
  formats: string[];
}

export interface AuditDownloadCatalogModule {
  module: string;
  title: string;
  route: string;
  description: string;
  available_formats: string[];
  available_items: number;
  recent_downloads: number;
  reports: AuditDownloadCatalogReport[];
}

export interface AuditDownloadCatalogResponse {
  modules: AuditDownloadCatalogModule[];
}

export interface AuditDownloadLogItem {
  id: number;
  module: string;
  report_key: string;
  report_label: string;
  format: string;
  status: string;
  file_name?: string | null;
  file_checksum?: string | null;
  row_count?: number | null;
  filters?: Record<string, unknown> | null;
  context?: Record<string, unknown> | null;
  downloaded_at?: string | null;
  user?: {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    email?: string | null;
  } | null;
}

export type AuditDownloadLogResponse = PaginatedResponse<AuditDownloadLogItem>;

export interface AuditDownloadLogPayload {
  module: string;
  report_key: string;
  report_label: string;
  format: string;
  status?: string;
  file_name?: string | null;
  file_checksum?: string | null;
  row_count?: number | null;
  filters?: Record<string, unknown> | null;
  context?: Record<string, unknown> | null;
}
