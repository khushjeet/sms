import { Component, inject, signal } from '@angular/core';
import { NgFor, NgIf, JsonPipe } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { HrPayrollService } from '../../core/services/hr-payroll.service';
import { PayrollBatch, PayrollBatchDetail } from '../../models/hr-payroll';

@Component({
  selector: 'app-staff-payout',
  standalone: true,
  imports: [NgIf, NgFor, JsonPipe, ReactiveFormsModule],
  templateUrl: './staff-payout.component.html',
  styleUrl: './staff-payout.component.scss'
})
export class StaffPayoutComponent {
  private readonly fb = inject(FormBuilder);
  private readonly hrPayrollService = inject(HrPayrollService);

  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly busy = signal<string | null>(null);
  readonly batches = signal<PayrollBatch[]>([]);
  readonly detail = signal<PayrollBatchDetail | null>(null);

  readonly filterForm = this.fb.nonNullable.group({
    year: [new Date().getFullYear()],
    month: [''],
    status: ['']
  });

  readonly generateForm = this.fb.nonNullable.group({
    year: [new Date().getFullYear(), [Validators.required, Validators.min(2000), Validators.max(2100)]],
    month: [new Date().getMonth() + 1, [Validators.required, Validators.min(1), Validators.max(12)]]
  });

  readonly adjustmentForm = this.fb.nonNullable.group({
    item_id: ['', Validators.required],
    adjustment_type: ['correction' as 'recovery' | 'bonus' | 'correction', Validators.required],
    amount: ['', Validators.required],
    remarks: ['']
  });

  ngOnInit() {
    this.loadBatches();
  }

  loadBatches() {
    const raw = this.filterForm.getRawValue();
    this.start('list');
    this.hrPayrollService
      .listPayrollBatches({
        year: raw.year ? Number(raw.year) : undefined,
        month: raw.month ? Number(raw.month) : undefined,
        status: (raw.status || undefined) as 'generated' | 'finalized' | 'paid' | undefined,
        per_page: 50
      })
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: (response) => this.batches.set(response.data || []),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load payroll batches.')
      });
  }

  generatePayroll() {
    if (this.generateForm.invalid) {
      this.generateForm.markAllAsTouched();
      return;
    }
    const raw = this.generateForm.getRawValue();
    this.start('generate');
    this.hrPayrollService
      .generatePayroll({
        year: Number(raw.year),
        month: Number(raw.month)
      })
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: (response) => {
          this.message.set(response.message || 'Payroll generated.');
          this.loadBatches();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to generate payroll.')
      });
  }

  selectBatch(batchId: number) {
    this.start('detail');
    this.hrPayrollService
      .getPayrollBatch(batchId)
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: (row) => this.detail.set(row),
        error: (err) => this.error.set(err?.error?.message || 'Unable to load payout details.')
      });
  }

  finalizeBatch() {
    const batch = this.detail();
    if (!batch) {
      this.error.set('Select a batch first.');
      return;
    }
    this.start('finalize');
    this.hrPayrollService
      .finalizePayroll(batch.id)
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: () => {
          this.message.set('Payroll finalized.');
          this.selectBatch(batch.id);
          this.loadBatches();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to finalize payroll.')
      });
  }

  markBatchPaid() {
    const batch = this.detail();
    if (!batch) {
      this.error.set('Select a batch first.');
      return;
    }
    this.start('mark_paid');
    this.hrPayrollService
      .markPayrollPaid(batch.id)
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: () => {
          this.message.set('Staff payout marked as paid.');
          this.selectBatch(batch.id);
          this.loadBatches();
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to mark payout paid.')
      });
  }

  addAdjustment() {
    const batch = this.detail();
    if (!batch) {
      this.error.set('Select a batch first.');
      return;
    }
    if (this.adjustmentForm.invalid) {
      this.adjustmentForm.markAllAsTouched();
      return;
    }

    const raw = this.adjustmentForm.getRawValue();
    this.start('adjustment');
    this.hrPayrollService
      .addPayrollAdjustment(batch.id, Number(raw.item_id), {
        adjustment_type: raw.adjustment_type,
        amount: Number(raw.amount),
        remarks: raw.remarks || undefined
      })
      .pipe(finalize(() => this.stop()))
      .subscribe({
        next: () => {
          this.message.set('Payout adjustment recorded.');
          this.selectBatch(batch.id);
        },
        error: (err) => this.error.set(err?.error?.message || 'Unable to post adjustment.')
      });
  }

  private start(action: string) {
    this.error.set(null);
    this.message.set(null);
    this.busy.set(action);
  }

  private stop() {
    this.busy.set(null);
  }
}
