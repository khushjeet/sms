import { inject, Injectable } from '@angular/core';
import { ApiClient } from './api-client.service';
import {
  CollectionReportResponse,
  ClassLedgerResponse,
  ClassLedgerStatementsResponse,
  DueReportResponse,
  EnrollmentPaymentsResponse,
  FeeHead,
  FeeInstallment,
  FinancialHold,
  LedgerBalance,
  PaymentCreateResponse,
  PaymentRecord,
  ReceiptCreateResponse,
  RouteWiseReportResponse,
  StudentLedgerStatement,
  StudentFeeLedgerEntry,
  EnrollmentLedgerBalance,
  TransportAssignmentItem,
  TransportRouteItem,
  TransportStopItem
} from '../../models/finance';

@Injectable({
  providedIn: 'root'
})
export class FinanceService {
  private readonly api = inject(ApiClient);

  listInstallments(params?: { academic_year_id?: number; class_id?: number }) {
    return this.api.get<FeeInstallment[]>('finance/installments', params);
  }

  listFeeHeads(params?: { status?: string }) {
    return this.api.get<FeeHead[]>('finance/fee-heads', params);
  }

  createFeeHead(payload: Record<string, unknown>) {
    return this.api.post<FeeHead>('finance/fee-heads', payload);
  }

  updateFeeHead(id: number, payload: Record<string, unknown>) {
    return this.api.put<FeeHead>(`finance/fee-heads/${id}`, payload);
  }

  createInstallment(payload: Record<string, unknown>) {
    return this.api.post<FeeInstallment>('finance/installments', payload);
  }

  assignInstallment(studentId: number, payload: Record<string, unknown>) {
    return this.api.post<unknown>(`finance/students/${studentId}/installments`, payload);
  }

  assignInstallmentByEnrollment(enrollmentId: number, payload: Record<string, unknown>) {
    return this.api.post<unknown>(`finance/enrollments/${enrollmentId}/installments`, payload);
  }

  assignInstallmentToClass(payload: Record<string, unknown>) {
    return this.api.post<{ message: string; fee_installment_id: number; enrollments_found: number; assigned_count: number }>(
      'finance/installments/assign-to-class',
      payload
    );
  }

  listStudentInstallments(studentId: number, params?: { academic_year_id?: number }) {
    return this.api.get<unknown[]>(`finance/students/${studentId}/installments`, params);
  }

  createReceipt(payload: Record<string, unknown>) {
    return this.api.post<ReceiptCreateResponse>('finance/receipts', payload);
  }

  ledgerByStudent(studentId: number, params?: { academic_year_id?: number; start_date?: string; end_date?: string }) {
    return this.api.get<StudentLedgerStatement>(`finance/students/${studentId}/ledger`, params);
  }

  classLedger(classId: number, params?: { academic_year_id?: number; start_date?: string; end_date?: string }) {
    return this.api.get<ClassLedgerResponse>(`finance/classes/${classId}/ledger`, params);
  }

  classLedgerStatements(classId: number, params?: { academic_year_id?: number; start_date?: string; end_date?: string }) {
    return this.api.get<ClassLedgerStatementsResponse>(`finance/classes/${classId}/ledger/statements`, params);
  }

  downloadStudentLedger(studentId: number, params?: { academic_year_id?: number; start_date?: string; end_date?: string }) {
    return this.api.getBlob(`finance/students/${studentId}/ledger/download`, params);
  }

  downloadClassLedger(classId: number, params?: { academic_year_id?: number; start_date?: string; end_date?: string }) {
    return this.api.getBlob(`finance/classes/${classId}/ledger/download`, params);
  }

  ledgerByEnrollment(enrollmentId: number) {
    return this.api.get<StudentFeeLedgerEntry[]>(`finance/enrollments/${enrollmentId}/ledger`);
  }

  balanceByStudent(studentId: number, params?: { academic_year_id?: number }) {
    return this.api.get<LedgerBalance>(`finance/students/${studentId}/balance`, params);
  }

