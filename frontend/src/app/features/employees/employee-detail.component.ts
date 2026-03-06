import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { NgFor, NgIf } from '@angular/common';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { jsPDF } from 'jspdf';
import {
  Employee,
  EmployeeDocument,
  StaffAttendanceHistoryRow,
  StaffPayoutHistoryRow
} from '../../models/employee';
import { EmployeesService } from '../../core/services/employees.service';

@Component({
  selector: 'app-employee-detail',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, ReactiveFormsModule],
  templateUrl: './employee-detail.component.html',
  styleUrl: './employee-detail.component.scss'
})
export class EmployeeDetailComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);
  private readonly employeesService = inject(EmployeesService);

  readonly loading = signal(true);
  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly downloadingDocumentId = signal<number | null>(null);
  readonly loadingAttendance = signal(false);
  readonly loadingPayouts = signal(false);
  readonly employee = signal<Employee | null>(null);
  readonly attendanceRows = signal<StaffAttendanceHistoryRow[]>([]);
  readonly payoutRows = signal<StaffPayoutHistoryRow[]>([]);

  readonly attendanceFilterForm = this.fb.nonNullable.group({
    start_date: [''],
    end_date: [''],
    status: ['']
  });

  readonly payoutFilterForm = this.fb.nonNullable.group({
    year: [new Date().getFullYear()],
    month: [''],
    status: ['']
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      this.loading.set(false);
      this.error.set('Invalid employee id.');
      return;
    }

    this.employeesService.getById(id).subscribe({
      next: (employee) => {
        this.employee.set(employee);
        this.loading.set(false);
        this.loadAttendanceHistory();
        this.loadPayoutHistory();
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load employee.');
      }
    });
  }

  deleteEmployee() {
    const employee = this.employee();
    if (!employee) {
      return;
    }
    if (!confirm('Archive this employee profile?')) {
      return;
    }

    this.employeesService.delete(employee.id).subscribe({
      next: () => this.router.navigate(['/employees']),
      error: (err) => this.error.set(err?.error?.message || 'Unable to archive employee.')
    });
  }

  downloadDocument(doc: EmployeeDocument) {
    const employee = this.employee();
    if (!employee) {
      return;
    }
    this.downloadingDocumentId.set(doc.id);
    this.error.set(null);

    this.employeesService.downloadDocument(employee.id, doc.id).subscribe({
      next: (blob) => {
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = doc.original_name || doc.file_name;
        a.click();
        URL.revokeObjectURL(objectUrl);
        this.downloadingDocumentId.set(null);
      },
      error: (err) => {
        this.downloadingDocumentId.set(null);
        this.error.set(err?.error?.message || 'Unable to download document.');
      }
    });
  }

  loadAttendanceHistory() {
    const employee = this.employee();
    if (!employee) {
      return;
    }

    const raw = this.attendanceFilterForm.getRawValue();
    this.loadingAttendance.set(true);
    this.error.set(null);
    this.employeesService
      .attendanceHistory(employee.id, {
        start_date: raw.start_date || undefined,
        end_date: raw.end_date || undefined,
        status: (raw.status || undefined) as 'present' | 'absent' | 'half_day' | 'leave' | undefined,
        per_page: 365
      })
      .subscribe({
        next: (response) => {
          this.attendanceRows.set(response.data || []);
          this.loadingAttendance.set(false);
        },
        error: (err) => {
          this.loadingAttendance.set(false);
          this.error.set(err?.error?.message || 'Unable to load attendance history.');
        }
      });
  }

  loadPayoutHistory() {
    const employee = this.employee();
    if (!employee) {
      return;
    }

    const raw = this.payoutFilterForm.getRawValue();
    this.loadingPayouts.set(true);
    this.error.set(null);
    this.employeesService
      .payoutHistory(employee.id, {
        year: raw.year ? Number(raw.year) : undefined,
        month: raw.month ? Number(raw.month) : undefined,
        status: (raw.status || undefined) as 'generated' | 'finalized' | 'paid' | undefined,
        per_page: 240
      })
      .subscribe({
        next: (response) => {
          this.payoutRows.set(response.data || []);
          this.loadingPayouts.set(false);
        },
        error: (err) => {
          this.loadingPayouts.set(false);
          this.error.set(err?.error?.message || 'Unable to load payout history.');
        }
      });
  }

  downloadAttendanceExcel() {
    const employee = this.employee();
    if (!employee) {
      return;
    }
    const raw = this.attendanceFilterForm.getRawValue();
    this.message.set(null);
    this.error.set(null);

    this.employeesService.downloadAttendanceHistoryExcel(employee.id, {
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined,
      status: (raw.status || undefined) as 'present' | 'absent' | 'half_day' | 'leave' | undefined
    }).subscribe({
      next: (blob) => this.downloadBlob(blob, `staff_attendance_${employee.employee_id}.csv`),
      error: (err) => this.error.set(err?.error?.message || 'Unable to download attendance Excel.')
    });
  }

  downloadPayoutExcel() {
    const employee = this.employee();
    if (!employee) {
      return;
    }
    const raw = this.payoutFilterForm.getRawValue();
    this.message.set(null);
    this.error.set(null);

    this.employeesService.downloadPayoutHistoryExcel(employee.id, {
      year: raw.year ? Number(raw.year) : undefined,
      month: raw.month ? Number(raw.month) : undefined,
      status: (raw.status || undefined) as 'generated' | 'finalized' | 'paid' | undefined
    }).subscribe({
      next: (blob) => this.downloadBlob(blob, `staff_payout_${employee.employee_id}.csv`),
      error: (err) => this.error.set(err?.error?.message || 'Unable to download payout Excel.')
    });
  }

  downloadAttendancePdf() {
    const employee = this.employee();
    const rows = this.attendanceRows();
    if (!employee || !rows.length) {
      this.error.set('No attendance rows available to export.');
      return;
    }

    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const margin = 24;
    const pageHeight = doc.internal.pageSize.getHeight();
    let y = 44;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text(`Staff Attendance History - ${this.fullName(employee)} (${employee.employee_id})`, margin, y);
    y += 16;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text(`Generated at: ${new Date().toLocaleString()}`, margin, y);
    y += 16;

    const headers = ['Date', 'Status', 'Late', 'Remarks', 'Override'];
    const widths = [90, 90, 60, 230, 230];
    this.drawPdfRow(doc, y, headers, widths, true);
    y += 16;

    rows.forEach((row) => {
      if (y > pageHeight - 26) {
        doc.addPage();
        y = 34;
        this.drawPdfRow(doc, y, headers, widths, true);
        y += 16;
      }
      this.drawPdfRow(doc, y, [
        row.attendance_date,
        row.status,
        String(row.late_minutes ?? ''),
        (row.remarks || '').slice(0, 90),
        (row.override_reason || '').slice(0, 90)
      ], widths, false);
      y += 16;
    });

    doc.save(`staff_attendance_${employee.employee_id}.pdf`);
  }

  downloadPayoutPdf() {
    const employee = this.employee();
    const rows = this.payoutRows();
    if (!employee || !rows.length) {
      this.error.set('No payout rows available to export.');
      return;
    }

    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const margin = 24;
    const pageHeight = doc.internal.pageSize.getHeight();
    let y = 44;

    doc.setFontSize(12);
    doc.setFont('helvetica', 'bold');
    doc.text(`Staff Payout History - ${this.fullName(employee)} (${employee.employee_id})`, margin, y);
    y += 16;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text(`Generated at: ${new Date().toLocaleString()}`, margin, y);
    y += 16;

    const headers = ['Period', 'Status', 'Gross', 'Deduction', 'Net', 'Adjust', 'Net Final', 'Paid At'];
    const widths = [85, 70, 85, 85, 85, 85, 85, 130];
    this.drawPdfRow(doc, y, headers, widths, true);
    y += 16;

    rows.forEach((row) => {
      if (y > pageHeight - 26) {
        doc.addPage();
        y = 34;
        this.drawPdfRow(doc, y, headers, widths, true);
        y += 16;
      }
      this.drawPdfRow(doc, y, [
        `${row.year}-${String(row.month).padStart(2, '0')}`,
        row.status,
        row.gross_pay,
        row.total_deductions,
        row.net_pay,
        row.adjustment_total,
        row.net_after_adjustment,
        row.paid_at || '-'
      ], widths, false);
      y += 16;
    });

    doc.save(`staff_payout_${employee.employee_id}.pdf`);
  }

  fullName(employee: Employee): string {
    const user = employee.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }

  private downloadBlob(blob: Blob, filename: string) {
    const objectUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objectUrl;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(objectUrl);
  }

  private drawPdfRow(doc: jsPDF, y: number, columns: string[], widths: number[], head: boolean) {
    let x = 24;
    doc.setFont('helvetica', head ? 'bold' : 'normal');
    doc.setFontSize(8);

    columns.forEach((column, index) => {
      const width = widths[index] ?? 80;
      doc.rect(x, y - 10, width, 16);
      doc.text(String(column || '').slice(0, 22), x + 3, y);
      x += width;
    });
  }
}
