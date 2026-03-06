import { PaginatedResponse } from './pagination';

export type ExpensePaymentMethod =
  | 'cash'
  | 'cheque'
  | 'online'
  | 'card'
  | 'upi'
  | 'bank_transfer'
  | 'other';

export interface ExpenseRecord {
  id: number;
  expense_number: string;
  expense_date: string;
  category: string;
  description?: string | null;
  vendor_name?: string | null;
  amount: number;
  payment_method: ExpensePaymentMethod | string;
  payment_account_code: string;
  expense_account_code: string;
  reference_number?: string | null;
  created_by?: number | null;
  is_reversal: boolean;
  reversal_of_expense_id?: number | null;
  reversed_by?: number | null;
  reversed_at?: string | null;
  reversal_reason?: string | null;
  created_at?: string;
  updated_at?: string;
  receipts?: ExpenseReceipt[];
}

export interface ExpenseReceipt {
  id: number;
  expense_id: number;
  file_name: string;
  original_name: string;
  mime_type: string;
  extension: string;
  size_bytes: number;
  file_path: string;
  uploaded_by?: number | null;
  created_at?: string;
  updated_at?: string;
}

export interface ExpenseSummary {
  total_expense: number;
  reversed_amount: number;
  net_expense: number;
}

export interface ExpenseListResponse {
  summary: ExpenseSummary;
  data: PaginatedResponse<ExpenseRecord>;
}

export interface ExpenseAuditTrendRow {
  bucket: string;
  total_expense: number;
  total_reversed: number;
  net_expense: number;
}

export interface ExpenseAuditTrailRow {
  id: number;
  action: string;
  model_type: string;
  model_id: number | null;
  user_id: number | null;
  reason: string | null;
  created_at: string | null;
}

export interface ExpenseAuditReportResponse {
  filters: {
    start_date: string | null;
    end_date: string | null;
    group_by: 'month' | 'year' | 'category';
  };
  summary: {
    total_entries: number;
    original_entries: number;
    reversal_entries: number;
    total_expense: number;
    total_reversed: number;
    net_expense: number;
    receipt_attachment_count: number;
    receipt_coverage_percent: number;
    journal_linked_count: number;
    journal_unlinked_count: number;
  };
  trend: ExpenseAuditTrendRow[];
  audit_trail: ExpenseAuditTrailRow[];
  report_fingerprint: string;
  generated_at: string;
}
