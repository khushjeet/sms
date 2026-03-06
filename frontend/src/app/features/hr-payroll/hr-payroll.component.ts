import { Component, computed, inject, signal } from '@angular/core';
import { NgFor, NgIf, JsonPipe } from '@angular/common';
import { FormArray, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../core/services/auth.service';
import { EmployeesService } from '../../core/services/employees.service';
import { HrPayrollService } from '../../core/services/hr-payroll.service';
import { Employee } from '../../models/employee';
import {
  LeaveRequestRow,
  LeaveType,
  PayrollBatch,
  PayrollBatchDetail,
  PayrollPeriodOption,
  SalaryTemplate,
  SelfieAttendanceDailyRow
} from '../../models/hr-payroll';

@Component({
  selector: 'app-hr-payroll',
  standalone: true,
  imports: [NgIf, NgFor, JsonPipe, ReactiveFormsModule],
  templateUrl: './hr-payroll.component.html',
  styleUrl: './hr-payroll.component.scss'
})
export class HrPayrollComponent {
  private readonly fb = inject(FormBuilder);
  private readonly authService = inject(AuthService);
  private readonly employeesService = inject(EmployeesService);
  private readonly hrPayrollService = inject(HrPayrollService);

  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly busyAction = signal<string | null>(null);

  readonly staffRows = signal<Employee[]>([]);
  readonly leaveTypes = signal<LeaveType[]>([]);
  readonly leaveRequests = signal<LeaveRequestRow[]>([]);
  readonly salaryTemplates = signal<SalaryTemplate[]>([]);
  readonly payrollPeriods = signal<PayrollPeriodOption[]>([]);
  readonly payrollBatches = signal<PayrollBatch[]>([]);
  readonly payrollDetail = signal<PayrollBatchDetail | null>(null);
  readonly selfieDailyRows = signal<SelfieAttendanceDailyRow[]>([]);
  readonly selfieViewer = signal<string | null>(null);
  readonly leaveBalance = signal<{
    credits: number;
    debits: number;
    adjustments: number;
    balance: number;
  } | null>(null);

  readonly role = computed(() => this.authService.user()?.role || '');
  readonly canEdit = computed(() => ['super_admin', 'school_admin', 'accountant'].includes(this.role()));

  readonly attendanceForm = this.fb.nonNullable.group({
    staff_id: ['', Validators.required],
    date: ['', Validators.required],
    status: ['present' as 'present' | 'absent' | 'half_day' | 'leave', Validators.required],
    late_minutes: [''],
    remarks: [''],
    override_locked_month: [false],
    override_reason: ['']
  });

  readonly monthLockForm = this.fb.nonNullable.group({
    period: [this.defaultPeriodValue(0), [Validators.required]],
    override_reason: ['']
  });

  readonly selfieDailyFilterForm = this.fb.nonNullable.group({
    date: [this.todayDate(), Validators.required],
    status: ['' as '' | 'pending' | 'approved' | 'rejected']
  });

  readonly leaveRequestForm = this.fb.nonNullable.group({
    staff_id: ['', Validators.required],
    leave_type_id: ['', Validators.required],
    start_date: ['', Validators.required],
    end_date: ['', Validators.required],
    reason: ['', Validators.required]
  });

  readonly leaveDecisionForm = this.fb.nonNullable.group({
    leave_id: ['', Validators.required],
    status: ['approved' as 'approved' | 'rejected', Validators.required],
    remarks: ['']
  });

  readonly leaveLedgerForm = this.fb.nonNullable.group({
    staff_id: ['', Validators.required],
    leave_type_id: [''],
    entry_type: ['credit' as 'credit' | 'adjustment', Validators.required],
    quantity: ['', Validators.required],
    entry_date: ['', Validators.required],
    remarks: ['']
  });

  readonly leaveBalanceForm = this.fb.nonNullable.group({
    staff_id: ['', Validators.required],
    leave_type_id: ['']
  });

  readonly salaryTemplateForm = this.fb.group({
    name: this.fb.nonNullable.control('', [Validators.required]),
    description: this.fb.nonNullable.control(''),
    is_active: this.fb.nonNullable.control(true),
    components: this.fb.array([
      this.buildComponentGroup('Basic', 'earning', '0'),
      this.buildComponentGroup('HRA', 'earning', '0'),
      this.buildComponentGroup('Allowance', 'earning', '0'),
      this.buildComponentGroup('Deduction', 'deduction', '0')
    ])
  });

  readonly salaryAssignmentForm = this.fb.nonNullable.group({
    staff_id: ['', Validators.required],
    salary_template_id: ['', Validators.required],
    effective_from: ['', Validators.required],
    notes: ['']
  });

  readonly payrollGenerateForm = this.fb.nonNullable.group({
    period: [this.defaultPeriodValue(-1), [Validators.required]],
    force_regenerate: [false]
  });

  readonly payrollFilterForm = this.fb.nonNullable.group({
    period: [''],
    status: ['']
  });

  readonly adjustmentForm = this.fb.nonNullable.group({
    item_id: ['', Validators.required],
    adjustment_type: ['correction' as 'recovery' | 'bonus' | 'correction', Validators.required],
    amount: ['', Validators.required],
    remarks: ['']
  });

  get salaryComponents(): FormArray {
    return this.salaryTemplateForm.get('components') as FormArray;
  }

  ngOnInit() {
    this.loadBaseData();
  }

  loadBaseData() {
    this.error.set(null);
    this.employeesService.list({ per_page: 200 }).subscribe({
      next: (response) => this.staffRows.set(response.data || []),
      error: () => this.error.set('Unable to load staff list.')
    });

    this.hrPayrollService.listLeaveTypes().subscribe({
      next: (rows) => this.leaveTypes.set(rows),
      error: () => this.error.set('Unable to load leave types.')
    });

    this.loadPayrollPeriods();
    this.loadLeaveRequests();
    this.loadSalaryTemplates();
    this.loadPayrollBatches();
    this.loadSelfieDailyAttendance();
  }

  staffLabel(row: Employee): string {
    const name = row.user?.full_name || `${row.user?.first_name ?? ''} ${row.user?.last_name ?? ''}`.trim();
    return `${row.employee_id} - ${name || 'Staff #' + row.id}`;
  }

  addSalaryComponentRow() {
    this.salaryComponents.push(this.buildComponentGroup('Component', 'earning', '0'));
  }

  removeSalaryComponentRow(index: number) {
    if (this.salaryComponents.length <= 1) {
      return;
    }
    this.salaryComponents.removeAt(index);
  }

  markAttendance() {
    if (this.attendanceForm.invalid) {
      this.attendanceForm.markAllAsTouched();
      return;
    }

    const raw = this.attendanceForm.getRawValue();
    this.startBusy('attendance_mark');
    this.hrPayrollService.markAttendance({
      staff_id: Number(raw.staff_id),
      date: raw.date,
      status: raw.status,
      late_minutes: raw.late_minutes !== '' ? Number(raw.late_minutes) : undefined,
      remarks: raw.remarks || undefined,
      override_locked_month: raw.override_locked_month,
      override_reason: raw.override_reason || undefined
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => this.message.set('Attendance saved successfully.'),
        error: (err) => this.error.set(err?.error?.message || 'Unable to save attendance.')
      });
  }

  lockMonth() {
    if (this.monthLockForm.invalid) {
      this.monthLockForm.markAllAsTouched();
      return;
    }
    const raw = this.monthLockForm.getRawValue();
    const period = this.parsePeriod(raw.period);
    if (!period) {
      this.error.set('Select a valid attendance period.');
      return;
    }
    this.startBusy('attendance_lock');
    this.hrPayrollService.lockMonth({
      year: period.year,
      month: period.month
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => this.message.set('Attendance month locked.'),
        error: (err) => this.error.set(err?.error?.message || 'Unable to lock month.')
      });
  }

  unlockMonth() {
    const raw = this.monthLockForm.getRawValue();
    if (!raw.override_reason.trim()) {
      this.error.set('Unlock override reason is required.');
      return;
    }
    const period = this.parsePeriod(raw.period);
    if (!period) {
      this.error.set('Select a valid attendance period.');
      return;
    }
    this.startBusy('attendance_unlock');
    this.hrPayrollService.unlockMonth({
      year: period.year,
      month: period.month,
      override_reason: raw.override_reason.trim()
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => this.message.set('Attendance month unlocked with admin override.'),
        error: (err) => this.error.set(err?.error?.message || 'Unable to unlock month.')
      });
  }

  loadSelfieDailyAttendance() {
    const raw = this.selfieDailyFilterForm.getRawValue();
    this.startBusy('attendance_selfie_list');
    this.hrPayrollService.listSelfieDailyAttendance({
      date: raw.date || undefined,
      status: raw.status || undefined,
      per_page: 200
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: (response) => this.selfieDailyRows.set(response.data || []),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load daily selfie attendance.')
      });
  }

  approveSelfieAttendance(sessionId: number) {
    this.startBusy('attendance_selfie_approve');
    this.hrPayrollService.approveSelfieAttendance(sessionId)
      .pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Attendance approved.');
          this.loadSelfieDailyAttendance();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to approve attendance.')
      });
  }

  openSelfieViewer(url: string | null) {
    if (!url) {
      return;
    }
    this.selfieViewer.set(url);
  }

  closeSelfieViewer() {
    this.selfieViewer.set(null);
  }

  createLeaveRequest() {
    if (this.leaveRequestForm.invalid) {
      this.leaveRequestForm.markAllAsTouched();
      return;
    }

    const raw = this.leaveRequestForm.getRawValue();
    this.startBusy('leave_create');
    this.hrPayrollService.createLeaveRequest({
      staff_id: Number(raw.staff_id),
      leave_type_id: Number(raw.leave_type_id),
      start_date: raw.start_date,
      end_date: raw.end_date,
      reason: raw.reason.trim()
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Leave request submitted.');
          this.leaveRequestForm.reset({ staff_id: '', leave_type_id: '', start_date: '', end_date: '', reason: '' });
          this.loadLeaveRequests();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to create leave request.')
      });
  }

  decideLeaveRequest() {
    if (this.leaveDecisionForm.invalid) {
      this.leaveDecisionForm.markAllAsTouched();
      return;
    }

    const raw = this.leaveDecisionForm.getRawValue();
    this.startBusy('leave_decide');
    this.hrPayrollService.decideLeaveRequest(Number(raw.leave_id), {
      status: raw.status,
      remarks: raw.remarks || undefined
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Leave decision saved.');
          this.loadLeaveRequests();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to update leave request.')
      });
  }

  postLeaveLedger() {
    if (this.leaveLedgerForm.invalid) {
      this.leaveLedgerForm.markAllAsTouched();
      return;
    }

    const raw = this.leaveLedgerForm.getRawValue();
    this.startBusy('leave_ledger');
    this.hrPayrollService.postLeaveLedger({
      staff_id: Number(raw.staff_id),
      leave_type_id: raw.leave_type_id ? Number(raw.leave_type_id) : null,
      entry_type: raw.entry_type,
      quantity: Number(raw.quantity),
      entry_date: raw.entry_date,
      remarks: raw.remarks || undefined
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => this.message.set('Leave ledger entry posted.'),
        error: (err) => this.error.set(err?.error?.message || 'Unable to post leave ledger entry.')
      });
  }

  loadLeaveBalance() {
    if (this.leaveBalanceForm.invalid) {
      this.leaveBalanceForm.markAllAsTouched();
      return;
    }
    const raw = this.leaveBalanceForm.getRawValue();
    this.startBusy('leave_balance');
    this.hrPayrollService.getLeaveBalance(
      Number(raw.staff_id),
      raw.leave_type_id ? Number(raw.leave_type_id) : null
    ).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: (row) => this.leaveBalance.set(row),
        error: (err) => this.error.set(err?.error?.message || 'Unable to fetch leave balance.')
      });
  }

  createSalaryTemplate() {
    if (this.salaryTemplateForm.invalid) {
      this.salaryTemplateForm.markAllAsTouched();
      return;
    }

    const raw = this.salaryTemplateForm.getRawValue();
    const components = (raw.components || []).map((item) => ({
      component_name: String(item.component_name || '').trim(),
      component_type: item.component_type,
      amount: item.amount !== '' ? Number(item.amount) : null,
      percentage: item.percentage !== '' ? Number(item.percentage) : null,
      is_taxable: !!item.is_taxable,
      sort_order: Number(item.sort_order || 0)
    })).filter((item) => item.component_name);

    if (!components.length) {
      this.error.set('At least one salary component is required.');
      return;
    }

    this.startBusy('salary_template_create');
    this.hrPayrollService.createSalaryTemplate({
      name: String(raw.name || '').trim(),
      description: String(raw.description || '').trim() || undefined,
      is_active: !!raw.is_active,
      components
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Salary template created.');
          this.loadSalaryTemplates();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to create salary template.')
      });
  }

  assignSalaryStructure() {
    if (this.salaryAssignmentForm.invalid) {
      this.salaryAssignmentForm.markAllAsTouched();
      return;
    }

    const raw = this.salaryAssignmentForm.getRawValue();
    this.startBusy('salary_assign');
    this.hrPayrollService.assignSalaryStructure({
      staff_id: Number(raw.staff_id),
      salary_template_id: Number(raw.salary_template_id),
      effective_from: raw.effective_from,
      notes: raw.notes || undefined
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => this.message.set('Salary structure version assigned.'),
        error: (err) => this.error.set(err?.error?.message || 'Unable to assign salary structure.')
      });
  }

  generatePayroll() {
    if (this.payrollGenerateForm.invalid) {
      this.payrollGenerateForm.markAllAsTouched();
      return;
    }
    const raw = this.payrollGenerateForm.getRawValue();
    const period = this.parsePeriod(raw.period);
    if (!period) {
      this.error.set('Select a valid payroll period.');
      return;
    }
    this.startBusy('payroll_generate');
    this.hrPayrollService.generatePayroll({
      year: period.year,
      month: period.month,
      force_regenerate: raw.force_regenerate
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: (response) => {
          this.message.set(response.message || 'Payroll generated.');
          this.loadPayrollBatches();
          const id = Number(response.data?.id);
          if (id) {
            this.loadPayrollDetail(id);
          }
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to generate payroll.')
      });
  }

  loadPayrollBatches() {
    const raw = this.payrollFilterForm.getRawValue();
    const period = raw.period ? this.parsePeriod(raw.period) : null;
    this.startBusy('payroll_list');
    this.hrPayrollService.listPayrollBatches({
      year: period?.year,
      month: period?.month,
      status: (raw.status || undefined) as 'generated' | 'finalized' | 'paid' | undefined,
      per_page: 50
    }).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: (response) => this.payrollBatches.set(response.data || []),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load payroll batches.')
      });
  }

  loadPayrollDetail(batchId: number) {
    this.startBusy('payroll_detail');
    this.hrPayrollService.getPayrollBatch(batchId)
      .pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: (row) => this.payrollDetail.set(row),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load payroll batch details.')
      });
  }

  finalizeSelectedBatch() {
    const batch = this.payrollDetail();
    if (!batch) {
      this.error.set('Select a payroll batch first.');
      return;
    }
    this.startBusy('payroll_finalize');
    this.hrPayrollService.finalizePayroll(batch.id)
      .pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Payroll finalized.');
          this.loadPayrollDetail(batch.id);
          this.loadPayrollBatches();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to finalize payroll.')
      });
  }

  markSelectedBatchPaid() {
    const batch = this.payrollDetail();
    if (!batch) {
      this.error.set('Select a payroll batch first.');
      return;
    }
    this.startBusy('payroll_paid');
    this.hrPayrollService.markPayrollPaid(batch.id)
      .pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Payroll marked as paid.');
          this.loadPayrollDetail(batch.id);
          this.loadPayrollBatches();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to mark payroll paid.')
      });
  }

  addAdjustment() {
    const batch = this.payrollDetail();
    if (!batch) {
      this.error.set('Select a payroll batch first.');
      return;
    }
    if (this.adjustmentForm.invalid) {
      this.adjustmentForm.markAllAsTouched();
      return;
    }

    const raw = this.adjustmentForm.getRawValue();
    this.startBusy('payroll_adjustment');
    this.hrPayrollService.addPayrollAdjustment(
      batch.id,
      Number(raw.item_id),
      {
        adjustment_type: raw.adjustment_type,
        amount: Number(raw.amount),
        remarks: raw.remarks || undefined
      }
    ).pipe(finalize(() => this.stopBusy()))
      .subscribe({
        next: () => {
          this.message.set('Payroll adjustment recorded.');
          this.loadPayrollDetail(batch.id);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to add payroll adjustment.')
      });
  }

  selectLeaveForDecision(row: LeaveRequestRow) {
    this.leaveDecisionForm.patchValue({ leave_id: String(row.id), status: 'approved', remarks: '' });
  }

  selectBatch(batch: PayrollBatch) {
    this.loadPayrollDetail(batch.id);
  }

  private loadLeaveRequests() {
    this.hrPayrollService.listLeaveRequests({ per_page: 50 }).subscribe({
      next: (response) => this.leaveRequests.set(response.data || []),
      error: () => this.error.set('Unable to load leave requests.')
    });
  }

  private loadSalaryTemplates() {
    this.hrPayrollService.listSalaryTemplates().subscribe({
      next: (rows) => this.salaryTemplates.set(rows),
      error: () => this.error.set('Unable to load salary templates.')
    });
  }

  private loadPayrollPeriods() {
    this.hrPayrollService.listPayrollPeriods().subscribe({
      next: (rows) => {
        this.payrollPeriods.set(rows || []);
        if (!this.monthLockForm.controls.period.value && rows.length) {
          this.monthLockForm.patchValue({ period: rows[0].value }, { emitEvent: false });
        }
        if (!this.payrollGenerateForm.controls.period.value && rows.length) {
          this.payrollGenerateForm.patchValue({ period: rows[0].value }, { emitEvent: false });
        }
      },
      error: () => this.error.set('Unable to load payroll periods.')
    });
  }

  private startBusy(action: string) {
    this.error.set(null);
    this.message.set(null);
    this.busyAction.set(action);
  }

  private stopBusy() {
    this.busyAction.set(null);
  }

  private buildComponentGroup(
    name = '',
    type: 'earning' | 'deduction' = 'earning',
    amount = ''
  ) {
    return this.fb.nonNullable.group({
      component_name: [name, Validators.required],
      component_type: [type, Validators.required],
      amount: [amount],
      percentage: [''],
      is_taxable: [false],
      sort_order: [0]
    });
  }

  private defaultPeriodValue(monthOffset = 0): string {
    const current = new Date();
    current.setDate(1);
    current.setMonth(current.getMonth() + monthOffset);
    const year = current.getFullYear();
    const month = String(current.getMonth() + 1).padStart(2, '0');
    return `${year}-${month}`;
  }

  private todayDate(): string {
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  private parsePeriod(period: string): { year: number; month: number } | null {
    const match = /^(\d{4})-(0[1-9]|1[0-2])$/.exec((period || '').trim());
    if (!match) {
      return null;
    }

    return {
      year: Number(match[1]),
      month: Number(match[2]),
    };
  }
}
