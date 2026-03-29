import { Component, computed, inject, signal } from '@angular/core';
import { NgClass, NgFor, NgIf } from '@angular/common';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ClassesService } from '../../core/services/classes.service';
import { EnrollmentsService } from '../../core/services/enrollments.service';
import { FinanceService } from '../../core/services/finance.service';
import { SchoolDetails, SchoolDetailsService } from '../../core/services/school-details.service';
import { AuditDownloadsService } from '../../core/services/audit-downloads.service';
import { SectionsService } from '../../core/services/sections.service';
import { StudentsService } from '../../core/services/students.service';
import { environment } from '../../../environments/environment';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';
import { Enrollment } from '../../models/enrollment';
import { Section } from '../../models/section';
import { Student, StudentFinancialSummary } from '../../models/student';
import { firstValueFrom } from 'rxjs';
import { finalize } from 'rxjs/operators';
import { jsPDF } from 'jspdf';
import {
  ClassLedgerExportFormat,
  ClassLedgerStatementsResponse,
  CollectionSummary,
  DueReportItem,
  FeeHead,
  FeeInstallment,
  FinancialHold,
  PaymentMethod,
  PaymentRecord,
  RouteWiseReportItem,
  StudentLedgerStatement,
  StudentLedgerStatementEntry,
  StudentFeeLedgerEntry,
  TransportAssignmentItem,
  TransportRouteItem,
  TransportStopItem
} from '../../models/finance';

type FinanceTab = 'overview' | 'ledger' | 'payments' | 'receipts' | 'holds' | 'reports' | 'transport';
interface EnrollmentSummaryView {
  id: number;
  academic_year_id: number | null;
  class_id: number | null;
  class_name: string | null;
  section: string | null;
  status: string | null;
}

@Component({
  selector: 'app-finance',
  standalone: true,
  imports: [
    NgIf,
    NgFor,
    NgClass,   // ✅ ADD THIS
    ReactiveFormsModule
  ],
  templateUrl: './finance.component.html',
  styleUrl: './finance.component.scss'
})

export class FinanceComponent {
  private readonly financeService = inject(FinanceService);
  private readonly classesService = inject(ClassesService);
  private readonly sectionsService = inject(SectionsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly studentsService = inject(StudentsService);
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly schoolDetailsService = inject(SchoolDetailsService);
  private readonly auditDownloadsService = inject(AuditDownloadsService);
  private readonly fb = inject(FormBuilder);
  private readonly apiBase = environment.apiBaseUrl.replace(/\/$/, '');
  private readonly apiOrigin = new URL(environment.apiBaseUrl).origin;
  private readonly apiPath = this.extractPath(environment.apiBaseUrl);
  private readonly defaultSchoolLogoUrl = `${this.apiOrigin}/storage/assets/ips.png`;

  readonly activeTab = signal<FinanceTab>('overview');
  readonly error = signal<string | null>(null);
  readonly busy = signal<Record<string, boolean>>({});

  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly academicYearOptions = computed(() =>
    this.academicYears().filter((year) => /^\d{4}-\d{4}$/.test((year.name || '').trim()))
  );
  readonly students = signal<Student[]>([]);
  readonly selectedStudent = signal<Student | null>(null);
  readonly selectedStudentSummary = signal<StudentFinancialSummary | null>(null);
  readonly schoolDetails = signal<SchoolDetails | null>(null);

  readonly feeHeads = signal<FeeHead[]>([]);
  readonly installments = signal<FeeInstallment[]>([]);
  readonly assignableInstallments = signal<FeeInstallment[]>([]);
  readonly bulkInstallments = signal<FeeInstallment[]>([]);
  readonly ledgerEntries = signal<StudentLedgerStatementEntry[]>([]);
  readonly ledgerBalance = signal<number | null>(null);
  readonly holds = signal<FinancialHold[]>([]);
  readonly routes = signal<TransportRouteItem[]>([]);
  readonly stops = signal<TransportStopItem[]>([]);
  readonly transportAssignments = signal<TransportAssignmentItem[]>([]);
  readonly bulkTransportEnrollments = signal<Enrollment[]>([]);
  readonly bulkTransportSelectedEnrollmentIds = signal<number[]>([]);
  readonly bulkTransportEnrollmentsLoading = signal(false);
  readonly bulkTransportLedgerPreviewEnrollment = signal<Enrollment | null>(null);
  readonly bulkTransportLedgerPreviewEntries = signal<StudentFeeLedgerEntry[]>([]);
  readonly bulkTransportLedgerPreviewLoading = signal(false);
  readonly payments = signal<PaymentRecord[]>([]);
  readonly selectedPaymentIds = signal<number[]>([]);
  readonly selectedPayments = computed(() =>
    this.payments().filter((payment) => this.selectedPaymentIds().includes(payment.id))
  );
  readonly selectedPaymentsTotal = computed(() =>
    this.selectedPayments().reduce((sum, payment) => sum + this.toNumber(payment.amount), 0)
  );
  readonly dueReportRows = signal<DueReportItem[]>([]);
  readonly collectionSummary = signal<CollectionSummary | null>(null);
  readonly collectionPayments = signal<PaymentRecord[]>([]);
  readonly routeWiseRows = signal<RouteWiseReportItem[]>([]);
  readonly selectedDueReportClassId = signal<number | null>(null);
  readonly selectedCollectionReportClassId = signal<number | null>(null);
  readonly selectedRouteWiseClassId = signal<number | null>(null);

  readonly studentSearching = signal(false);
  readonly reportsLoading = signal(false);

  readonly selectedEnrollmentId = computed(() => this.getStudentEnrollment(this.selectedStudent())?.id ?? null);
  readonly selectedEnrollment = computed<EnrollmentSummaryView | null>(() => {
    const enrollment = this.getStudentEnrollment(this.selectedStudent());
    if (!enrollment) {
      return null;
    }
    const classId = this.getEnrollmentClassId(enrollment);
    const className = this.getEnrollmentClassName(enrollment);

    return {
      id: enrollment.id,
      academic_year_id: enrollment.academic_year_id ?? null,
      class_id: classId,
      section: this.getEnrollmentSectionName(enrollment),
      class_name: className,
      status: enrollment.status ?? null
    };
  });

  readonly dueReportSections = computed(() => {
    const classId = this.selectedDueReportClassId();
    if (!classId) {
      return this.sections();
    }

    return this.sections().filter((section) => Number(section.class_id) === classId);
  });

  readonly collectionReportSections = computed(() => {
    const classId = this.selectedCollectionReportClassId();
    if (!classId) {
      return this.sections();
    }

    return this.sections().filter((section) => Number(section.class_id) === classId);
  });

  readonly routeWiseSections = computed(() => {
    const classId = this.selectedRouteWiseClassId();
    if (!classId) {
      return this.sections();
    }

    return this.sections().filter((section) => Number(section.class_id) === classId);
  });

  readonly studentSearchForm = this.fb.nonNullable.group({
    search: ['', Validators.required]
  });

  readonly studentSelectForm = this.fb.nonNullable.group({
    student_id: ['', Validators.required]
  });

  readonly feeHeadForm = this.fb.nonNullable.group({
    name: ['', Validators.required],
    code: [''],
    description: [''],
    status: ['active', Validators.required]
  });

  readonly installmentForm = this.fb.nonNullable.group({
    fee_head_id: ['', Validators.required],
    class_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    name: ['', Validators.required],
    due_date: ['', Validators.required],
    amount: ['', Validators.required]
  });

  readonly assignInstallmentForm = this.fb.nonNullable.group({
    enrollment_id: ['', Validators.required],
    fee_installment_id: ['', Validators.required],
    amount: ['', Validators.required]
  });

  readonly assignInstallmentFilterForm = this.fb.nonNullable.group({
    class_id: [''],
    academic_year_id: ['']
  });

  readonly bulkAssignInstallmentForm = this.fb.nonNullable.group({
    class_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    fee_installment_id: ['', Validators.required],
    amount: ['']
  });

  readonly ledgerQueryForm = this.fb.nonNullable.group({
    student_id: ['', Validators.required],
    academic_year_id: [''],
    start_date: [''],
    end_date: ['']
  });

  readonly classLedgerDownloadForm = this.fb.nonNullable.group({
    class_id: ['', Validators.required],
    academic_year_id: [''],
    start_date: [''],
    end_date: [''],
    format: ['excel' as ClassLedgerExportFormat, Validators.required]
  });

  readonly specialFeeForm = this.fb.nonNullable.group({
    enrollment_id: ['', Validators.required],
    amount: ['', Validators.required],
    posted_at: [''],
    narration: ['', Validators.required]
  });

  readonly reversalForm = this.fb.nonNullable.group({
    entry_id: ['', Validators.required],
    reason: ['', Validators.required]
  });

  readonly paymentForm = this.fb.nonNullable.group({
    enrollment_id: ['', Validators.required],
    amount: ['', Validators.required],
    payment_date: ['', Validators.required],
    payment_method: ['cash' as PaymentMethod, Validators.required],
    transaction_id: [''],
    remarks: ['']
  });

  readonly paymentLookupForm = this.fb.nonNullable.group({
    enrollment_id: ['', Validators.required]
  });

  readonly refundForm = this.fb.nonNullable.group({
    payment_id: ['', Validators.required],
    refund_reason: ['', Validators.required],
    refund_date: ['']
  });

  readonly receiptForm = this.fb.nonNullable.group({
    student_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    amount: ['', Validators.required],
    payment_method: ['cash', Validators.required],
    transaction_id: [''],
    paid_at: ['', Validators.required]
  });

  readonly holdForm = this.fb.nonNullable.group({
    student_id: ['', Validators.required],
    reason: ['', Validators.required],
    outstanding_amount: ['']
  });

  readonly dueReportForm = this.fb.nonNullable.group({
    academic_year_id: [''],
    class_id: [''],
    start_date: [''],
    end_date: [''],
    section_id: ['']
  });

  readonly collectionReportForm = this.fb.nonNullable.group({
    academic_year_id: [''],
    class_id: [''],
    section_id: [''],
    start_date: [''],
    end_date: ['']
  });

  readonly routeWiseReportForm = this.fb.nonNullable.group({
    academic_year_id: [''],
    class_id: [''],
    section_id: [''],
    start_date: [''],
    end_date: ['']
  });

  readonly transportRouteForm = this.fb.nonNullable.group({
    route_name: ['', Validators.required],
    vehicle_number: ['', Validators.required],
    driver_name: ['']
  });

  readonly transportStopForm = this.fb.nonNullable.group({
    route_id: ['', Validators.required],
    stop_name: ['', Validators.required],
    fee_amount: ['', Validators.required],
    distance_km: ['']
  });

  readonly transportAssignmentForm = this.fb.nonNullable.group({
    enrollment_id: ['', Validators.required],
    route_id: ['', Validators.required],
    stop_id: ['', Validators.required],
    start_date: ['', Validators.required]
  });

  readonly bulkTransportAssignForm = this.fb.nonNullable.group({
    route_id: ['', Validators.required],
    stop_id: ['', Validators.required],
    start_date: ['', Validators.required],
    search: ['']
  });

  readonly transportStopAssignmentForm = this.fb.nonNullable.group({
    assignment_id: ['', Validators.required],
    end_date: ['', Validators.required]
  });

  readonly transportAssignmentsQueryForm = this.fb.nonNullable.group({
    enrollment_id: ['']
  });

  readonly transportCycleForm = this.fb.nonNullable.group({
    assignment_id: ['', Validators.required],
    month: ['', Validators.required],
    year: ['', Validators.required],
    amount: ['']
  });

  readonly selectedTransportRouteId = signal<number | null>(null);
  readonly selectedBulkTransportRouteId = signal<number | null>(null);

  readonly filteredStopsForAssignment = computed(() => {
    const routeId = this.selectedTransportRouteId();
    if (!routeId) {
      return this.stops();
    }
    return this.stops().filter((stop) => Number(stop.route_id) === routeId);
  });

  readonly filteredStopsForBulkAssignment = computed(() => {
    const routeId = this.selectedBulkTransportRouteId();
    if (!routeId) {
      return this.stops();
    }
    return this.stops().filter((stop) => Number(stop.route_id) === routeId);
  });

  readonly activeTransportAssignments = computed(() =>
    this.transportAssignments().filter((item) => item.status === 'active')
  );
  // #region Template events: Toast notifications (click: clearMessage)
  // ======================================================
// Toast / Notification State (Required by Template)
// ======================================================

readonly message = signal<{ text: string; type: 'success' | 'error' } | null>(null);

/** Template event handler: (click) close toast -> clears message(). */
clearMessage(): void {
  this.message.set(null);
}

/** Helper: shows a success toast and auto-clears after 4 seconds. */
showSuccess(text: string): void {
  this.message.set({ text, type: 'success' });
  setTimeout(() => this.clearMessage(), 4000);
}

/** Helper: shows an error toast and auto-clears after 4 seconds. */
showError(text: string): void {
  this.message.set({ text, type: 'error' });
  setTimeout(() => this.clearMessage(), 4000);
}
  // #endregion Template events: Toast notifications

  setBusy(key: string, value: boolean): void {
    this.busy.update((current) => ({ ...current, [key]: value }));
  }

  isBusy(key: string): boolean {
    return !!this.busy()[key];
  }

  // #region Lifecycle + reactive form events (ngOnInit)
  ngOnInit() {
    // Initial page loads (runs once on component init)
    this.loadSchoolDetails();
    this.loadReferenceData();
    this.refreshFeeHeads();
    this.refreshInstallments();
    this.refreshHolds();
    this.refreshRoutes();
    this.refreshStops();

    // Reactive form events START (valueChanges subscriptions)
    this.bulkAssignInstallmentForm.controls.class_id.valueChanges.subscribe(() => this.loadInstallmentsForBulkAssignment());
    this.bulkAssignInstallmentForm.controls.academic_year_id.valueChanges.subscribe(() =>
      this.loadInstallmentsForBulkAssignment()
    );
    this.assignInstallmentFilterForm.controls.class_id.valueChanges.subscribe(() => this.loadInstallmentsForAssignment());
    this.assignInstallmentFilterForm.controls.academic_year_id.valueChanges.subscribe(() =>
      this.loadInstallmentsForAssignment()
    );
    this.dueReportForm.controls.class_id.valueChanges.subscribe((value) => {
      const classId = value ? Number(value) : null;
      const normalizedClassId = classId && Number.isFinite(classId) ? classId : null;
      this.selectedDueReportClassId.set(normalizedClassId);

      const currentSectionId = this.dueReportForm.controls.section_id.value;
      if (!currentSectionId) {
        return;
      }

      const sectionStillAllowed = this.sections().some(
        (section) =>
          section.id === Number(currentSectionId) &&
          (!normalizedClassId || Number(section.class_id) === normalizedClassId)
      );

      if (!sectionStillAllowed) {
        this.dueReportForm.patchValue({ section_id: '' });
      }
    });
    this.collectionReportForm.controls.class_id.valueChanges.subscribe((value) => {
      const classId = value ? Number(value) : null;
      const normalizedClassId = classId && Number.isFinite(classId) ? classId : null;
      this.selectedCollectionReportClassId.set(normalizedClassId);

      const currentSectionId = this.collectionReportForm.controls.section_id.value;
      if (!currentSectionId) {
        return;
      }

      const sectionStillAllowed = this.sections().some(
        (section) =>
          section.id === Number(currentSectionId) &&
          (!normalizedClassId || Number(section.class_id) === normalizedClassId)
      );

      if (!sectionStillAllowed) {
        this.collectionReportForm.patchValue({ section_id: '' });
      }
    });
    this.routeWiseReportForm.controls.class_id.valueChanges.subscribe((value) => {
      const classId = value ? Number(value) : null;
      const normalizedClassId = classId && Number.isFinite(classId) ? classId : null;
      this.selectedRouteWiseClassId.set(normalizedClassId);

      const currentSectionId = this.routeWiseReportForm.controls.section_id.value;
      if (!currentSectionId) {
        return;
      }

      const sectionStillAllowed = this.sections().some(
        (section) =>
          section.id === Number(currentSectionId) &&
          (!normalizedClassId || Number(section.class_id) === normalizedClassId)
      );

      if (!sectionStillAllowed) {
        this.routeWiseReportForm.patchValue({ section_id: '' });
      }
    });
    this.assignInstallmentForm.controls.fee_installment_id.valueChanges.subscribe((value) => {
      const installmentId = value ? Number(value) : null;
      if (!installmentId) {
        return;
      }
      const selectedInstallment = this.assignableInstallments().find((item) => item.id === installmentId);
      if (!selectedInstallment) {
        return;
      }
      this.assignInstallmentForm.patchValue({
        amount: String(this.toNumber(selectedInstallment.amount))
      });
    });

    this.transportAssignmentForm.controls.route_id.valueChanges.subscribe(() => {
      const rawRouteId = this.transportAssignmentForm.controls.route_id.value;
      this.selectedTransportRouteId.set(rawRouteId ? Number(rawRouteId) : null);
      this.transportAssignmentForm.patchValue({ stop_id: '' });
    });

    this.transportAssignmentForm.controls.enrollment_id.valueChanges.subscribe((value) => {
      const enrollmentId = value ? Number(value) : null;
      if (enrollmentId && Number.isFinite(enrollmentId)) {
        this.loadTransportAssignments(enrollmentId);
      }
    });
    this.bulkTransportAssignForm.controls.route_id.valueChanges.subscribe(() => {
      const rawRouteId = this.bulkTransportAssignForm.controls.route_id.value;
      this.selectedBulkTransportRouteId.set(rawRouteId ? Number(rawRouteId) : null);
      this.bulkTransportAssignForm.patchValue({ stop_id: '' });
    });
    // Reactive form events END
  }
  // #endregion Lifecycle + reactive form events (ngOnInit)

  // #region Template events: Navigation (click: setTab)
  /** Template event handler: (click) tab button -> switches active finance tab. */
  setTab(tab: FinanceTab) {
    this.activeTab.set(tab);
  }
  // #endregion Template events: Navigation (click: setTab)

  loadReferenceData() {
    this.classesService.list({ per_page: 100 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });

    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => {
        this.academicYears.set(response.data);

        const yearOptions = response.data.filter((year) => /^\d{4}-\d{4}$/.test((year.name || '').trim()));
        const preferred =
          yearOptions
            .filter((year) => year.is_current)
            .sort((a, b) => String(b.start_date || '').localeCompare(String(a.start_date || '')))[0] ??
          yearOptions.sort((a, b) => String(b.start_date || '').localeCompare(String(a.start_date || '')))[0] ??
          null;

        if (preferred) {
          const bulkYear = this.bulkAssignInstallmentForm.controls.academic_year_id.value;
          if (!bulkYear) {
            this.bulkAssignInstallmentForm.patchValue({ academic_year_id: String(preferred.id) });
          }

          const installmentYear = this.installmentForm.controls.academic_year_id.value;
          if (!installmentYear) {
            this.installmentForm.patchValue({ academic_year_id: String(preferred.id) });
          }
        }
      }
    });

