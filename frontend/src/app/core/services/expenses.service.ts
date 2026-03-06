import { Injectable, inject } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  ExpenseAuditReportResponse,
  ExpenseListResponse,
  ExpenseRecord,
  ExpenseReceipt
} from '../../models/expense';

@Injectable({
  providedIn: 'root'
})
export class ExpensesService {
  private readonly api = inject(ApiClient);

  list(params?: {
    start_date?: string;
    end_date?: string;
    category?: string;
    payment_method?: string;
    is_reversal?: boolean;
    per_page?: number;
    page?: number;
  }) {
    return this.api.get<ExpenseListResponse>('finance/expenses', params);
  }

  create(payload: FormData | {
    expense_date: string;
    category: string;
    description?: string;
    vendor_name?: string;
    amount: number;
    payment_method: string;
    payment_account_code?: string;
    expense_account_code?: string;
    reference_number?: string;
  }) {
    return this.api.post<{ message: string; data: ExpenseRecord }>('finance/expenses', payload);
  }

  reverse(id: number, payload: { reversal_reason: string; reversal_date?: string }) {
    return this.api.post<{ message: string; data: ExpenseRecord }>(`finance/expenses/${id}/reverse`, payload);
  }

  uploadReceipt(expenseId: number, file: File | null) {
    if (!file) {
      return this.api.post<{ message: string; data: ExpenseReceipt | null }>(`finance/expenses/${expenseId}/receipts`, {});
    }
    const formData = new FormData();
    formData.append('receipt_file', file);
    return this.api.post<{ message: string; data: ExpenseReceipt }>(`finance/expenses/${expenseId}/receipts`, formData);
  }

  getReceiptFile(receiptId: number) {
    return this.api.getBlob(`finance/expenses/receipts/${receiptId}/file`);
  }

  auditReport(params?: { start_date?: string; end_date?: string; group_by?: 'month' | 'year' | 'category' }) {
    return this.api.get<ExpenseAuditReportResponse>('finance/reports/expenses/audit', params);
  }

  downloadExpenseEntries(params?: { start_date?: string; end_date?: string }) {
    return this.api.getBlob('finance/reports/expenses/entries/download', params);
  }
}
