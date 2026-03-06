import { Component, computed, inject, signal } from '@angular/core';
import { NgFor, NgIf } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { AuthService } from '../../core/services/auth.service';
import { ExpensesService } from '../../core/services/expenses.service';
import {
  ExpenseAuditReportResponse,
  ExpenseRecord,
  ExpenseSummary
} from '../../models/expense';

@Component({
  selector: 'app-expenses',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './expenses.component.html',
  styleUrl: './expenses.component.scss'
})
export class ExpensesComponent {
  private readonly expensesService = inject(ExpensesService);
  private readonly authService = inject(AuthService);
  private readonly fb = inject(FormBuilder);

  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly loading = signal(false);
  readonly expenses = signal<ExpenseRecord[]>([]);
  readonly summary = signal<ExpenseSummary | null>(null);
  readonly auditReport = signal<ExpenseAuditReportResponse | null>(null);
  readonly createReceiptFile = signal<File | null>(null);
  readonly uploadReceiptFile = signal<File | null>(null);
  readonly createDropActive = signal(false);
  readonly uploadDropActive = signal(false);

  readonly userRole = computed(() => this.authService.user()?.role ?? '');
  readonly canEdit = computed(() => {
    const role = this.userRole();
    return role === 'super_admin' || role === 'school_admin';
  });
  readonly canUpload = computed(() => {
    const role = this.userRole();
    return role === 'super_admin' || role === 'school_admin' || role === 'accountant';
  });

  readonly filterForm = this.fb.nonNullable.group({
    start_date: [''],
    end_date: [''],
    category: [''],
    payment_method: [''],
    is_reversal: ['']
  });

  readonly createForm = this.fb.nonNullable.group({
    expense_date: ['', Validators.required],
    category: ['', Validators.required],
    description: [''],
    vendor_name: [''],
    amount: ['', Validators.required],
    payment_method: ['cash', Validators.required],
    reference_number: ['']
  });

  readonly reversalForm = this.fb.nonNullable.group({
    expense_id: ['', Validators.required],
    reversal_reason: ['', Validators.required],
    reversal_date: ['']
  });

  readonly receiptUploadForm = this.fb.nonNullable.group({
    expense_id: ['', Validators.required]
  });

  readonly reportForm = this.fb.nonNullable.group({
    start_date: [''],
    end_date: [''],
    group_by: ['month' as 'month' | 'year' | 'category']
  });

  ngOnInit() {
    this.loadExpenses();
  }