    this.sectionsService.list({ per_page: 200 }).subscribe({
      next: (response) => this.sections.set(response.data)
    });
  }

  // #region Template events: Student context (ngSubmit: searchStudents, selectStudent)
  /** Template event handler: (ngSubmit) studentSearchForm -> searches students list. */
  searchStudents() {
    if (this.studentSearchForm.invalid) {
      this.studentSearchForm.markAllAsTouched();
      return;
    }

    this.studentSearching.set(true);
    const query = this.studentSearchForm.getRawValue().search.trim();

    this.studentsService.list({ search: query, per_page: 20 }).subscribe({
      next: (response) => {
        this.students.set(response.data);
        this.studentSearching.set(false);
      },
      error: (err) => {
        this.studentSearching.set(false);
        this.showError(err?.error?.message || 'Unable to search students.');
      }
    });
  }

  /** Template event handler: (ngSubmit) studentSelectForm -> binds selected student and refreshes dependent views. */
  selectStudent() {
    if (this.studentSelectForm.invalid) {
      this.studentSelectForm.markAllAsTouched();
      return;
    }

    const studentId = Number(this.studentSelectForm.getRawValue().student_id);
    const student = this.students().find((item) => item.id === studentId) ?? null;
    this.selectedStudent.set(student);
    this.selectedStudentSummary.set(null);

    if (!studentId) {
      return;
    }

    this.studentsService.financialSummary(studentId).subscribe({
      next: (summary) => this.selectedStudentSummary.set(summary),
      error: (err) => this.showError(err?.error?.message || 'Unable to load student finance summary.')
    });

    this.ledgerQueryForm.patchValue({ student_id: String(studentId) });
    this.holdForm.patchValue({ student_id: String(studentId) });
    this.receiptForm.patchValue({ student_id: String(studentId) });

    const currentEnrollment = this.getStudentEnrollment(student);
    const currentYearId = currentEnrollment?.academic_year_id;
    if (currentYearId) {
      this.receiptForm.patchValue({ academic_year_id: String(currentYearId) });
    }

    const enrollmentId = currentEnrollment?.id;
    if (enrollmentId) {
      const enrollmentClassId = this.getEnrollmentClassId(currentEnrollment);
      if (enrollmentClassId) {
        this.bulkAssignInstallmentForm.patchValue({ class_id: String(enrollmentClassId) });
      }
      const yearId = currentEnrollment?.academic_year_id;
      if (yearId) {
        this.bulkAssignInstallmentForm.patchValue({ academic_year_id: String(yearId) });
      }
      this.assignInstallmentFilterForm.patchValue({
        class_id: enrollmentClassId ? String(enrollmentClassId) : '',
        academic_year_id: yearId ? String(yearId) : ''
      });

      this.assignInstallmentForm.patchValue({
        enrollment_id: String(enrollmentId),
        fee_installment_id: '',
        amount: ''
      });
      this.specialFeeForm.patchValue({ enrollment_id: String(enrollmentId) });
      this.paymentForm.patchValue({ enrollment_id: String(enrollmentId) });
      this.paymentLookupForm.patchValue({ enrollment_id: String(enrollmentId) });
      this.transportAssignmentForm.patchValue({ enrollment_id: String(enrollmentId) });
      this.loadPayments();
      this.loadTransportAssignments(enrollmentId);
    } else {
      this.assignInstallmentForm.patchValue({
        enrollment_id: '',
        fee_installment_id: '',
        amount: ''
      });
      this.paymentLookupForm.patchValue({ enrollment_id: '' });
      this.paymentForm.patchValue({ enrollment_id: '' });
      this.transportAssignmentForm.patchValue({ enrollment_id: '' });
      this.specialFeeForm.patchValue({ enrollment_id: '' });
      this.payments.set([]);
      this.transportAssignments.set([]);
      this.assignInstallmentFilterForm.patchValue({
        class_id: '',
        academic_year_id: ''
      });
      this.assignableInstallments.set([]);
    }

    this.loadInstallmentsForAssignment();
  }
  // #endregion Template events: Student context (ngSubmit: searchStudents, selectStudent)

  private getStudentEnrollment(student: Student | null): any | null {
    if (!student) {
      return null;
    }

    const typedStudent = student as any;
    return typedStudent.currentEnrollment ?? typedStudent.current_enrollment ?? null;
  }

  private getEnrollmentClassId(enrollment: any): number | null {
    return enrollment?.section?.class?.id
      ?? enrollment?.section?.class_id
      ?? enrollment?.classModel?.id
      ?? enrollment?.class_model?.id
      ?? enrollment?.class_id
      ?? null;
  }

  private getEnrollmentClassName(enrollment: any): string | null {
    return enrollment?.section?.class?.name
      ?? enrollment?.section?.class_name
      ?? enrollment?.classModel?.name
      ?? enrollment?.class_model?.name
      ?? null;
  }

  private getEnrollmentSectionName(enrollment: any): string | null {
    return enrollment?.section?.name
      ?? enrollment?.section_name
      ?? null;
  }

  // #region Template events: Fees & installments (ngSubmit/click)
  /** Template event handler: (click) "Refresh" installments -> reloads installments list. */
  refreshInstallments() {
    this.financeService.listInstallments().subscribe({
      next: (data) => this.installments.set(data)
    });
  }

  loadInstallmentsForAssignment() {
    const raw = this.assignInstallmentFilterForm.getRawValue();
    const classId = raw.class_id ? Number(raw.class_id) : null;
    const yearId = raw.academic_year_id ? Number(raw.academic_year_id) : null;

    if (!classId || !yearId) {
      this.assignableInstallments.set([]);
      this.assignInstallmentForm.patchValue({ fee_installment_id: '' });
      return;
    }

    this.financeService
      .listInstallments({
        academic_year_id: yearId,
        class_id: classId
      })
      .subscribe({
        next: (data) => {
          this.assignableInstallments.set(data);
          this.assignInstallmentForm.patchValue({ fee_installment_id: '' });
        },
        error: () => {
          this.assignableInstallments.set([]);
          this.assignInstallmentForm.patchValue({ fee_installment_id: '' });
        }
      });
  }

  loadInstallmentsForBulkAssignment() {
    const raw = this.bulkAssignInstallmentForm.getRawValue();
    const classId = raw.class_id ? Number(raw.class_id) : null;
    const yearId = raw.academic_year_id ? Number(raw.academic_year_id) : null;

    if (!classId || !yearId) {
      this.bulkInstallments.set([]);
      return;
    }

    this.financeService
      .listInstallments({
        academic_year_id: yearId,
        class_id: classId
      })
      .subscribe({
        next: (data) => {
          this.bulkInstallments.set(data);
          this.bulkAssignInstallmentForm.patchValue({ fee_installment_id: '' });
        },
        error: () => this.bulkInstallments.set([])
      });
  }

  refreshFeeHeads() {
    this.financeService.listFeeHeads().subscribe({
      next: (data) => this.feeHeads.set(data)
    });
  }

  /** Template event handler: (ngSubmit) feeHeadForm -> creates a fee head. */
  createFeeHead() {
    if (this.feeHeadForm.invalid) {
      this.feeHeadForm.markAllAsTouched();
      return;
    }
    const raw = this.feeHeadForm.getRawValue();
    const payload = {
      name: raw.name,
      code: raw.code || undefined,
      description: raw.description || undefined,
      status: raw.status
    };
    this.setBusy('createFeeHead', true);
    this.financeService
      .createFeeHead(payload)
      .pipe(finalize(() => this.setBusy('createFeeHead', false)))
      .subscribe({
      next: () => {
        this.feeHeadForm.reset({ name: '', code: '', description: '', status: 'active' });
        this.refreshFeeHeads();
        this.showSuccess('Fee head created.');
      },
      error: (err) => this.showError(err?.error?.message || 'Unable to create fee head.')
    });
  }

  /** Template event handler: (ngSubmit) installmentForm -> creates a fee installment. */
  createInstallment() {
    if (this.installmentForm.invalid) {
      this.installmentForm.markAllAsTouched();
      return;
    }
    const raw = this.installmentForm.getRawValue();
    const payload = {
      fee_head_id: Number(raw.fee_head_id),
      class_id: Number(raw.class_id),
      academic_year_id: Number(raw.academic_year_id),
      name: raw.name,
      due_date: raw.due_date,
      amount: Number(raw.amount)
    };
    this.setBusy('createInstallment', true);
    this.financeService
      .createInstallment(payload)
      .pipe(finalize(() => this.setBusy('createInstallment', false)))
      .subscribe({
      next: () => {
        this.installmentForm.reset();
        this.refreshInstallments();
        this.showSuccess('Installment created.');
      },
      error: (err) => this.showError(err?.error?.message || 'Unable to create installment.')
    });
  }

  /** Template event handler: (ngSubmit) assignInstallmentForm -> assigns an installment to one enrollment. */
  assignInstallment() {
    if (this.assignInstallmentForm.invalid) {
      this.assignInstallmentForm.markAllAsTouched();
      this.showError('Please select enrollment, installment, and amount before assigning.');
      return;
    }
    const raw = this.assignInstallmentForm.getRawValue();
    const enrollmentId = Number(raw.enrollment_id);
    const payload = {
      fee_installment_id: Number(raw.fee_installment_id),
      amount: Number(raw.amount)
    };
    this.setBusy('assignInstallment', true);
    this.financeService
      .assignInstallmentByEnrollment(enrollmentId, payload)
      .pipe(finalize(() => this.setBusy('assignInstallment', false)))
      .subscribe({
        next: () => {
          this.assignInstallmentForm.reset({
            enrollment_id: String(enrollmentId),
            fee_installment_id: '',
            amount: ''
          });
          this.showSuccess('Installment assigned.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to assign installment.')
      });
  }

  /** Template event handler: (ngSubmit) bulkAssignInstallmentForm -> bulk assigns an installment to a class/year. */
  bulkAssignInstallmentToClass() {
    if (this.bulkAssignInstallmentForm.invalid) {
      this.bulkAssignInstallmentForm.markAllAsTouched();
      return;
    }

    const raw = this.bulkAssignInstallmentForm.getRawValue();
    const payload: Record<string, unknown> = {
      class_id: Number(raw.class_id),
      academic_year_id: Number(raw.academic_year_id),
      fee_installment_id: Number(raw.fee_installment_id)
    };

    if (raw.amount !== '') {
      payload['amount'] = Number(raw.amount);
    }

    this.setBusy('bulkAssignInstallment', true);
    this.financeService
      .assignInstallmentToClass(payload)
      .pipe(finalize(() => this.setBusy('bulkAssignInstallment', false)))
      .subscribe({
        next: (response) => {
          if (response.enrollments_found === 0) {
            this.showError(response.message || 'No active enrollments found.');
            return;
          }
          if (response.assigned_count === 0) {
            this.showSuccess(response.message || 'No new charges posted.');
            return;
          }
          this.showSuccess(`${response.assigned_count}/${response.enrollments_found} enrollment(s) charged.`);
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to bulk-assign installment.')
      });
  }

  /** Template event handler: (ngSubmit) specialFeeForm -> posts a one-off debit to the ledger. */
  postSpecialFee() {
    if (this.specialFeeForm.invalid) {
      this.specialFeeForm.markAllAsTouched();
      return;
    }

    const raw = this.specialFeeForm.getRawValue();
    const enrollmentId = Number(raw.enrollment_id);
    const payload = {
      amount: Number(raw.amount),
      posted_at: raw.posted_at || undefined,
      narration: raw.narration
    };

    this.setBusy('postSpecialFee', true);
    this.financeService
      .postSpecialFee(enrollmentId, payload)
      .pipe(finalize(() => this.setBusy('postSpecialFee', false)))
      .subscribe({
        next: () => {
          this.specialFeeForm.reset({ enrollment_id: raw.enrollment_id, amount: '', posted_at: '', narration: '' });
          this.showSuccess('Special fee posted.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to post special fee.')
      });
  }

  // #endregion Template events: Fees & installments (ngSubmit/click)

  // #region Template events: Ledger (click/ngSubmit)
  /** Template event handler: (click) "Load ledger" -> fetches ledger entries for student/year. */
  loadLedger() {
    if (this.ledgerQueryForm.invalid) {
      this.ledgerQueryForm.markAllAsTouched();
      return;
    }
    const raw = this.ledgerQueryForm.getRawValue();
    const studentId = Number(raw.student_id);
    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };
    this.financeService.ledgerByStudent(studentId, params).subscribe({
      next: (data) => {
        this.ledgerEntries.set(data.entries ?? []);
        this.ledgerBalance.set(data.totals?.balance ?? null);
      },
      error: (err) => this.showError(err?.error?.message || 'Unable to load ledger.')
    });
  }

  /** Template event handler: (click) "Load balance" -> fetches current balance for student/year. */
  loadBalance() {
    const raw = this.ledgerQueryForm.getRawValue();
    const studentId = Number(raw.student_id);
    if (!studentId) {
      this.ledgerQueryForm.markAllAsTouched();
      return;
    }
    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined
    };
    this.financeService.balanceByStudent(studentId, params).subscribe({
      next: (data) => this.ledgerBalance.set(data.balance),
      error: (err) => this.showError(err?.error?.message || 'Unable to load balance.')
    });
  }

  downloadStudentLedger() {
    if (this.ledgerQueryForm.invalid) {
      this.ledgerQueryForm.markAllAsTouched();
      return;
    }

    const raw = this.ledgerQueryForm.getRawValue();
    const studentId = Number(raw.student_id);
    if (!studentId) {
      this.showError('Student ID is required.');
      return;
    }

    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };

    const key = `downloadStudentLedger:${studentId}`;
    this.setBusy(key, true);
    this.financeService
      .downloadStudentLedger(studentId, params)
      .pipe(finalize(() => this.setBusy(key, false)))
      .subscribe({
        next: (blob) => {
          const fileName = `student_ledger_${studentId}.csv`;
          this.downloadBlob(blob, fileName);
          this.logFinanceDownload('student_ledger', 'Student Ledger', fileName, this.ledgerEntries().length, params, 'csv', blob);
          this.showSuccess('Student ledger download started.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to download student ledger.')
      });
  }

  downloadStudentLedgerPdf() {
    if (this.ledgerQueryForm.invalid) {
      this.ledgerQueryForm.markAllAsTouched();
      return;
    }

    const raw = this.ledgerQueryForm.getRawValue();
    const studentId = Number(raw.student_id);
    if (!studentId) {
      this.showError('Student ID is required.');
      return;
    }

    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };

    const key = `downloadStudentLedgerPdf:${studentId}`;
    this.setBusy(key, true);
    this.financeService
      .ledgerByStudent(studentId, params)
      .pipe(finalize(() => this.setBusy(key, false)))
      .subscribe({
        next: (payload) => {
          this.buildStudentLedgerPdf(payload, studentId);
          this.logFinanceDownload('student_ledger', 'Student Ledger', `student_ledger_${studentId}.pdf`, payload.entries?.length || 0, params, 'pdf');
          this.showSuccess('Student ledger PDF download started.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to download student ledger PDF.')
      });
  }

  downloadClassLedger() {
    if (this.classLedgerDownloadForm.invalid) {
      this.classLedgerDownloadForm.markAllAsTouched();
      return;
    }

    const raw = this.classLedgerDownloadForm.getRawValue();
    const classId = Number(raw.class_id);
    if (!classId) {
      this.showError('Class is required for bulk ledger download.');
      return;
    }

    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };
    const format = (raw.format || 'excel') as ClassLedgerExportFormat;

    const key = `downloadClassLedger:${classId}:${format}`;
    this.setBusy(key, true);
    if (format === 'pdf') {
      this.financeService
        .classLedgerStatements(classId, params)
        .pipe(finalize(() => this.setBusy(key, false)))
        .subscribe({
          next: (payload) => {
            this.downloadClassLedgerPdf(payload, classId);
            this.logFinanceDownload('class_ledger', 'Class Ledger', `class_${classId}_ledger.pdf`, payload.statements?.length || 0, params, 'pdf');
            this.showSuccess('Class PDF statements download started.');
          },
          error: (err) => this.showError(err?.error?.message || 'Unable to download class ledger PDF.')
        });
      return;
    }

    this.financeService
      .downloadClassLedger(classId, params)
      .pipe(finalize(() => this.setBusy(key, false)))
      .subscribe({
        next: (blob) => {
          const fileName = `class_${classId}_ledger.csv`;
          this.downloadBlob(blob, fileName);
          this.logFinanceDownload('class_ledger', 'Class Ledger', fileName, 0, params, 'csv', blob);
          this.showSuccess('Class Excel download started.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to download class ledger.')
      });
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.click();
    window.URL.revokeObjectURL(url);
  }

  private escapeCsv(value: unknown): string {
    const text = String(value ?? '');
    return `"${text.replace(/"/g, '""')}"`;
  }

  private logFinanceDownload(
    reportKey: string,
    reportLabel: string,
    fileName: string,
    rowCount: number,
    filters: Record<string, unknown>,
    format: 'csv' | 'pdf' = 'csv',
    blob?: Blob
  ): void {
    this.buildChecksum(blob).then((checksum) => {
      this.auditDownloadsService.logDownload({
        module: 'fee_reports',
        report_key: reportKey,
        report_label: reportLabel,
        format,
        file_name: fileName,
        file_checksum: checksum,
        row_count: rowCount,
        filters,
        context: {
          active_tab: this.activeTab(),
        },
      }).subscribe({ error: () => void 0 });
    });
  }

  private async buildChecksum(blob?: Blob): Promise<string | null> {
    if (!blob || !window.crypto?.subtle) {
      return null;
    }

    const buffer = await blob.arrayBuffer();
    const digest = await window.crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(digest)).map((value) => value.toString(16).padStart(2, '0')).join('');
  }

  private downloadClassLedgerPdf(payload: ClassLedgerStatementsResponse, classId: number): void {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const marginX = 40;
    const headerBottom = 120;
    const rowHeight = 16;
    const footerReserve = 40;
    const bodyMaxY = pageHeight - footerReserve;

    payload.statements.forEach((statement, index) => {
      if (index > 0) {
        doc.addPage();
      }

      let y = 40;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(14);
      doc.text(`Class Ledger Statement - ${payload.class.name}`, marginX, y);
      y += 20;

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(10);
      doc.text(`Student: ${statement.student_name}`, marginX, y);
      y += 14;
      doc.text(`Admission: ${statement.admission_number || '-'}`, marginX, y);
      y += 14;
      doc.text(`Father: ${statement.father_name}`, marginX, y);
      y += 14;
      doc.text(`Mobile: ${statement.mobile || '-'}`, marginX, y);
      y += 14;
      doc.text(`Class/Section: ${statement.class} / ${statement.section || '-'}`, marginX, y);
      y += 14;
      doc.text(`Enrollment ID: ${statement.enrollment_id}`, marginX, y);
      y += 20;

      const drawHeader = () => {
        doc.setFont('helvetica', 'bold');
        doc.text('Date', marginX, y);
        doc.text('Type', marginX + 90, y);
        doc.text('Ref', marginX + 145, y);
        doc.text('Debit', marginX + 200, y);
        doc.text('Credit', marginX + 255, y);
        doc.text('Running', marginX + 315, y);
        doc.text('Narration', marginX + 390, y);
        y += 8;
        doc.line(marginX, y, pageWidth - marginX, y);
        y += 12;
        doc.setFont('helvetica', 'normal');
      };

      drawHeader();

      statement.entries.forEach((entry) => {
        if (y > bodyMaxY - rowHeight) {
          doc.addPage();
          y = 40;
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(12);
          doc.text(`Student: ${statement.student_name} (continued)`, marginX, y);
          y += 22;
          drawHeader();
        }

        const narration = this.formatNarrationForPdf(entry.narration, 35);
        const referenceLabel = entry.reference_label?.trim() || '';
        const referenceNote = entry.reference_note?.trim() || '';
        const defaultReference = `${entry.reference_type || '-'}#${entry.reference_id ?? '-'}`;
        const referenceText =
          entry.transaction_type === 'credit'
            ? (referenceNote || referenceLabel || defaultReference)
            : (referenceLabel || defaultReference);
        doc.setFontSize(9);
        doc.text((entry.posted_at || '-').slice(0, 10), marginX, y);
        doc.text(entry.transaction_type || '-', marginX + 90, y);
        doc.text(referenceText, marginX + 145, y);
        doc.text(String(entry.debit ?? 0), marginX + 200, y);
        doc.text(String(entry.credit ?? 0), marginX + 255, y);
        doc.text(String(entry.running_balance ?? 0), marginX + 315, y);
        doc.text(narration, marginX + 390, y);
        y += rowHeight;
      });

      if (y > bodyMaxY - 30) {
        doc.addPage();
        y = 40;
      }

      doc.line(marginX, y, pageWidth - marginX, y);
      y += 14;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(10);
      doc.text(
        `Totals: Debit ${statement.totals.debits} | Credit ${statement.totals.credits} | Balance ${statement.totals.balance}`,
        marginX,
        y
      );
    });

    const safeClassName = (payload.class.name || `class_${classId}`).replace(/[^A-Za-z0-9_-]/g, '_');
    doc.save(`class_${safeClassName}_statements.pdf`);
  }

  private buildStudentLedgerPdf(payload: StudentLedgerStatement, studentId: number): void {
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const marginX = 40;
    const rowHeight = 16;
    const footerReserve = 40;
    const bodyMaxY = pageHeight - footerReserve;

    let y = 40;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text('Student Ledger Statement', marginX, y);
    y += 20;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text(`Student: ${payload.student.name || '-'}`, marginX, y);
    y += 14;
    doc.text(`Admission: ${payload.student.admission_number || '-'}`, marginX, y);
    y += 14;
    doc.text(`Student ID: ${payload.student.id}`, marginX, y);
    y += 14;
    doc.text(`From: ${payload.filters.start_date || '-'}  To: ${payload.filters.end_date || '-'}`, marginX, y);
    y += 20;

    const drawHeader = () => {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(10);
      doc.text('Date', marginX, y);
      doc.text('Type', marginX + 85, y);
      doc.text('Reference', marginX + 145, y);
      doc.text('Debit', marginX + 290, y);
      doc.text('Credit', marginX + 350, y);
      doc.text('Narration', marginX + 410, y);
      y += 8;
      doc.line(marginX, y, pageWidth - marginX, y);
      y += 12;
      doc.setFont('helvetica', 'normal');
    };

    drawHeader();

    payload.entries.forEach((entry) => {
      if (y > bodyMaxY - rowHeight) {
        doc.addPage();
        y = 40;
        drawHeader();
      }

      const defaultReference = `${entry.reference_type || '-'}#${entry.reference_id ?? '-'}`;
      const referenceText = (entry.reference_label?.trim() || defaultReference).slice(0, 28);
      const narration = this.formatNarrationForPdf(entry.narration, 45);

      doc.setFontSize(9);
      doc.text((entry.posted_at || '-').slice(0, 10), marginX, y);
      doc.text(String(entry.transaction_type || '-'), marginX + 85, y);
      doc.text(referenceText, marginX + 145, y);
      doc.text(String(entry.debit ?? 0), marginX + 290, y);
      doc.text(String(entry.credit ?? 0), marginX + 350, y);
      doc.text(narration, marginX + 410, y);
      y += rowHeight;
    });

    if (y > bodyMaxY - 30) {
      doc.addPage();
      y = 40;
    }

    doc.line(marginX, y, pageWidth - marginX, y);
    y += 14;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(10);
    doc.text(
      `Totals: Debit ${payload.totals.debits} | Credit ${payload.totals.credits} | Balance ${payload.totals.balance}`,
      marginX,
      y
    );

    const safeAdmission = (payload.student.admission_number || `student_${studentId}`).replace(/[^A-Za-z0-9_-]/g, '_');
    doc.save(`student_ledger_${safeAdmission}.pdf`);
  }

  /** Template event handler: (ngSubmit) reversalForm -> posts a reversal for a ledger entry. */
  reverseEntry() {
    if (this.reversalForm.invalid) {
      this.reversalForm.markAllAsTouched();
      return;
    }
    const raw = this.reversalForm.getRawValue();
    const entryId = Number(raw.entry_id);
    const payload = { reason: raw.reason };
    this.setBusy('reverseEntry', true);
    this.financeService
      .reverseLedgerEntry(entryId, payload)
      .pipe(finalize(() => this.setBusy('reverseEntry', false)))
      .subscribe({
        next: () => {
          this.reversalForm.reset();
          this.showSuccess('Reversal posted.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to reverse entry.')
      });
  }

  // #endregion Template events: Ledger (click/ngSubmit)

  // #region Template events: Payments (ngSubmit/click)
  /** Template event handler: (ngSubmit) paymentForm -> records a payment (credit) against an enrollment. */
  createPayment() {
    if (this.paymentForm.invalid) {
      this.paymentForm.markAllAsTouched();
      return;
    }

    const raw = this.paymentForm.getRawValue();
    const payload = {
      enrollment_id: Number(raw.enrollment_id),
      amount: Number(raw.amount),
      payment_date: raw.payment_date,
      payment_method: raw.payment_method,
      transaction_id: raw.transaction_id || undefined,
      remarks: raw.remarks || undefined
    };

    this.setBusy('createPayment', true);
    this.financeService
      .createPayment(payload)
      .pipe(finalize(() => this.setBusy('createPayment', false)))
      .subscribe({
        next: () => {
          this.paymentForm.reset({
            enrollment_id: String(payload.enrollment_id),
            amount: '',
            payment_date: '',
            payment_method: 'cash',
            transaction_id: '',
            remarks: ''
          });
          this.paymentLookupForm.patchValue({ enrollment_id: String(payload.enrollment_id) });
          this.loadPayments();
          this.showSuccess('Payment recorded.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to record payment.')
      });
  }

  /** Template event handler: (click) "Load payments" -> loads payment history for enrollment. */
  loadPayments() {
    if (this.paymentLookupForm.invalid) {
      this.paymentLookupForm.markAllAsTouched();
      return;
    }

    const enrollmentId = Number(this.paymentLookupForm.getRawValue().enrollment_id);
    this.financeService.listPaymentsByEnrollment(enrollmentId).subscribe({
      next: (response) => {
        this.payments.set(response.payments);
        this.selectedPaymentIds.set([]);
      },
      error: (err) => this.error.set(err?.error?.message || 'Unable to load payment history.')
    });
  }

  isPaymentSelectable(payment: PaymentRecord): boolean {
    return !payment.is_refunded && this.toNumber(payment.amount) > 0;
  }

  togglePaymentSelection(paymentId: number): void {
    this.selectedPaymentIds.update((current) =>
      current.includes(paymentId) ? current.filter((id) => id !== paymentId) : [...current, paymentId]
    );
  }

  allSelectablePaymentsSelected(): boolean {
    const selectableIds = this.payments()
      .filter((payment) => this.isPaymentSelectable(payment))
      .map((payment) => payment.id);

    if (!selectableIds.length) {
      return false;
    }

    const selected = this.selectedPaymentIds();
    return selectableIds.every((id) => selected.includes(id));
  }

  toggleAllPaymentSelection(): void {
    if (this.allSelectablePaymentsSelected()) {
      this.selectedPaymentIds.set([]);
      return;
    }

    const selectableIds = this.payments()
      .filter((payment) => this.isPaymentSelectable(payment))
      .map((payment) => payment.id);
    this.selectedPaymentIds.set(selectableIds);
  }

  async downloadSelectedPaymentsPdf(): Promise<void> {
    const selected = this.selectedPayments().filter((payment) => this.isPaymentSelectable(payment));
    if (!selected.length) {
      this.showError('Select at least one non-refunded payment to generate PDF.');
      return;
    }

    let student = this.selectedStudent();
    const studentId = student?.id ?? null;
    if (studentId) {
      try {
        student = await firstValueFrom(this.studentsService.getById(studentId));
      } catch {
        // Keep existing selected student if full fetch fails.
      }
    }

    const enrollmentIdRaw = this.paymentLookupForm.getRawValue().enrollment_id || '-';
    const enrollmentIdNumber = Number(enrollmentIdRaw);
    let enrollmentDetail: Enrollment | null = null;
    if (Number.isFinite(enrollmentIdNumber) && enrollmentIdNumber > 0) {
      try {
        enrollmentDetail = await firstValueFrom(this.enrollmentsService.getById(enrollmentIdNumber));
      } catch {
        // Keep PDF generation running with available data.
      }
    }

    const effectiveStudent = student ?? enrollmentDetail?.student ?? null;

    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const marginX = 40;
    const tableTopStart = 332;
    const rowHeight = 22;
    const footerReserve = 48;
    const maxY = pageHeight - footerReserve;
    let schoolDetails: SchoolDetails | null = null;
    try {
      schoolDetails = await firstValueFrom(this.schoolDetailsService.get());
      this.schoolDetails.set(schoolDetails);
    } catch {
      schoolDetails = this.schoolDetails();
    }

    const schoolName = schoolDetails?.name?.trim() || 'School';
    const schoolNameLines = this.buildSchoolNameLines(schoolName);
    const schoolAddress = schoolDetails?.address?.trim() || '';
    const schoolPhone = schoolDetails?.phone?.trim() || '';
    const schoolWebsite = schoolDetails?.website?.trim() || '';
    const schoolRegNo = schoolDetails?.registration_number?.trim() || '';
    const schoolUdise = schoolDetails?.udise_code?.trim() || '';
    const schoolLogoUrl = schoolDetails?.logo_data_url?.trim() || this.resolveSchoolLogoUrl(schoolDetails?.logo_url || null);
    const enrollmentId = enrollmentIdRaw;
    const anyStudent = effectiveStudent as any;
    const studentName = this.resolveStudentName(effectiveStudent);
    const rollFromStudent = this.resolveStudentRollNumber(effectiveStudent);
    const studentRollNumber =
      (rollFromStudent && rollFromStudent !== '-' ? rollFromStudent : '') ||
      (enrollmentDetail?.roll_number ? String(enrollmentDetail.roll_number) : '') ||
      '-';
    const studentIdText = effectiveStudent?.id ? String(effectiveStudent.id) : enrollmentDetail?.student_id ? String(enrollmentDetail.student_id) : '-';
    const className =
      effectiveStudent?.currentEnrollment?.section?.class?.name ||
      enrollmentDetail?.section?.class?.name ||
      enrollmentDetail?.classModel?.name ||
      '-';
    const sectionName = effectiveStudent?.currentEnrollment?.section?.name || enrollmentDetail?.section?.name || 'N/A';
    const admissionNumber = effectiveStudent?.admission_number || anyStudent?.admissionNumber || '-';
    const fatherName =
      effectiveStudent?.profile?.father_name ||
      anyStudent?.father_name ||
      anyStudent?.fatherName ||
      '-';
    const generatedDate = new Date();
    const qrSeed = `${schoolName}-${studentIdText}-${enrollmentId}-${generatedDate.getTime()}`;
    const generatedDateLabel = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }).format(
      generatedDate
    );
    const logoDataUrl = await this.loadImageAsDataUrl(schoolLogoUrl);

    let y = 0;

    const drawWatermark = () => {
      const cx = pageWidth / 2;
      const cy = pageHeight / 2;
      const topCy = 110;
      const radius = 110;
      doc.setDrawColor(230, 230, 230);
      doc.setTextColor(232, 232, 232);
      doc.setLineWidth(1);
      doc.circle(cx, topCy, radius);
      doc.circle(cx, topCy, radius - 16);
      doc.circle(cx, cy, radius);
      doc.circle(cx, cy, radius - 16);
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(18);
      doc.text(schoolName, cx, topCy - 4, { align: 'center' });
      doc.setFontSize(10);
      doc.text('FINANCE PAYMENT HISTORY', cx, topCy + 12, { align: 'center' });
      doc.setFontSize(20);
      doc.text(schoolName, cx, cy - 6, { align: 'center' });
      doc.setFontSize(11);
      doc.text('FINANCE PAYMENT HISTORY', cx, cy + 12, { align: 'center' });
      doc.setTextColor(15, 23, 42);
      doc.setDrawColor(180, 180, 180);
    };

    const drawHeader = () => {
      // Keep receipt/PDF branding fully data-driven from `school_settings`.
      drawWatermark();

      y = 36;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.text(`Reg No. ${schoolRegNo}`, marginX, y);
      doc.text(`UDISE : ${schoolUdise}`, pageWidth - marginX, y, { align: 'right' });
      y += 28;

      const badgeSize = 78;
      const logoX = marginX;
      const logoY = y + 2;
      doc.setDrawColor(198, 198, 198);
      doc.rect(logoX, logoY, badgeSize, badgeSize);
      if (logoDataUrl) {
        doc.addImage(logoDataUrl, this.detectImageFormat(logoDataUrl), logoX + 2, logoY + 2, badgeSize - 4, badgeSize - 4);
      }

      const qrSize = badgeSize;
      const qrX = pageWidth - marginX - qrSize;
      const qrY = y - 6;
      this.drawPseudoQrCode(doc, qrX, qrY, qrSize, qrSeed);

      doc.setFont('times', 'bold');
      doc.setFontSize(24);
      schoolNameLines.forEach((line, index) => {
        doc.text(line, pageWidth / 2, y + 6 + (index * 28), { align: 'center', maxWidth: pageWidth - 220 });
      });
      y += 46;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(11);
      doc.text(schoolAddress, pageWidth / 2, y, { align: 'center' });
      y += 20;

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9.5);
      doc.text(`Mob. ${schoolPhone}  |`, pageWidth / 2 - 14, y, { align: 'right' });
      doc.setTextColor(0, 102, 204);
      doc.text(schoolWebsite, pageWidth / 2 - 10, y);
      doc.setTextColor(15, 23, 42);
      y += 20;
      doc.line(marginX, y, pageWidth - marginX, y);
      y += 30;

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(18);
      doc.setTextColor('#000000');
      doc.text('Student Details:', marginX, y);
      doc.setTextColor('#000000');
      y += 30;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(12);
      doc.text('Name:', marginX, y);
      doc.setFont('helvetica', 'normal');
      doc.text(studentName, marginX + 48, y, { maxWidth: 150 });
      doc.setFont('helvetica', 'bold');
      doc.text('Roll Number:', marginX + 200, y);
      doc.setFont('helvetica', 'normal');
      doc.text(studentRollNumber, marginX + 288, y, { maxWidth: 80 });
      doc.setFont('helvetica', 'bold');
      doc.text('Class:', marginX + 360, y);
      doc.setFont('helvetica', 'normal');
      doc.text(className, marginX + 400, y, { maxWidth: 90 });
      doc.setFont('helvetica', 'bold');
      doc.text('Section:', marginX + 495, y);
      doc.setFont('helvetica', 'normal');
      doc.text(sectionName, marginX + 555, y, { maxWidth: 40 });
      y += 28;

      doc.setFont('helvetica', 'bold');
      doc.text('Date:', marginX, y);
      doc.setFont('helvetica', 'normal');
      doc.text(generatedDateLabel, marginX + 40, y, { maxWidth: 120 });
      doc.setFont('helvetica', 'bold');
      doc.text('Admission Number:', marginX + 200, y);
      doc.setFont('helvetica', 'normal');
      doc.text(admissionNumber, marginX + 325, y, { maxWidth: 120 });
      doc.setFont('helvetica', 'bold');
      doc.text('Student ID:', marginX + 465, y);
      doc.setFont('helvetica', 'normal');
      doc.text(studentIdText, marginX + 534, y, { maxWidth: 60 });
      y += 28;

      doc.setFont('helvetica', 'bold');
      doc.text('Father Name:', marginX, y);
      doc.setFont('helvetica', 'normal');
      doc.text(fatherName, marginX + 86, y, { maxWidth: 255 });
      doc.setFont('helvetica', 'bold');
      doc.text('Enrollment ID:', marginX + 350, y);
      doc.setFont('helvetica', 'normal');
      doc.text(String(enrollmentId), marginX + 437, y, { maxWidth: 80 });
      y += 16;

      doc.line(marginX, y, pageWidth - marginX, y);

      y = tableTopStart;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(10);
      doc.text('Fee Head', marginX, y);
      doc.text('Installment', marginX + 180, y);
      doc.text('Amount', pageWidth - marginX, y, { align: 'right' });
      y += 8;
      doc.line(marginX, y, pageWidth - marginX, y);
      y += 14;
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(10);
    };

    drawHeader();

    selected.forEach((payment) => {
      if (y > maxY - rowHeight) {
        doc.addPage();
        drawHeader();
      }

      const feeHead = this.resolvePaymentFeeHead(payment);
      const installment = this.resolvePaymentInstallment(payment);
      const amountText = this.formatAmount(this.toNumber(payment.amount));

      doc.text(feeHead, marginX, y, { maxWidth: 165 });
      doc.text(installment, marginX + 180, y, { maxWidth: 250 });
      doc.text(amountText, pageWidth - marginX, y, { align: 'right' });
      y += rowHeight;
    });

    if (y > maxY - 30) {
      doc.addPage();
      drawHeader();
    }

    doc.line(marginX, y, pageWidth - marginX, y);
    y += 18;
    doc.setFont('helvetica', 'bold');
    const selectedTotal = selected.reduce((sum, payment) => sum + this.toNumber(payment.amount), 0);
    doc.text(`Total: ${this.formatAmount(selectedTotal)}`, pageWidth - marginX, y, { align: 'right' });

    const remarksRows = selected
      .map((payment) => {
        const remark = (payment.remarks || '').trim();
        if (!remark) {
          return null;
        }
        const label = payment.receipt_number ? String(payment.receipt_number) : `Payment #${payment.id}`;
        return `${label}: ${remark}`;
      })
      .filter((row): row is string => !!row);

    y += 26;
    if (y > maxY - 40) {
      doc.addPage();
      drawHeader();
      y = tableTopStart;
    }

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.text('Remarks:', marginX, y);
    y += 16;

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    const finalRemarksRows = remarksRows.length ? remarksRows : ['-'];
    finalRemarksRows.forEach((line) => {
      const wrapped = doc.splitTextToSize(line, pageWidth - marginX * 2);
      wrapped.forEach((part: string) => {
        if (y > maxY - 14) {
          doc.addPage();
          drawHeader();
          y = tableTopStart;
          doc.setFont('helvetica', 'bold');
          doc.setFontSize(11);
          doc.text('Remarks (contd.):', marginX, y);
          y += 16;
          doc.setFont('helvetica', 'normal');
          doc.setFontSize(10);
        }
        doc.text(part, marginX, y);
        y += 14;
      });
    });

    y += 28;
    const signatureTop = y;
    const signatureLineY = signatureTop + 24;
    const signatureLabelY = signatureLineY + 16;
    const signatureLeftCenter = marginX + 120;
    const signatureRightCenter = pageWidth - marginX - 120;
    const signatureLineWidth = 150;

    if (signatureLabelY > pageHeight - 40) {
      doc.addPage();
      drawHeader();
      y = tableTopStart + 12;
    }

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.line(
      signatureLeftCenter - signatureLineWidth / 2,
      y + 24,
      signatureLeftCenter + signatureLineWidth / 2,
      y + 24
    );
    doc.line(
      signatureRightCenter - signatureLineWidth / 2,
      y + 24,
      signatureRightCenter + signatureLineWidth / 2,
      y + 24
    );
    doc.text('Principal Signature', signatureLeftCenter, y + 40, { align: 'center' });
    doc.text('Accountant Signature', signatureRightCenter, y + 40, { align: 'center' });

    const fileStamp = new Date().toISOString().slice(0, 10);
    doc.save(`payments_${enrollmentId}_${fileStamp}.pdf`);
  }

  private resolveStudentName(student: Student | null): string {
    if (!student) {
      return '-';
    }
    const fullName = student.user?.full_name?.trim();
    if (fullName) {
      return fullName;
    }
    const name = `${student.user?.first_name ?? ''} ${student.user?.last_name ?? ''}`.trim();
    return name || '-';
  }

  private resolveStudentRollNumber(student: Student | null): string {
    const anyStudent = student as any;
    const roll = anyStudent?.profile?.roll_number ?? anyStudent?.roll_number;
    return roll ? String(roll) : '-';
  }

  private async loadImageAsDataUrl(url: string | null | undefined): Promise<string | null> {
    if (!url) {
      return null;
    }
    if (url.startsWith('data:')) {
      return url;
    }

    const candidates = this.buildImageCandidates(url);
    const authHeaders = this.getAuthHeaders();
    for (const candidate of candidates) {
      try {
        const shouldUseAuth = candidate.startsWith(this.apiBase) || candidate.startsWith(this.apiPath);
        const response = await fetch(candidate, {
          mode: 'cors',
          credentials: shouldUseAuth ? 'include' : 'omit',
          headers: shouldUseAuth ? authHeaders : undefined
        });
        if (!response.ok) {
          continue;
        }
        const blob = await response.blob();
        if (blob.type && !blob.type.toLowerCase().startsWith('image/')) {
          continue;
        }
        const dataUrl = await new Promise<string | null>((resolve) => {
          const reader = new FileReader();
          reader.onload = () => resolve((reader.result as string) || null);
          reader.onerror = () => resolve(null);
          reader.readAsDataURL(blob);
        });
        if (dataUrl) {
          return dataUrl;
        }
      } catch {
        // Try next candidate.
      }
    }
    return null;
  }

  private detectImageFormat(dataUrl: string): 'PNG' | 'JPEG' | 'WEBP' {
    if (dataUrl.startsWith('data:image/png')) {
      return 'PNG';
    }
    if (dataUrl.startsWith('data:image/webp')) {
      return 'WEBP';
    }
    return 'JPEG';
  }

  private drawPseudoQrCode(doc: jsPDF, x: number, y: number, size: number, seedText: string): void {
    const modules = 29;
    const cell = size / modules;
    let seed = 0;
    for (let i = 0; i < seedText.length; i += 1) {
      seed = (seed * 31 + seedText.charCodeAt(i)) >>> 0;
    }

    const rand = () => {
      seed ^= seed << 13;
      seed ^= seed >>> 17;
      seed ^= seed << 5;
      return ((seed >>> 0) % 1000) / 1000;
    };

    const isFinderZone = (mx: number, my: number, fx: number, fy: number): boolean =>
      mx >= fx && mx < fx + 7 && my >= fy && my < fy + 7;

    const isFinderPattern = (mx: number, my: number, fx: number, fy: number): boolean => {
      const dx = mx - fx;
      const dy = my - fy;
      const outer = dx === 0 || dx === 6 || dy === 0 || dy === 6;
      const inner = dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4;
      return outer || inner;
    };

    doc.setFillColor(255, 255, 255);
    doc.rect(x, y, size, size, 'F');
    doc.setDrawColor(190, 190, 190);
    doc.rect(x, y, size, size);
    doc.setFillColor(0, 0, 0);

    for (let my = 0; my < modules; my += 1) {
      for (let mx = 0; mx < modules; mx += 1) {
        const inTL = isFinderZone(mx, my, 0, 0);
        const inTR = isFinderZone(mx, my, modules - 7, 0);
        const inBL = isFinderZone(mx, my, 0, modules - 7);

        let fill = false;
        if (inTL) {
          fill = isFinderPattern(mx, my, 0, 0);
        } else if (inTR) {
          fill = isFinderPattern(mx, my, modules - 7, 0);
        } else if (inBL) {
          fill = isFinderPattern(mx, my, 0, modules - 7);
        } else {
          fill = rand() > 0.52;
        }

        if (fill) {
          doc.rect(x + mx * cell, y + my * cell, cell, cell, 'F');
        }
      }
    }
  }

  private buildImageCandidates(url: string): string[] {
    const values = new Set<string>();
    values.add(url);

    const proxied = this.toProxiedPath(url);
    if (proxied) {
      values.add(proxied);
    }

    if (url.includes('/public/storage/')) {
      values.add(url.replace('/public/storage/', '/storage/'));
    }
    if (url.includes('/storage/')) {
      values.add(url.replace('/storage/', '/public/storage/'));
    }

    if (url.startsWith('/')) {
      values.add(`${this.apiOrigin}${url}`);
    }

    return Array.from(values);
  }

  private toProxiedPath(url: string): string | null {
    if (!url.startsWith('http://') && !url.startsWith('https://')) {
      return url.startsWith('/') ? url : `/${url}`;
    }

    try {
      const parsed = new URL(url);
      if (parsed.origin !== this.apiOrigin) {
        return null;
      }
      return `${parsed.pathname}${parsed.search}`;
    } catch {
      return null;
    }
  }

  private extractPath(url: string): string {
    try {
      const parsed = new URL(url);
      const path = parsed.pathname.replace(/\/$/, '');
      return path || '/';
    } catch {
      return '/api/v1';
    }
  }

  private getAuthHeaders(): Record<string, string> {
    try {
      const raw = localStorage.getItem('sms_auth_session');
      if (!raw) {
        return {};
      }
      const parsed = JSON.parse(raw) as { token?: string };
      if (!parsed?.token) {
        return {};
      }
      return { Authorization: `Bearer ${parsed.token}` };
    } catch {
      return {};
    }
  }

  /** Template event handler: (click) "Download" receipt -> opens receipt HTML in a new window/tab. */
  downloadPaymentReceipt(paymentId: number) {
    if (!paymentId) {
      return;
    }

    const key = `paymentReceipt:${paymentId}`;
    this.setBusy(key, true);
    this.financeService
      .paymentReceiptHtml(paymentId)
      .pipe(finalize(() => this.setBusy(key, false)))
      .subscribe({
        next: (html) => {
          const popup = window.open('', '_blank');
          if (!popup) {
            this.showError('Popup blocked. Please allow popups to download the receipt.');
            return;
          }
          const printableHtml = this.preparePaymentReceiptHtmlForPrint(html);
          popup.document.open();
          popup.document.write(printableHtml);
          popup.document.close();
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to generate receipt.')
      });
  }

  private preparePaymentReceiptHtmlForPrint(html: string): string {
    let receiptHtml = html;

    const logoUrl = this.resolveSchoolDisplay().logoUrl.replace(/"/g, '&quot;');
    const logoMarkup = `<img src="${logoUrl}" alt="Logo" style="width:70px;height:70px;object-fit:contain" />`;
    const hasLogoImage = /<div class="logo-box">[\s\S]*?<img\b/i.test(receiptHtml);
    if (!hasLogoImage) {
      receiptHtml = receiptHtml.replace(/<div class="logo-placeholder">LOGO<\/div>/i, logoMarkup);
    }

    const autoPrintScript =
      '<script>(function(){var openPrint=function(){setTimeout(function(){window.focus();window.print();},150);};if(document.readyState==="complete"){openPrint();}else{window.addEventListener("load",openPrint,{once:true});}})();</script>';

    if (/<\/body>/i.test(receiptHtml)) {
      return receiptHtml.replace(/<\/body>/i, `${autoPrintScript}</body>`);
    }
    return `${receiptHtml}${autoPrintScript}`;
  }

  private resolvePaymentFeeHead(payment: PaymentRecord): string {
    const remarks = (payment.remarks || '').trim();
    const matched = remarks.match(/fee\s*head\s*[:\-]\s*([^,;|]+)/i);
    return matched?.[1]?.trim() || 'Fee Paid';
  }

  private resolvePaymentInstallment(payment: PaymentRecord): string {
    const remarks = (payment.remarks || '').trim();
    const matched = remarks.match(/installment\s*[:\-]\s*([^,;|]+)/i);
    if (matched?.[1]?.trim()) {
      return matched[1].trim();
    }

    const paymentDate = (payment.payment_date || '').slice(0, 10) || '-';
    return `${payment.receipt_number || 'Receipt'} | ${paymentDate}`;
  }

  // Loads school branding/details from the backend `school_settings` table.
  private loadSchoolDetails(): void {
    this.schoolDetailsService.get().subscribe({
      next: (details) => this.schoolDetails.set(details),
      error: () => this.schoolDetails.set(null)
    });
  }

  // All school header fields are sourced from `school_settings` via `school/details`.
  private resolveSchoolDisplay(school: SchoolDetails | null = this.schoolDetails()): {
    name: string;
    address: string;
    phone: string;
    website: string;
    registrationNumber: string;
    udiseCode: string;
    logoUrl: string;
  } {
    return {
      name: school?.name?.trim() || 'School',
      address: school?.address?.trim() || '',
      phone: school?.phone?.trim() || '',
      website: school?.website?.trim() || '',
      registrationNumber: school?.registration_number?.trim() || '',
      udiseCode: school?.udise_code?.trim() || '',
      logoUrl: this.resolveSchoolLogoUrl(school?.logo_url || null)
    };
  }

  private resolveSchoolLogoUrl(path?: string | null): string {
    return this.resolveAssetUrl(path) || this.defaultSchoolLogoUrl;
  }

  private buildSchoolNameLines(name: string): string[] {
    const normalized = name.trim() || 'School';
    const words = normalized.split(/\s+/).filter(Boolean);

    if (words.length <= 2) {
      return [normalized.toUpperCase()];
    }

    const midpoint = Math.ceil(words.length / 2);
    return [
      words.slice(0, midpoint).join(' ').toUpperCase(),
      words.slice(midpoint).join(' ').toUpperCase()
    ];
  }

  private resolveAssetUrl(path?: string | null): string | null {
    const normalized = (path || '').trim();
    if (!normalized) {
      return null;
    }

    if (normalized.startsWith('http://') || normalized.startsWith('https://') || normalized.startsWith('data:')) {
      return normalized;
    }

    const relative = normalized.replace(/^public\//, '').replace(/^\/+/, '');
    const publicRelative = relative.startsWith('storage/') ? relative : `storage/${relative}`;
    return `${this.apiOrigin}/${publicRelative}`;
  }

  private formatNarrationForPdf(narration: string | null | undefined, maxLength: number): string {
    const text = (narration || '').trim();
    if (!text) {
      return '-';
    }

    const normalized = text.toLowerCase();
    const installmentPrefix = 'installment assigned:';
    if (normalized.startsWith(installmentPrefix)) {
      const installmentName = text.slice(installmentPrefix.length).trim();
      return `Installment assigned: ${installmentName || '-'}`.slice(0, maxLength);
    }

    return text.slice(0, maxLength);
  }

  toNumber(value: unknown): number {
    const num = Number(value);
    return Number.isFinite(num) ? num : 0;
  }

  formatAmount(value: number): string {
    return value.toFixed(2);
  }

  formatPaymentDate(value: string | null | undefined): string {
    const normalized = (value || '').trim();
    if (!normalized) {
      return '-';
    }

    const parsed = new Date(normalized);
    if (Number.isNaN(parsed.getTime())) {
      return normalized;
    }

    const hasExplicitTime = /t|\s+\d{1,2}:\d{2}/i.test(normalized);
    return new Intl.DateTimeFormat('en-IN', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      ...(hasExplicitTime ? { hour: '2-digit', minute: '2-digit', hour12: true } : {})
    }).format(parsed);
  }

  formatPaymentMethod(value: string | null | undefined): string {
    const normalized = (value || '').trim();
    if (!normalized) {
      return '-';
    }

    return normalized
      .split(/[_\s]+/)
      .filter(Boolean)
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
      .join(' ');
  }

  /** Template event handler: (ngSubmit) refundForm -> refunds a payment and refreshes payment history. */
  refundPayment() {
    if (this.refundForm.invalid) {
      this.refundForm.markAllAsTouched();
      return;
    }

    const raw = this.refundForm.getRawValue();
    const paymentId = Number(raw.payment_id);
    const payload = {
      refund_reason: raw.refund_reason,
      refund_date: raw.refund_date || undefined
    };

    this.setBusy('refundPayment', true);
    this.financeService
      .refundPayment(paymentId, payload)
      .pipe(finalize(() => this.setBusy('refundPayment', false)))
      .subscribe({
        next: () => {
          this.refundForm.reset();
          this.loadPayments();
          this.showSuccess('Refund recorded.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to refund payment.')
      });
  }

  // #endregion Template events: Payments (ngSubmit/click)

  // #region Template events: Receipts (ngSubmit)
  /** Template event handler: (ngSubmit) receiptForm -> records a receipt (student-based). */
  createReceipt() {
    if (this.receiptForm.invalid) {
      this.receiptForm.markAllAsTouched();
      return;
    }
    const raw = this.receiptForm.getRawValue();
    const payload = {
      student_id: Number(raw.student_id),
      academic_year_id: Number(raw.academic_year_id),
      amount: Number(raw.amount),
      payment_method: raw.payment_method,
      transaction_id: raw.transaction_id || undefined,
      paid_at: raw.paid_at
    };
    this.setBusy('createReceipt', true);
    this.financeService
      .createReceipt(payload)
      .pipe(finalize(() => this.setBusy('createReceipt', false)))
      .subscribe({
        next: () => {
          this.receiptForm.reset({
            student_id: '',
            academic_year_id: '',
            amount: '',
            payment_method: 'cash',
            transaction_id: '',
            paid_at: ''
          });
          this.showSuccess('Receipt posted.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to create receipt.')
      });
  }

  // #endregion Template events: Receipts (ngSubmit)

  // #region Template events: Holds (ngSubmit/click)
  /** Template event handler: (click) "Refresh" holds -> reloads holds list. */
  refreshHolds() {
    this.financeService.listHolds().subscribe({
      next: (data) => this.holds.set(data)
    });
  }

  /** Template event handler: (ngSubmit) holdForm -> creates a financial hold. */
  createHold() {
    if (this.holdForm.invalid) {
      this.holdForm.markAllAsTouched();
      return;
    }
    const raw = this.holdForm.getRawValue();
    const payload = {
      student_id: Number(raw.student_id),
      reason: raw.reason,
      outstanding_amount: raw.outstanding_amount ? Number(raw.outstanding_amount) : undefined
    };
    this.setBusy('createHold', true);
    this.financeService
      .createHold(payload)
      .pipe(finalize(() => this.setBusy('createHold', false)))
      .subscribe({
        next: () => {
          this.holdForm.reset();
          this.refreshHolds();
          this.showSuccess('Financial hold created.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to create financial hold.')
      });
  }

  /** Template event handler: (click) toggle hold -> activates/deactivates a hold. */
  toggleHold(hold: FinancialHold) {
    const payload = { active: !hold.is_active };
    const key = `toggleHold:${hold.id}`;
    this.setBusy(key, true);
    this.financeService
      .toggleHold(hold.id, payload)
      .pipe(finalize(() => this.setBusy(key, false)))
      .subscribe({
        next: () => {
          this.refreshHolds();
          this.showSuccess('Hold updated.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to update hold.')
      });
  }

  // #endregion Template events: Holds (ngSubmit/click)

  // #region Template events: Reports (click)
  /** Template event handler: (click) "Generate due report" -> fetches due summary rows. */
  runDueReport() {
    const raw = this.dueReportForm.getRawValue();
    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      class_id: raw.class_id ? Number(raw.class_id) : undefined,
      section_id: raw.section_id ? Number(raw.section_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };

    this.reportsLoading.set(true);
    this.financeService.dueReport(params).subscribe({
      next: (response) => {
        this.dueReportRows.set(response.data);
        this.reportsLoading.set(false);
      },
      error: (err) => {
        this.reportsLoading.set(false);
        this.error.set(err?.error?.message || 'Unable to generate due report.');
      }
    });
  }

  downloadDueReportCsv() {
    const rows = this.dueReportRows();
    if (!rows.length) {
      this.showError('Generate due report first.');
      return;
    }

    const fileName = `fee_due_report_${new Date().toISOString().slice(0, 10)}.csv`;
    const csv = [
      ['Enrollment ID', 'Student', 'Academic Year', 'Class', 'Section', 'Total Debits', 'Total Credits', 'Balance Due'],
      ...rows.map((row) => [
        row.enrollment_id,
        row.student,
        row.academic_year,
        row.class,
        row.section,
        row.total_debits,
        row.total_credits,
        row.balance_due,
      ]),
    ].map((line) => line.map((value) => this.escapeCsv(value)).join(',')).join('\n');

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    this.downloadBlob(blob, fileName);
    this.logFinanceDownload('due_report', 'Due Report', fileName, rows.length, this.dueReportForm.getRawValue(), 'csv', blob);
    this.showSuccess('Due report CSV downloaded.');
  }

  /** Template event handler: (click) "Generate collection report" -> fetches collection summary + payments. */
  runCollectionReport() {
    const raw = this.collectionReportForm.getRawValue();
    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      class_id: raw.class_id ? Number(raw.class_id) : undefined,
      section_id: raw.section_id ? Number(raw.section_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };

    this.reportsLoading.set(true);
    this.financeService.collectionReport(params).subscribe({
      next: (response) => {
        this.collectionSummary.set(response.summary);
        this.collectionPayments.set(response.payments);
        this.reportsLoading.set(false);
      },
      error: (err) => {
        this.reportsLoading.set(false);
        this.error.set(err?.error?.message || 'Unable to generate collection report.');
      }
    });
  }

  downloadCollectionReportCsv() {
    const payments = this.collectionPayments();
    const summary = this.collectionSummary();
    if (!summary) {
      this.showError('Generate collection report first.');
      return;
    }

    const fileName = `fee_collection_report_${new Date().toISOString().slice(0, 10)}.csv`;
    const summaryLines = [
      ['Total Amount', summary.total_amount],
      ['Refunds', summary.refunds ?? 0],
      ['Net Amount', summary.net_amount ?? 0],
      ['Total Count', summary.total_count],
    ];
    const paymentLines = [
      ['Payment ID', 'Enrollment ID', 'Receipt', 'Date', 'Amount', 'Method', 'Remarks'],
      ...payments.map((payment) => [
        payment.id,
        payment.enrollment_id,
        payment.receipt_number,
        payment.payment_date,
        payment.amount,
        payment.payment_method,
        payment.remarks ?? '',
      ]),
    ];
    const csv = [...summaryLines, [], ...paymentLines]
      .map((line) => line.map((value) => this.escapeCsv(value)).join(','))
      .join('\n');

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    this.downloadBlob(blob, fileName);
    this.logFinanceDownload('collection_report', 'Collection Report', fileName, payments.length, this.collectionReportForm.getRawValue(), 'csv', blob);
    this.showSuccess('Collection report CSV downloaded.');
  }

  /** Template event handler: (click) "Generate route-wise report" -> fetches transport route-wise report. */
  runRouteWiseReport() {
    const raw = this.routeWiseReportForm.getRawValue();
    const params = {
      academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
      class_id: raw.class_id ? Number(raw.class_id) : undefined,
      section_id: raw.section_id ? Number(raw.section_id) : undefined,
      start_date: raw.start_date || undefined,
      end_date: raw.end_date || undefined
    };

    this.reportsLoading.set(true);
    this.financeService.routeWiseReport(params).subscribe({
      next: (response) => {
        this.routeWiseRows.set(response.data);
        this.reportsLoading.set(false);
      },
      error: (err) => {
        this.reportsLoading.set(false);
        this.error.set(err?.error?.message || 'Unable to generate route-wise report.');
      }
    });
  }

  downloadRouteWiseReportCsv() {
    const rows = this.routeWiseRows();
    if (!rows.length) {
      this.showError('Generate route-wise report first.');
      return;
    }

    const fileName = `transport_route_wise_report_${new Date().toISOString().slice(0, 10)}.csv`;
    const csv = [
      ['Route ID', 'Route Name', 'Route Number', 'Enrollment IDs', 'Students', 'Fee / Student', 'Total Amount'],
      ...rows.map((row) => [
        row.route_id ?? '',
        row.route_name ?? '',
        row.route_number ?? '',
        (row.enrollment_ids || []).join(', '),
        row.student_count,
        row.fee_amount,
        row.total_amount,
      ]),
    ].map((line) => line.map((value) => this.escapeCsv(value)).join(',')).join('\n');

    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    this.downloadBlob(blob, fileName);
    this.logFinanceDownload('route_wise_report', 'Route-Wise Report', fileName, rows.length, this.routeWiseReportForm.getRawValue(), 'csv', blob);
    this.showSuccess('Route-wise report CSV downloaded.');
  }

  // #endregion Template events: Reports (click)

  // #region Template events: Transport (click/ngSubmit)
  refreshRoutes() {
    this.financeService.listRoutes().subscribe({
      next: (data) => this.routes.set(data)
    });
  }

  refreshStops() {
    this.financeService.listStops().subscribe({
      next: (data) => this.stops.set(data)
    });
  }

  /** Template event handler: (click) load/refresh assignments -> loads transport assignments for enrollment. */
  loadTransportAssignments(enrollmentId?: number) {
    const rawQuery = this.transportAssignmentsQueryForm.getRawValue();
    const queryEnrollmentId = rawQuery.enrollment_id ? Number(rawQuery.enrollment_id) : null;
    const id = enrollmentId ?? this.selectedEnrollmentId() ?? queryEnrollmentId;
    if (!id) {
      this.transportAssignments.set([]);
      return;
    }

    this.transportAssignmentsQueryForm.patchValue({ enrollment_id: String(id) }, { emitEvent: false });

    this.financeService
      .listTransportAssignments({ enrollment_id: id, per_page: 50 })
      .subscribe({
        next: (response) => {
          const rows = response.data ?? [];
          this.transportAssignments.set(rows);

          const active = rows.filter((item) => item.status === 'active');
          if (active.length === 1) {
            const chosenId = String(active[0].id);
            if (!this.transportStopAssignmentForm.controls.assignment_id.value) {
              this.transportStopAssignmentForm.patchValue({ assignment_id: chosenId });
            }
            if (!this.transportCycleForm.controls.assignment_id.value) {
              this.transportCycleForm.patchValue({ assignment_id: chosenId });
            }
          }

          if (!this.transportCycleForm.controls.month.value) {
            this.transportCycleForm.patchValue({ month: String(new Date().getMonth() + 1) });
          }
          if (!this.transportCycleForm.controls.year.value) {
            this.transportCycleForm.patchValue({ year: String(new Date().getFullYear()) });
          }
        },
        error: () => this.transportAssignments.set([])
      });
  }

  /** Template event handler: (click) "Search" bulk enrollments -> lists active enrollments to select from. */
  searchBulkTransportEnrollments() {
    const query = this.bulkTransportAssignForm.controls.search.value.trim();
    this.bulkTransportEnrollmentsLoading.set(true);
    this.enrollmentsService
      .list({ status: 'active', search: query || undefined, per_page: 50 })
      .pipe(finalize(() => this.bulkTransportEnrollmentsLoading.set(false)))
      .subscribe({
        next: (response) => this.bulkTransportEnrollments.set(response.data),
        error: (err) => this.showError(err?.error?.message || 'Unable to load enrollments.')
      });
  }

  toggleBulkTransportEnrollment(enrollmentId: number) {
    const current = new Set(this.bulkTransportSelectedEnrollmentIds());
    if (current.has(enrollmentId)) {
      current.delete(enrollmentId);
    } else {
      current.add(enrollmentId);
    }
    this.bulkTransportSelectedEnrollmentIds.set(Array.from(current));
  }

  clearBulkTransportSelection() {
    this.bulkTransportSelectedEnrollmentIds.set([]);
  }

  /** Template event handler: (click) "Ledger" -> loads ledger entries for selected enrollment. */
  loadBulkTransportLedgerPreview(enrollment: Enrollment) {
    this.bulkTransportLedgerPreviewEnrollment.set(enrollment);
    this.bulkTransportLedgerPreviewLoading.set(true);
    this.financeService
      .ledgerByEnrollment(enrollment.id)
      .pipe(finalize(() => this.bulkTransportLedgerPreviewLoading.set(false)))
      .subscribe({
        next: (rows) => this.bulkTransportLedgerPreviewEntries.set(rows),
        error: (err) => this.showError(err?.error?.message || 'Unable to load enrollment ledger.')
      });
  }

  /** Template event handler: (click) "Assign selected" -> creates transport assignments for multiple enrollments. */
  bulkAssignTransport() {
    if (this.bulkTransportAssignForm.invalid) {
      this.bulkTransportAssignForm.markAllAsTouched();
      return;
    }

    const enrollmentIds = this.bulkTransportSelectedEnrollmentIds();
    if (!enrollmentIds.length) {
      this.showError('Select at least one enrollment to bulk assign transport.');
      return;
    }

    const raw = this.bulkTransportAssignForm.getRawValue();
    const payload = {
      enrollment_ids: enrollmentIds,
      route_id: Number(raw.route_id),
      stop_id: Number(raw.stop_id),
      start_date: raw.start_date,
      auto_generate_cycle: true
    };

    this.setBusy('bulkAssignTransport', true);
    this.financeService
      .bulkCreateAssignments(payload)
      .pipe(finalize(() => this.setBusy('bulkAssignTransport', false)))
      .subscribe({
        next: (res) => {
          this.showSuccess(`${res.created_count} assigned; ${res.charged_count} charged (ledger debits created).`);
          const first = enrollmentIds[0];
          this.clearBulkTransportSelection();
          if (first) {
            this.loadTransportAssignments(first);
            const enrollment = this.bulkTransportEnrollments().find((e) => e.id === first);
            if (enrollment) {
              this.loadBulkTransportLedgerPreview(enrollment);
            }
          }
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to bulk assign transport.')
      });
  }

  /** Template event handler: (ngSubmit) transportRouteForm -> creates a transport route. */
  createRoute() {
    if (this.transportRouteForm.invalid) {
      this.transportRouteForm.markAllAsTouched();
      return;
    }
    const raw = this.transportRouteForm.getRawValue();
    const payload: Record<string, unknown> = {
      route_name: raw.route_name,
      vehicle_number: raw.vehicle_number,
      driver_name: raw.driver_name || undefined
    };

    this.setBusy('createRoute', true);
    this.financeService
      .createRoute(payload)
      .pipe(finalize(() => this.setBusy('createRoute', false)))
      .subscribe({
        next: () => {
          this.transportRouteForm.reset();
          this.refreshRoutes();
          this.showSuccess('Route created.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to create route.')
      });
  }

  /** Template event handler: (ngSubmit) transportStopForm -> creates a stop for a route. */
  createStop() {
    if (this.transportStopForm.invalid) {
      this.transportStopForm.markAllAsTouched();
      return;
    }
    const raw = this.transportStopForm.getRawValue();
    const payload = {
      route_id: Number(raw.route_id),
      stop_name: raw.stop_name,
      fee_amount: Number(raw.fee_amount),
      distance_km: raw.distance_km ? Number(raw.distance_km) : undefined
    };
    this.setBusy('createStop', true);
    this.financeService
      .createStop(payload)
      .pipe(finalize(() => this.setBusy('createStop', false)))
      .subscribe({
        next: () => {
          this.transportStopForm.reset({ route_id: raw.route_id, stop_name: '', fee_amount: '', distance_km: '' });
          this.refreshStops();
          this.showSuccess('Stop created.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to create stop.')
      });
  }

  /** Template event handler: (ngSubmit) transportAssignmentForm -> assigns transport to an enrollment. */
  createAssignment() {
    if (this.transportAssignmentForm.invalid) {
      this.transportAssignmentForm.markAllAsTouched();
      return;
    }
    const raw = this.transportAssignmentForm.getRawValue();
    const payload = {
      enrollment_id: Number(raw.enrollment_id),
      route_id: Number(raw.route_id),
      stop_id: Number(raw.stop_id),
      start_date: raw.start_date,
      auto_generate_cycle: true
    };
    this.setBusy('createAssignment', true);
    this.financeService
      .createAssignment(payload)
      .pipe(finalize(() => this.setBusy('createAssignment', false)))
      .subscribe({
        next: () => {
          this.transportAssignmentForm.reset({
            enrollment_id: raw.enrollment_id,
            route_id: raw.route_id,
            stop_id: '',
            start_date: ''
          });
          this.loadTransportAssignments(Number(raw.enrollment_id));
          this.showSuccess('Transport assigned and charged (ledger updated).');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to create assignment.')
      });
  }

  /** Template event handler: (ngSubmit) transportStopAssignmentForm -> ends an active transport assignment. */
  stopAssignment() {
    if (this.transportStopAssignmentForm.invalid) {
      this.transportStopAssignmentForm.markAllAsTouched();
      return;
    }
    const raw = this.transportStopAssignmentForm.getRawValue();
    const payload = { end_date: raw.end_date };
    this.setBusy('stopAssignment', true);
    this.financeService
      .stopAssignment(Number(raw.assignment_id), payload)
      .pipe(finalize(() => this.setBusy('stopAssignment', false)))
      .subscribe({
        next: () => {
          this.transportStopAssignmentForm.reset({ assignment_id: '', end_date: '' });
          this.loadTransportAssignments();
          this.showSuccess('Transport stopped.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to stop assignment.')
      });
  }

  /** Template event handler: (ngSubmit) transportCycleForm -> generates monthly transport charge. */
  generateCycle() {
    if (this.transportCycleForm.invalid) {
      this.transportCycleForm.markAllAsTouched();
      return;
    }
    const raw = this.transportCycleForm.getRawValue();
    const payload: Record<string, unknown> = {
      assignment_id: Number(raw.assignment_id),
      month: Number(raw.month),
      year: Number(raw.year)
    };

    if (raw.amount !== '') {
      payload['amount'] = Number(raw.amount);
    }

    this.setBusy('generateCycle', true);
    this.financeService
      .generateTransportCycle(payload)
      .pipe(finalize(() => this.setBusy('generateCycle', false)))
      .subscribe({
        next: () => {
          this.transportCycleForm.reset({ assignment_id: raw.assignment_id, month: '', year: '', amount: '' });
          this.showSuccess('Monthly charge generated.');
        },
        error: (err) => this.showError(err?.error?.message || 'Unable to generate fee cycle.')
      });
  }
  // #endregion Template events: Transport (click/ngSubmit)
}
