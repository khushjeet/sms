/* ============================================
   PAYMENT
============================================ */

export type PaymentMethod = 'cash' | 'cheque' | 'online' | 'card' | 'upi';


export interface ReceiptCreateResponse {
  id: number;
  receipt_number: string;
  student_id: number;
  amount: number;
  payment_method: string;
  date: string;
  notes?: string | null;
}


export interface PaymentRecord {
  id: number;
  enrollment_id: number;

  receipt_number: string;
  receipt_sequence?: number;

  amount: number;
  payment_date: string;

  payment_method: PaymentMethod | string;
  transaction_id?: string | null;
  remarks?: string | null;

  received_by: number;

  is_refunded?: boolean;
  refunded_at?: string | null;

  created_at?: string;
  updated_at?: string;
}

export interface PaymentCreateResponse {
  message: string;
  data: PaymentRecord;
}

export interface EnrollmentPaymentsResponse {
  enrollment_id: number;
  total_paid: number;
  payments: PaymentRecord[];
}


/* ============================================
   LEDGER
============================================ */

export type LedgerTransactionType = 'debit' | 'credit' | 'adjustment' | string;

export type LedgerReferenceType =
  | 'receipt'
  | 'fee_installment'
  | 'transport'
  | 'discount'
  | 'refund'
  | 'manual'
  | string;

export interface StudentFeeLedgerEntry {
  id: number;
  enrollment_id: number;
  student_id?: number;
  academic_year_id?: number;

  transaction_type: LedgerTransactionType;
  reference_type: LedgerReferenceType;
  reference_id: number | null;

  amount: number;

  posted_at?: string | null;
  created_at?: string | null;

  is_reversal: boolean;
}

export interface StudentLedgerStatementEntry {
  id: number;
  posted_at: string | null;
  enrollment_id: number;
  academic_year_id: number | null;
  reference_type: LedgerReferenceType;
  reference_id: number | null;
  reference_label?: string | null;
  transaction_type: LedgerTransactionType;
  debit: number;
  credit: number;
  running_balance: number;
  is_reversal: boolean;
  narration?: string | null;
}

export interface StudentLedgerStatement {
  student: {
    id: number;
    name: string;
    admission_number: string | null;
  };
  filters: {
    academic_year_id?: number | null;
    start_date?: string | null;
    end_date?: string | null;
    reference_type?: string | null;
  };
  totals: {
    debits: number;
    credits: number;
    balance: number;
  };
  entries: StudentLedgerStatementEntry[];
}

export type ClassLedgerExportFormat = 'excel' | 'pdf';

export interface ClassLedgerStudentSummary {
  ledger_serial_number: string;
  student_id: number | null;
  student_name: string;
  admission_number: string | null;
  father_name: string;
  mobile: string;
  phone_number: string;
  class: string;
  section: string | null;
  enrollment_id: number;
  debits: number;
  credits: number;
  balance: number;
}

export interface ClassLedgerSummary {
  students_count: number;
  total_debits: number;
  total_credits: number;
  total_balance: number;
}

export interface ClassLedgerResponse {
  class: {
    id: number;
    name: string;
  };
  filters: {
    academic_year_id?: number | null;
    start_date?: string | null;
    end_date?: string | null;
  };
  summary: ClassLedgerSummary;
  students: ClassLedgerStudentSummary[];
}

export interface ClassLedgerStatementEntry {
  id: number;
  posted_at: string | null;
  reference_type: string | null;
  reference_id: number | null;
  reference_label?: string | null;
  reference_note?: string | null;
  transaction_type: string;
  debit: number;
  credit: number;
  running_balance: number;
  is_reversal: boolean;
  narration?: string | null;
}

export interface ClassLedgerStudentStatement extends ClassLedgerStudentSummary {
  totals: {
    debits: number;
    credits: number;
    balance: number;
  };
  entries: ClassLedgerStatementEntry[];
}

export interface ClassLedgerStatementsResponse {
  class: {
    id: number;
    name: string;
  };
  filters: {
    academic_year_id?: number | null;
    start_date?: string | null;
    end_date?: string | null;
  };
  summary: ClassLedgerSummary;
  statements: ClassLedgerStudentStatement[];
}

export interface LedgerBalance {
  student_id: number;
  balance: number;
  debits: number;
  credits: number;
  adjustments: number;
}


/* ============================================
   ENROLLMENT SUMMARY (LEDGER BASED)
============================================ */

export interface EnrollmentLedgerSummary {
  enrollment_id: number;
  total_debits: number;
  total_credits: number;
  balance_due: number;
}


/* ============================================
   FEE HEADS & INSTALLMENTS
============================================ */

export interface FeeHead {
  id: number;
  name: string;
  code?: string | null;
  description?: string | null;
  status: 'active' | 'inactive' | string;
}

export interface FeeInstallment {
  id: number;
  fee_head_id: number;
  class_id: number;
  academic_year_id: number;

  name: string;
  due_date: string;
  amount: number;

  status: 'active' | 'inactive' | string;

  fee_head?: FeeHead;
  class_name?: string;
  academic_year_name?: string;
}

export interface AssignedFeeToStudent {
  id: number;
  student_id: number;
  academic_year_id: number;
  fee_installment_id: number;
  amount: number;
  assigned_by: number;
  created_at?: string;
}


/* ============================================
   FINANCIAL HOLD
============================================ */

export interface FinancialHold {
  id: number;
  student_id: number;
  reason: string;
  outstanding_amount?: number;
  is_active: boolean;
  created_at?: string;
}


/* ============================================
   RECEIPT VIEW (READ ONLY — derived from payment)
============================================ */

export interface ReceiptView {
  receipt_number: string;
  payment_date: string;
  amount: number;
  payment_method: PaymentMethod | string;
  transaction_id?: string | null;

  student: {
    id: number;
    name: string;
    admission_number?: string;
  };

  academic_year: string;
  class: string;
  section: string;
}


/* ============================================
   REPORTS
============================================ */

export interface DueReportItem {
  enrollment_id: number;
  student: string;
  academic_year: string;
  class: string;
  section: string;

  total_debits: number;
  total_credits: number;
  balance_due: number;
}

export interface DueReportResponse {
  count: number;
  data: DueReportItem[];
}

export interface CollectionSummary {
  total_amount: number;
  total_count: number;
  by_method: Record<string, number>;
}

export interface CollectionReportResponse {
  summary: CollectionSummary;
  payments: PaymentRecord[];
}

export interface RouteWiseReportItem {
  route_id: number | null;
  route_name: string | null;
  route_number: string | null;
  student_count: number;
  fee_amount: number;
  total_amount: number;
}

export interface RouteWiseReportResponse {
  count: number;
  data: RouteWiseReportItem[];
}


/* ============================================
   TRANSPORT
============================================ */

export interface TransportRouteItem {
  id: number;
  route_name?: string;
  route_number?: string | null;
  fee_amount?: number | null;
  vehicle_number?: string | null;
  driver_name?: string | null;
}

export interface TransportStopItem {
  id: number;
  route_id?: number;
  stop_name?: string;
  fee_amount?: number | null;
  distance_km?: number | null;
}

export interface TransportAssignmentItem {
  id: number;
  enrollment_id?: number | null;
  student_id?: number;
  academic_year_id?: number;
  route_id: number;
  stop_id: number;
  start_date: string;
  end_date?: string | null;
  status: 'active' | 'stopped';
  route?: TransportRouteItem;
  stop?: TransportStopItem;
}

export interface EnrollmentLedgerBalance {
  enrollment_id: number;
  balance: number;
  debits: number;
  credits: number;
}
