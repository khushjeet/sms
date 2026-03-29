import { PaginatedResponse } from './pagination';

export interface LeaveType {
  id: number;
  name: string;
  max_days_per_year: number;
  is_paid: boolean;
}

export interface LeaveRequestRow {
  id: number;
  staff_id: number;
  leave_type_id: number;
  start_date: string;
  end_date: string;
  total_days: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  employee_code?: string | null;
}

export interface SalaryTemplateComponentInput {
  component_name: string;
  component_type: 'earning' | 'deduction';
  amount?: number | null;
  percentage?: number | null;
  is_taxable?: boolean;
  sort_order?: number;
}

export interface SalaryTemplate {
  id: number;
  name: string;
  description?: string | null;
  is_active: boolean;
  components: SalaryTemplateComponentInput[];
}

export interface PayrollBatch {
  id: number;
  year: number;
  month: number;
  status: 'generated' | 'finalized' | 'paid';
  is_locked: boolean;
  generated_at?: string | null;
  finalized_at?: string | null;
  paid_at?: string | null;
  items_count?: number;
}

export interface PayrollBatchItemAdjustment {
  id: number;
  payroll_batch_item_id: number;
  adjustment_type: 'recovery' | 'bonus' | 'correction';
  amount: string;
  remarks?: string | null;
  created_at: string;
}

export interface PayrollSnapshotAttendance {
  present: number;
  leave: number;
  half_day: number;
  absent: number;
  unmarked: number;
  days_in_month: number;
  payable_days: number;
  pay_ratio: number;
}

export interface PayrollSnapshotComponent {
  name: string;
  type: 'earning' | 'deduction' | string;
  amount: number;
  source_amount?: number | null;
  source_percentage?: number | null;
}

export interface PayrollSnapshotComputed {
  full_month_gross: number;
  full_month_deductions: number;
  pro_rated_gross: number;
  pro_rated_deductions: number;
  net: number;
}

export interface PayrollSnapshot {
  staff_id: number;
  employee_id?: string | null;
  salary_structure_id?: number | null;
  salary_template_id?: number | null;
  effective_from?: string | null;
  effective_to?: string | null;
  attendance?: PayrollSnapshotAttendance | null;
  components?: PayrollSnapshotComponent[] | null;
  computed?: PayrollSnapshotComputed | null;
}

export interface PayrollBatchItem {
  id: number;
  staff_id: number;
  days_in_month: number;
  payable_days: string;
  leave_days: string;
  absent_days: string;
  gross_pay: string;
  total_deductions: string;
  net_pay: string;
  snapshot: PayrollSnapshot;
  adjustments?: PayrollBatchItemAdjustment[];
}

export interface PayrollBatchDetail extends PayrollBatch {
  items: PayrollBatchItem[];
}

export interface PayrollPeriodOption {
  value: string;
  year: number;
  month: number;
  label: string;
  attendance_locked: boolean;
  payroll_status: 'generated' | 'finalized' | 'paid' | null;
  payroll_batch_id: number | null;
}

export interface SelfieAttendanceEventRow {
  id: number;
  punch_type: 'in' | 'out' | 'auto_out' | string;
  punched_at: string | null;
  latitude: number | null;
  longitude: number | null;
  location_accuracy_meters: number | null;
  selfie_url: string | null;
  source?: string;
}

export interface SelfieAttendanceDailyRow {
  id: number;
  staff_id: number;
  employee_id: string;
  staff_name: string;
  attendance_date: string;
  punch_in_at: string | null;
  punch_out_at: string | null;
  duration_minutes: number | null;
  review_status: 'pending' | 'approved' | 'rejected' | string;
  punch_in_selfie_url: string | null;
  punch_out_selfie_url: string | null;
  events: SelfieAttendanceEventRow[];
}

export type LeaveRequestPaginated = PaginatedResponse<LeaveRequestRow>;
export type PayrollBatchPaginated = PaginatedResponse<PayrollBatch>;
export type SelfieAttendanceDailyPaginated = PaginatedResponse<SelfieAttendanceDailyRow>;