  loadExpenses() {
    const raw = this.filterForm.getRawValue();
    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.expensesService
      .list({
        start_date: raw.start_date || undefined,
        end_date: raw.end_date || undefined,
        category: raw.category || undefined,
        payment_method: raw.payment_method || undefined,
        is_reversal: raw.is_reversal === '' ? undefined : raw.is_reversal === 'true',
        per_page: 100
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.expenses.set(response.data.data || []);
          this.summary.set(response.summary);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load expenses.');
        }
      });
  }

  createExpense() {
    if (this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      return;
    }

    const raw = this.createForm.getRawValue();
    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    const formData = new FormData();
    formData.append('expense_date', raw.expense_date);
    formData.append('category', raw.category.trim());
    formData.append('amount', String(Number(raw.amount)));
    formData.append('payment_method', raw.payment_method);
    if (raw.description) {
      formData.append('description', raw.description);
    }
    if (raw.vendor_name) {
      formData.append('vendor_name', raw.vendor_name);
    }
    if (raw.reference_number) {
      formData.append('reference_number', raw.reference_number);
    }
    if (this.createReceiptFile()) {
      formData.append('receipt_file', this.createReceiptFile() as File);
    }

    this.expensesService
      .create(formData)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: () => {
          this.createForm.reset({
            expense_date: '',
            category: '',
            description: '',
            vendor_name: '',
            amount: '',
            payment_method: 'cash',
            reference_number: ''
          });
          this.createReceiptFile.set(null);
          this.message.set('Expense recorded.');
          this.loadExpenses();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to create expense.');
        }
      });
  }

  reverseExpense() {
    if (this.reversalForm.invalid) {
      this.reversalForm.markAllAsTouched();
      return;
    }

    const raw = this.reversalForm.getRawValue();
    const expenseId = Number(raw.expense_id);
    if (!Number.isFinite(expenseId) || expenseId <= 0) {
      this.error.set('Enter a valid expense ID.');
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.expensesService
      .reverse(expenseId, {
        reversal_reason: raw.reversal_reason.trim(),
        reversal_date: raw.reversal_date || undefined
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: () => {
          this.reversalForm.reset({ expense_id: '', reversal_reason: '', reversal_date: '' });
          this.message.set('Expense reversal recorded.');
          this.loadExpenses();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to reverse expense.');
        }
      });
  }

  uploadReceiptToExistingExpense() {
    if (this.receiptUploadForm.invalid) {
      this.receiptUploadForm.markAllAsTouched();
      return;
    }

    const file = this.uploadReceiptFile();

    const expenseId = Number(this.receiptUploadForm.getRawValue().expense_id);
    if (!Number.isFinite(expenseId) || expenseId <= 0) {
      this.error.set('Enter a valid expense ID.');
      return;
    }

    this.loading.set(true);
    this.error.set(null);
    this.message.set(null);

    this.expensesService
      .uploadReceipt(expenseId, file)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.uploadReceiptFile.set(null);
          this.message.set(response.message || 'Receipt upload request processed.');
          this.loadExpenses();
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to upload receipt.');
        }
      });
  }

  generateAuditReport() {
    const raw = this.reportForm.getRawValue();
    this.loading.set(true);
    this.error.set(null);

    this.expensesService
      .auditReport({
        start_date: raw.start_date || undefined,
        end_date: raw.end_date || undefined,
        group_by: raw.group_by
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (report) => {
          this.auditReport.set(report);
          this.message.set('Audit report generated.');
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to generate expense audit report.');
        }
      });
  }

  downloadExpenseEntries() {
    const raw = this.reportForm.getRawValue();
    this.loading.set(true);
    this.error.set(null);

    this.expensesService
      .downloadExpenseEntries({
        start_date: raw.start_date || undefined,
        end_date: raw.end_date || undefined
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (blob) => {
          const url = URL.createObjectURL(blob);
          const anchor = document.createElement('a');
          anchor.href = url;
          anchor.download = `expense_entries_${new Date().toISOString().slice(0, 10)}.csv`;
          document.body.appendChild(anchor);
          anchor.click();
          document.body.removeChild(anchor);
          URL.revokeObjectURL(url);
          this.message.set('Expense entries CSV downloaded.');
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to download expense entries.');
        }
      });
  }

  onCreateDragOver(event: DragEvent) {
    event.preventDefault();
    this.createDropActive.set(true);
  }

  onCreateDragLeave(event: DragEvent) {
    event.preventDefault();
    this.createDropActive.set(false);
  }

  onCreateDrop(event: DragEvent) {
    event.preventDefault();
    this.createDropActive.set(false);
    const file = event.dataTransfer?.files?.[0];
    this.setCreateReceiptFile(file ?? null);
  }

  onCreateFilePicked(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.setCreateReceiptFile(file);
    input.value = '';
  }

  clearCreateFile() {
    this.createReceiptFile.set(null);
  }

  onUploadDragOver(event: DragEvent) {
    event.preventDefault();
    this.uploadDropActive.set(true);
  }

  onUploadDragLeave(event: DragEvent) {
    event.preventDefault();
    this.uploadDropActive.set(false);
  }

  onUploadDrop(event: DragEvent) {
    event.preventDefault();
    this.uploadDropActive.set(false);
    const file = event.dataTransfer?.files?.[0];
    this.setUploadReceiptFile(file ?? null);
  }

  onUploadFilePicked(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.setUploadReceiptFile(file);
    input.value = '';
  }

  clearUploadFile() {
    this.uploadReceiptFile.set(null);
  }

  downloadReceipt(receiptId: number, name: string) {
    this.expensesService.getReceiptFile(receiptId).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = name || `expense-receipt-${receiptId}`;
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
      },
      error: () => this.error.set('Unable to download receipt file.')
    });
  }

  private setCreateReceiptFile(file: File | null) {
    if (!file) {
      return;
    }
    if (!this.isAllowedReceiptFile(file)) {
      this.error.set('Invalid file type. Allowed: PDF, image, Excel, CSV, DOC, DOCX.');
      return;
    }
    this.error.set(null);
    this.createReceiptFile.set(file);
  }

  private setUploadReceiptFile(file: File | null) {
    if (!file) {
      return;
    }
    if (!this.isAllowedReceiptFile(file)) {
      this.error.set('Invalid file type. Allowed: PDF, image, Excel, CSV, DOC, DOCX.');
      return;
    }
    this.error.set(null);
    this.uploadReceiptFile.set(file);
  }

  private isAllowedReceiptFile(file: File): boolean {
    const extension = (file.name.split('.').pop() || '').toLowerCase();
    return ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'xls', 'xlsx', 'csv', 'doc', 'docx'].includes(extension);
  }
}
