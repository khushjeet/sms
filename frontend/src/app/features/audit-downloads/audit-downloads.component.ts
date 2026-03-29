import { DatePipe, NgFor, NgIf, TitleCasePipe } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { AuditDownloadsService } from '../../core/services/audit-downloads.service';
import { AuditDownloadCatalogModule, AuditDownloadLogItem } from '../../models/audit-downloads';

@Component({
  selector: 'app-audit-downloads',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, TitleCasePipe, FormsModule],
  templateUrl: './audit-downloads.component.html',
  styleUrl: './audit-downloads.component.scss'
})
export class AuditDownloadsComponent {
  private readonly auditDownloadsService = inject(AuditDownloadsService);
  private readonly router = inject(Router);

  readonly loading = signal(false);
  readonly loadingLogs = signal(false);
  readonly exportingCsv = signal(false);
  readonly exportingArchive = signal(false);
  readonly modules = signal<AuditDownloadCatalogModule[]>([]);
  readonly logs = signal<AuditDownloadLogItem[]>([]);
  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);

  readonly moduleFilter = signal('');
  readonly reportKeyFilter = signal('');
  readonly formatFilter = signal('');
  readonly searchFilter = signal('');
  readonly dateFrom = signal('');
  readonly dateTo = signal('');

  ngOnInit() {
    this.loadCatalog();
    this.loadLogs();
  }

  openModule(route: string) {
    this.router.navigateByUrl(route);
  }

  checksumFor(log: AuditDownloadLogItem): string {
    return log.file_checksum ?? '-';
  }

  applyFilters() {
    this.loadLogs();
  }

  clearFilters() {
    this.moduleFilter.set('');
    this.reportKeyFilter.set('');
    this.formatFilter.set('');
    this.searchFilter.set('');
    this.dateFrom.set('');
    this.dateTo.set('');
    this.loadLogs();
  }

  exportCsv() {
    this.exportingCsv.set(true);
    this.auditDownloadsService.exportLogsCsv(this.buildFilterParams()).subscribe({
      next: (blob) => {
        this.downloadBlob(blob, `audit_download_logs_${new Date().toISOString().slice(0, 10)}.csv`);
        this.exportingCsv.set(false);
        this.message.set('Audit log CSV downloaded.');
      },
      error: (err) => {
        this.exportingCsv.set(false);
        this.error.set(err?.error?.message || 'Unable to export audit logs CSV.');
      }
    });
  }

  exportArchive() {
    this.exportingArchive.set(true);
    this.auditDownloadsService.archiveLogs(this.buildFilterParams()).subscribe({
      next: (blob) => {
        this.downloadBlob(blob, `audit_download_archive_${new Date().toISOString().slice(0, 10)}.zip`);
        this.exportingArchive.set(false);
        this.message.set('Audit archive downloaded.');
      },
      error: (err) => {
        this.exportingArchive.set(false);
        this.error.set(err?.error?.message || 'Unable to export audit archive.');
      }
    });
  }

  private loadCatalog() {
    this.loading.set(true);
    this.error.set(null);
    this.auditDownloadsService.catalog().subscribe({
      next: (response) => {
        this.modules.set(response.modules || []);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load audit download catalog.');
      }
    });
  }

  private loadLogs() {
    this.loadingLogs.set(true);
    this.error.set(null);
    this.message.set(null);
    this.auditDownloadsService.logs({ per_page: 20, ...this.buildFilterParams() }).subscribe({
      next: (response) => {
        this.logs.set(response.data || []);
        this.loadingLogs.set(false);
      },
      error: (err) => {
        this.loadingLogs.set(false);
        this.error.set(err?.error?.message || 'Unable to load audit download history.');
      }
    });
  }

  private buildFilterParams() {
    return {
      module: this.moduleFilter() || undefined,
      report_key: this.reportKeyFilter() || undefined,
      format: this.formatFilter() || undefined,
      search: this.searchFilter().trim() || undefined,
      date_from: this.dateFrom() || undefined,
      date_to: this.dateTo() || undefined,
    };
  }

  private downloadBlob(blob: Blob, filename: string) {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }
}
