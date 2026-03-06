import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf, NgFor } from '@angular/common';
import { EnrollmentsService } from '../../core/services/enrollments.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { SectionsService } from '../../core/services/sections.service';
import { ClassesService } from '../../core/services/classes.service';
import { FinanceService } from '../../core/services/finance.service';
import { Enrollment, EnrollmentHistoryResponse } from '../../models/enrollment';
import { AcademicYear } from '../../models/academic-year';
import { Section } from '../../models/section';
import { ClassModel } from '../../models/class';
import { StudentFeeLedgerEntry, TransportAssignmentItem } from '../../models/finance';

@Component({
  selector: 'app-enrollment-detail',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './enrollment-detail.component.html',
  styleUrl: './enrollment-detail.component.scss'
})
export class EnrollmentDetailComponent {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly sectionsService = inject(SectionsService);
  private readonly classesService = inject(ClassesService);
  private readonly financeService = inject(FinanceService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(true);
  readonly actionLoading = signal(false);
  readonly error = signal<string | null>(null);
  readonly enrollment = signal<Enrollment | null>(null);
  readonly history = signal<EnrollmentHistoryResponse | null>(null);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  readonly sections = signal<Section[]>([]);

  readonly ledgerBalance = signal<{ balance: number; debits: number; credits: number } | null>(null);
  readonly ledgerEntries = signal<StudentFeeLedgerEntry[]>([]);
  readonly transportAssignments = signal<TransportAssignmentItem[]>([]);
  readonly transportCharge = signal<{
    has_transport: boolean;
    fee_amount: number;
    route: any;
    stop: any;
  } | null>(null);

  readonly promoteForm = this.fb.nonNullable.group({
    new_academic_year_id: ['', Validators.required],
    new_class_id: [''],
    new_section_id: [''],
    roll_number: [''],
    remarks: ['']
  });

  readonly repeatForm = this.fb.nonNullable.group({
    new_academic_year_id: ['', Validators.required],
    section_id: [''],
    remarks: ['']
  });

  readonly transferForm = this.fb.nonNullable.group({
    transfer_date: ['', Validators.required],
    remarks: ['', Validators.required]
  });

  readonly filteredPromoteSections = computed(() => {
    const raw = this.promoteForm.getRawValue();
    const classId = raw.new_class_id ? Number(raw.new_class_id) : null;
    const yearId = raw.new_academic_year_id ? Number(raw.new_academic_year_id) : null;

    return this.sections().filter((section) => {
      const classMatch = !classId || section.class_id === classId;
      const yearMatch = !yearId || section.academic_year_id === yearId;
      return classMatch && yearMatch;
    });
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      this.error.set('Invalid enrollment id.');
      this.loading.set(false);
      return;
    }

    this.loadReferenceData();
    this.loadEnrollment(id);
    this.loadHistory(id);
  }

  loadReferenceData() {
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => this.academicYears.set(response.data)
    });
    this.classesService.list({ per_page: 200 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });
    this.sectionsService.list({ per_page: 200 }).subscribe({
      next: (response) => this.sections.set(response.data)
    });
  }

  loadEnrollment(id: number) {
    this.enrollmentsService.getById(id).subscribe({
      next: (enrollment) => {
        this.enrollment.set(enrollment);
        this.loading.set(false);
        this.loadFinance(id);
      },
      error: (err) => {
        this.error.set(this.getApiError(err, 'Unable to load enrollment.'));
        this.loading.set(false);
      }
    });
  }

  loadFinance(enrollmentId: number) {
    this.financeService.balanceByEnrollment(enrollmentId).subscribe({
      next: (response) =>
        this.ledgerBalance.set({
          balance: response.balance,
          debits: response.debits,
          credits: response.credits
        }),
      error: () => this.ledgerBalance.set(null)
    });

    this.financeService.ledgerByEnrollment(enrollmentId).subscribe({
      next: (rows) => this.ledgerEntries.set(rows),
      error: () => this.ledgerEntries.set([])
    });

    this.financeService.listTransportAssignments({ enrollment_id: enrollmentId, per_page: 20 }).subscribe({
      next: (response) => this.transportAssignments.set(response.data ?? []),
      error: () => this.transportAssignments.set([])
    });

    this.financeService.transportChargeByEnrollment(enrollmentId).subscribe({
      next: (response) => this.transportCharge.set(response),
      error: () => this.transportCharge.set(null)
    });
  }

  loadHistory(id: number) {
    this.enrollmentsService.academicHistory(id).subscribe({
      next: (response) => this.history.set(response),
      error: (err) => this.error.set(this.getApiError(err, 'Unable to load enrollment history.'))
    });
  }

  promote() {
    const enrollment = this.enrollment();
    if (!enrollment || this.promoteForm.invalid || this.actionLoading()) {
      this.promoteForm.markAllAsTouched();
      return;
    }

    const raw = this.promoteForm.getRawValue();
    const payload = {
      new_academic_year_id: Number(raw.new_academic_year_id),
      new_class_id: raw.new_class_id ? Number(raw.new_class_id) : null,
      new_section_id: raw.new_section_id ? Number(raw.new_section_id) : null,
      roll_number: raw.roll_number ? Number(raw.roll_number) : null,
      remarks: raw.remarks || undefined
    };

    this.actionLoading.set(true);
    this.error.set(null);

    this.enrollmentsService.promote(enrollment.id, payload).subscribe({
      next: (response) => {
        this.actionLoading.set(false);
        this.router.navigate(['/enrollments', response.data.id]);
      },
      error: (err) => {
        this.actionLoading.set(false);
        this.error.set(this.getApiError(err, 'Unable to promote enrollment.'));
      }
    });
  }

  repeat() {
    const enrollment = this.enrollment();
    if (!enrollment || this.repeatForm.invalid || this.actionLoading()) {
      this.repeatForm.markAllAsTouched();
      return;
    }

    const raw = this.repeatForm.getRawValue();
    const payload = {
      new_academic_year_id: Number(raw.new_academic_year_id),
      section_id: raw.section_id ? Number(raw.section_id) : null,
      remarks: raw.remarks || undefined
    };

    this.actionLoading.set(true);
    this.error.set(null);

    this.enrollmentsService.repeat(enrollment.id, payload).subscribe({
      next: (response) => {
        this.actionLoading.set(false);
        this.router.navigate(['/enrollments', response.data.id]);
      },
      error: (err) => {
        this.actionLoading.set(false);
        this.error.set(this.getApiError(err, 'Unable to repeat enrollment.'));
      }
    });
  }

  transfer() {
    const enrollment = this.enrollment();
    if (!enrollment || this.transferForm.invalid || this.actionLoading()) {
      this.transferForm.markAllAsTouched();
      return;
    }

    this.actionLoading.set(true);
    this.error.set(null);

    this.enrollmentsService.transfer(enrollment.id, this.transferForm.getRawValue()).subscribe({
      next: () => {
        this.actionLoading.set(false);
        this.loadEnrollment(enrollment.id);
      },
      error: (err) => {
        this.actionLoading.set(false);
        this.error.set(this.getApiError(err, 'Unable to transfer student.'));
      }
    });
  }

  onPromoteClassOrYearChange() {
    this.promoteForm.patchValue({ new_section_id: '' }, { emitEvent: false });
  }

  getCurrentClassSection(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    const className = enrollment.section?.class?.name
      || anyEnrollment?.section?.class?.name
      || enrollment.classModel?.name
      || anyEnrollment?.class_model?.name
      || anyEnrollment?.classModel?.name
      || anyEnrollment?.student?.profile?.class?.name
      || '-';
    const sectionName = enrollment.section?.name
      || anyEnrollment?.section?.name
      || '-';
    return `${className} / ${sectionName}`;
  }

  getPromotedFromClassSection(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    const promotedFrom = anyEnrollment?.promotedFromEnrollment || anyEnrollment?.promoted_from_enrollment;
    if (!promotedFrom) {
      return '-';
    }

    const className = promotedFrom?.section?.class?.name || '-';
    const classFallback = promotedFrom?.class_model?.name || promotedFrom?.classModel?.name || className;
    const sectionName = promotedFrom?.section?.name || '-';
    return `${classFallback} / ${sectionName}`;
  }

  getPromotedToClassSection(enrollment: Enrollment): string {
    const anyEnrollment = enrollment as any;
    const promotedTo = anyEnrollment?.promotedToEnrollment || anyEnrollment?.promoted_to_enrollment;
    if (!promotedTo) {
      return '-';
    }

    const className = promotedTo?.section?.class?.name || promotedTo?.class_model?.name || promotedTo?.classModel?.name || '-';
    const sectionName = promotedTo?.section?.name || '-';
    return `${className} / ${sectionName}`;
  }

  private getApiError(err: any, fallback: string): string {
    const directMessage = err?.error?.message;
    if (typeof directMessage === 'string' && directMessage.trim().length > 0) {
      return directMessage;
    }

    const internalError = err?.error?.error;
    if (typeof internalError === 'string' && internalError.trim().length > 0) {
      return internalError;
    }

    const validationErrors = err?.error?.errors;
    if (validationErrors && typeof validationErrors === 'object') {
      const firstKey = Object.keys(validationErrors)[0];
      const firstValue = Array.isArray(validationErrors[firstKey]) ? validationErrors[firstKey][0] : validationErrors[firstKey];
      if (typeof firstValue === 'string' && firstValue.trim().length > 0) {
        return firstValue;
      }
    }

    return fallback;
  }
}
