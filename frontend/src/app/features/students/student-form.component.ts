import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgFor, NgIf } from '@angular/common';
import { StudentsService } from '../../core/services/students.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ClassesService } from '../../core/services/classes.service';
import { AcademicYear } from '../../models/academic-year';
import { ClassModel } from '../../models/class';

@Component({
  selector: 'app-student-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './student-form.component.html',
  styleUrl: './student-form.component.scss'
})
export class StudentFormComponent {
  private readonly studentsService = inject(StudentsService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly classesService = inject(ClassesService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly submitting = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  readonly selectedImageName = signal<string | null>(null);
  readonly showCreatePassword = signal(false);
  readonly showEditPassword = signal(false);
  readonly showEditPasswordConfirmation = signal(false);
  readonly academicYears = signal<AcademicYear[]>([]);
  readonly classes = signal<ClassModel[]>([]);
  private studentId?: number;
  private selectedImage: File | null = null;

  readonly form = this.fb.nonNullable.group({
    academic_year_id: [''],
    roll_number: [''],
    class_id: [''],
    first_name: ['', Validators.required],
    last_name: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    password: [''],
    edit_password: [''],
    edit_password_confirmation: [''],
    admission_number: ['', Validators.required],
    admission_date: ['', Validators.required],
    date_of_birth: ['', Validators.required],
    gender: ['', Validators.required],
    blood_group: [''],
    address: [''],
    city: [''],
    state: [''],
    pincode: [''],
    nationality: ['Indian'],
    religion: [''],
    category: [''],
    caste: [''],
    aadhar_number: [''],
    father_name: [''],
    father_email: [''],
    father_mobile_number: [''],
    father_occupation: [''],
    mother_name: [''],
    mother_email: [''],
    mother_mobile_number: [''],
    mother_occupation: [''],
    bank_account_number: [''],
    bank_account_holder: [''],
    ifsc_code: [''],
    relation_with_account_holder: [''],
    permanent_address: [''],
    current_address: [''],
    medical_info: [''],
    remarks: ['']
  });

  ngOnInit() {
    this.loadReferenceData();

    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.studentId = id;
      this.disableCreateOnlyFields();
      this.loadStudent(id);
    }
  }

  disableCreateOnlyFields() {
    ['academic_year_id', 'roll_number', 'class_id', 'password', 'admission_number', 'admission_date', 'date_of_birth', 'gender', 'nationality', 'religion', 'category', 'aadhar_number']
      .forEach((field) => this.form.get(field)?.disable());
  }

  loadReferenceData() {
    this.academicYearsService.list({ per_page: 100, status: 'active' }).subscribe({
      next: (response) => this.academicYears.set(response.data),
    });

    this.classesService.list({ per_page: 100, status: 'active' }).subscribe({
      next: (response) => this.classes.set(response.data),
    });
  }

  onImageSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.selectedImage = file;
    this.selectedImageName.set(file?.name ?? null);
  }