  balanceByEnrollment(enrollmentId: number) {
    return this.api.get<EnrollmentLedgerBalance>(`finance/enrollments/${enrollmentId}/balance`);
  }

  reverseLedgerEntry(entryId: number, payload: Record<string, unknown>) {
    return this.api.post<StudentFeeLedgerEntry>(`finance/ledger/${entryId}/reverse`, payload);
  }

  postSpecialFee(enrollmentId: number, payload: Record<string, unknown>) {
    return this.api.post<StudentFeeLedgerEntry>(`finance/enrollments/${enrollmentId}/special-fee`, payload);
  }

  listHolds(params?: { active?: boolean }) {
    return this.api.get<FinancialHold[]>('finance/holds', params);
  }

  createHold(payload: Record<string, unknown>) {
    return this.api.post<FinancialHold>('finance/holds', payload);
  }

  toggleHold(id: number, payload: Record<string, unknown>) {
    return this.api.put<FinancialHold>(`finance/holds/${id}`, payload);
  }

  listRoutes() {
    return this.api.get<TransportRouteItem[]>('transport/routes');
  }

  createRoute(payload: Record<string, unknown>) {
    return this.api.post<TransportRouteItem>('transport/routes', payload);
  }

  listStops(routeId?: number) {
    return this.api.get<TransportStopItem[]>('transport/stops', routeId ? { route_id: routeId } : undefined);
  }

  createStop(payload: Record<string, unknown>) {
    return this.api.post<TransportStopItem>('transport/stops', payload);
  }

  createAssignment(payload: Record<string, unknown>) {
    return this.api.post<TransportAssignmentItem>('transport/assignments', payload);
  }

  bulkCreateAssignments(payload: Record<string, unknown>) {
    return this.api.post<{
      message: string;
      created_count: number;
      charged_count: number;
      results: Array<{
        enrollment_id: number;
        status: 'created' | 'skipped';
        assignment_id?: number;
        message?: string;
      }>;
    }>('transport/assignments/bulk', payload);
  }

  stopAssignment(id: number, payload: Record<string, unknown>) {
    return this.api.post<unknown>(`transport/assignments/${id}/stop`, payload);
  }

  generateTransportCycle(payload: Record<string, unknown>) {
    return this.api.post<unknown>('transport/fee-cycles/generate', payload);
  }

  listTransportAssignments(params?: { enrollment_id?: number; status?: string; per_page?: number }) {
    return this.api.get<{ data: TransportAssignmentItem[]; total: number }>('transport/assignments', params);
  }

  createPayment(payload: Record<string, unknown>) {
    return this.api.post<PaymentCreateResponse>('finance/payments', payload);
  }

  listPaymentsByEnrollment(enrollmentId: number) {
    return this.api.get<EnrollmentPaymentsResponse>(`finance/payments/enrollment/${enrollmentId}`);
  }

  refundPayment(paymentId: number, payload: Record<string, unknown>) {
    return this.api.post<PaymentCreateResponse>(`finance/payments/${paymentId}/refund`, payload);
  }

  getPaymentReceipt(paymentId: number) {
    return this.api.get<PaymentRecord>(`finance/payments/${paymentId}/receipt`);
  }

  paymentReceiptHtml(paymentId: number) {
    return this.api.getText(`finance/payments/${paymentId}/receipt-html`);
  }

  dueReport(params?: { academic_year_id?: number; section_id?: number }) {
    return this.api.get<DueReportResponse>('finance/reports/fees/due', params);
  }

  collectionReport(params?: { start_date?: string; end_date?: string }) {
    return this.api.get<CollectionReportResponse>('finance/reports/fees/collection', params);
  }

  routeWiseReport(params?: { academic_year_id?: number }) {
    return this.api.get<RouteWiseReportResponse>('finance/reports/transport/route-wise', params);
  }

  transportChargeByEnrollment(enrollmentId: number) {
    return this.api.get<{
      enrollment_id: number;
      has_transport: boolean;
      fee_amount: number;
      route: TransportRouteItem | null;
      stop: TransportStopItem | null;
    }>(`finance/transport-charges/enrollment/${enrollmentId}`);
  }
}
