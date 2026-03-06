import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  LeaveRequestPaginated,
  LeaveType,
  PayrollBatchDetail,
  PayrollBatchPaginated,
  PayrollPeriodOption,
  SelfieAttendanceDailyPaginated,
  SalaryTemplate,
  SalaryTemplateComponentInput
} from '../../models/hr-payroll';

@Injectable({
  providedIn: 'root'
})
export class HrPayrollService {
  private readonly api = inject(ApiClient);

  markAttendance(payload: {
    staff_id: number;
    date: string;
    status: 'present' | 'absent' | 'half_day' | 'leave';
    late_minutes?: number | null;
    remarks?: string;
    override_locked_month?: boolean;
    override_reason?: string;
  }) {
    return this.api.post<{ message: string }>('hr/attendance/mark', payload);
  }

  lockMonth(payload: { year: number; month: number }) {
    return this.api.post<{ message: string }>('hr/attendance/lock-month', payload);
  }

  unlockMonth(payload: { year: number; month: number; override_reason: string }) {
    return this.api.post<{ message: string }>('hr/attendance/unlock-month', payload);
  }

  listSelfieDailyAttendance(params?: { date?: string; status?: 'pending' | 'approved' | 'rejected'; per_page?: number; page?: number }) {
    return this.api.get<SelfieAttendanceDailyPaginated>('hr/attendance/selfie-daily', params);
  }

  approveSelfieAttendance(sessionId: number, payload?: { review_note?: string }) {
    return this.api.post<{ message: string }>(`hr/attendance/selfie/${sessionId}/approve`, payload ?? {});
  }

  listLeaveTypes() {
    return this.api.get<LeaveType[]>('hr/leave/types');
  }

  listLeaveRequests(params?: { status?: string; staff_id?: number; per_page?: number; page?: number }) {
    return this.api.get<LeaveRequestPaginated>('hr/leave/requests', params);
  }

  createLeaveRequest(payload: {
    staff_id: number;
    leave_type_id: number;
    start_date: string;
    end_date: string;
    reason: string;
  }) {
    return this.api.post<{ message: string }>('hr/leave/requests', payload);
  }

  decideLeaveRequest(leaveId: number, payload: { status: 'approved' | 'rejected'; remarks?: string }) {
    return this.api.post<{ message: string }>(`hr/leave/requests/${leaveId}/decision`, payload);
  }

  postLeaveLedger(payload: {
    staff_id: number;
    leave_type_id?: number | null;
    entry_type: 'credit' | 'adjustment';
    quantity: number;
    entry_date: string;
    remarks?: string;
  }) {
    return this.api.post<{ message: string }>('hr/leave/ledger', payload);
  }

  getLeaveBalance(staffId: number, leaveTypeId?: number | null) {
    return this.api.get<{
      staff_id: number;
      leave_type_id?: number | null;
      credits: number;
      debits: number;
      adjustments: number;
      balance: number;
    }>(`hr/leave/balance/${staffId}`, {
      leave_type_id: leaveTypeId ?? undefined
    });
  }

  listSalaryTemplates() {
    return this.api.get<SalaryTemplate[]>('hr/salary/templates');
  }

  createSalaryTemplate(payload: {
    name: string;
    description?: string;
    is_active?: boolean;
    components: SalaryTemplateComponentInput[];
  }) {
    return this.api.post<{ message: string; data: SalaryTemplate }>('hr/salary/templates', payload);
  }

  assignSalaryStructure(payload: {
    staff_id: number;
    salary_template_id: number;
    effective_from: string;
    notes?: string;
  }) {
    return this.api.post<{ message: string }>('hr/salary/assignments', payload);
  }

  listPayrollBatches(params?: {
    year?: number;
    month?: number;
    status?: 'generated' | 'finalized' | 'paid';
    per_page?: number;
    page?: number;
  }) {
    return this.api.get<PayrollBatchPaginated>('hr/payroll', params);
  }

  listPayrollPeriods(params?: { months_back?: number; months_forward?: number }) {
    return this.api.get<PayrollPeriodOption[]>('hr/payroll/period-options', params);
  }

  generatePayroll(payload: { year: number; month: number; force_regenerate?: boolean }) {
    return this.api.post<{ message: string; data: { id: number } }>('hr/payroll/generate', payload);
  }

  finalizePayroll(batchId: number) {
    return this.api.post<{ message: string }>(`hr/payroll/${batchId}/finalize`, {});
  }

  markPayrollPaid(batchId: number) {
    return this.api.post<{ message: string }>(`hr/payroll/${batchId}/mark-paid`, {});
  }

  getPayrollBatch(batchId: number) {
    return this.api.get<PayrollBatchDetail>(`hr/payroll/${batchId}`);
  }

  addPayrollAdjustment(
    batchId: number,
    itemId: number,
    payload: { adjustment_type: 'recovery' | 'bonus' | 'correction'; amount: number; remarks?: string }
  ) {
    return this.api.post<{ message: string }>(`hr/payroll/${batchId}/items/${itemId}/adjustments`, payload);
  }
}