  loadStudent(id: number) {
    this.loading.set(true);
    this.studentsService.getById(id).subscribe({
      next: (student) => {
        this.form.patchValue({
          first_name: student.user?.first_name ?? '',
          last_name: student.user?.last_name ?? '',
          email: student.user?.email ?? '',
          phone: (student.user as any)?.phone ?? '',
          academic_year_id: student.profile?.academic_year_id ? String(student.profile.academic_year_id) : '',
          class_id: student.profile?.class_id ? String(student.profile.class_id) : '',
          roll_number: student.profile?.roll_number ?? '',
          blood_group: student.blood_group ?? '',
          address: student.address ?? '',
          city: student.city ?? '',
          state: student.state ?? '',
          pincode: student.pincode ?? '',
          caste: (student.profile as any)?.caste ?? '',
          father_name: student.profile?.father_name ?? '',
          father_email: student.profile?.father_email ?? '',
          father_mobile_number: student.profile?.father_mobile_number ?? (student.profile as any)?.father_mobile ?? '',
          father_occupation: student.profile?.father_occupation ?? '',
          mother_name: student.profile?.mother_name ?? '',
          mother_email: student.profile?.mother_email ?? '',
          mother_mobile_number: student.profile?.mother_mobile_number ?? (student.profile as any)?.mother_mobile ?? '',
          mother_occupation: student.profile?.mother_occupation ?? '',
          bank_account_number: student.profile?.bank_account_number ?? '',
          bank_account_holder: student.profile?.bank_account_holder ?? '',
          ifsc_code: student.profile?.ifsc_code ?? '',
          permanent_address: student.profile?.permanent_address ?? '',
          current_address: student.profile?.current_address ?? '',
          medical_info: student.medical_info ? JSON.stringify(student.medical_info, null, 2) : '',
          remarks: student.remarks ?? ''
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load student.');
      }
    });
  }

  submit() {
    if (this.form.invalid || this.loading() || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    this.submitting.set(true);
    this.error.set(null);

    const raw = this.form.getRawValue();
    const medicalInfo = raw.medical_info
      ? this.safeParseJson(raw.medical_info)
      : undefined;

    if (this.isEdit() && this.studentId) {
      if (raw.edit_password && raw.edit_password !== raw.edit_password_confirmation) {
        this.submitting.set(false);
        this.error.set('Password confirmation does not match.');
        return;
      }

      const payload: Record<string, unknown> = {
        first_name: raw.first_name,
        last_name: raw.last_name,
        email: raw.email,
        phone: raw.phone,
        blood_group: raw.blood_group || undefined,
        address: raw.address || undefined,
        city: raw.city || undefined,
        state: raw.state || undefined,
        pincode: raw.pincode || undefined,
        academic_year_id: raw.academic_year_id ? Number(raw.academic_year_id) : undefined,
        class_id: raw.class_id ? Number(raw.class_id) : undefined,
        roll_number: raw.roll_number || undefined,
        caste: raw.caste || undefined,
        father_name: raw.father_name || undefined,
        father_email: raw.father_email || undefined,
        father_mobile_number: raw.father_mobile_number || undefined,
        father_occupation: raw.father_occupation || undefined,
        mother_name: raw.mother_name || undefined,
        mother_email: raw.mother_email || undefined,
        mother_mobile_number: raw.mother_mobile_number || undefined,
        mother_occupation: raw.mother_occupation || undefined,
        bank_account_number: raw.bank_account_number || undefined,
        bank_account_holder: raw.bank_account_holder || undefined,
        ifsc_code: raw.ifsc_code || undefined,
        relation_with_account_holder: raw.relation_with_account_holder || undefined,
        permanent_address: raw.permanent_address || undefined,
        current_address: raw.current_address || undefined,
        medical_info: medicalInfo,
        remarks: raw.remarks || undefined
      };
      if (raw.edit_password) {
        payload['password'] = raw.edit_password;
        payload['password_confirmation'] = raw.edit_password_confirmation || '';
      }
      this.studentsService.update(this.studentId, this.buildPayload(payload)).subscribe({
        next: () => {
          this.submitting.set(false);
          this.router.navigate(['/students', this.studentId]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.error.set(this.extractApiError(err, 'Unable to update student.'));
        }
      });
      return;
    }

    const payload: Record<string, unknown> = {
      ...raw,
      medical_info: medicalInfo
    };

    this.studentsService.create(this.buildPayload(payload)).subscribe({
      next: (response) => {
        this.submitting.set(false);
        this.router.navigate(['/students', response.data.id]);
      },
      error: (err) => {
        this.submitting.set(false);
        this.error.set(this.extractApiError(err, 'Unable to create student.'));
      }
    });
  }

  private extractApiError(err: any, fallback: string): string {
    const payload = err?.error;
    const messages: string[] = [];

    if (typeof payload === 'string') {
      messages.push(payload);
    }

    if (payload?.message && typeof payload.message === 'string') {
      messages.push(payload.message);
    }

    const rawError = typeof payload?.error === 'string' ? payload.error : '';
    const isSqlError = /SQLSTATE|Unknown column|insert into|update .* set/i.test(rawError);
    if (isSqlError) {
      if (/Unknown column 'user_id'/i.test(rawError)) {
        messages.push('Database update required. Please run: php artisan migrate');
      } else {
        messages.push('Database error occurred while saving student details.');
      }
    }

    if (payload?.errors && typeof payload.errors === 'object') {
      Object.entries(payload.errors).forEach(([field, value]) => {
        if (Array.isArray(value) && value.length > 0) {
          messages.push(`${field}: ${value.join(', ')}`);
        } else if (typeof value === 'string') {
          messages.push(`${field}: ${value}`);
        }
      });
    }

    if (!messages.length && err?.message) {
      messages.push(err.message);
    }

    return messages.length ? messages.join(' | ') : fallback;
  }

  private safeParseJson(value: string) {
    try {
      return JSON.parse(value);
    } catch {
      return undefined;
    }
  }

  private buildPayload(payload: Record<string, unknown>): Record<string, unknown> | FormData {
    if (!this.selectedImage) {
      return payload;
    }

    const formData = new FormData();
    Object.entries(payload).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') {
        return;
      }
      if (typeof value === 'object') {
        formData.append(key, JSON.stringify(value));
        return;
      }
      formData.append(key, String(value));
    });
    if (this.selectedImage) {
      formData.append('image', this.selectedImage);
    }
    return formData;
  }
}
