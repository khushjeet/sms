import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgFor, NgIf } from '@angular/common';
import { Employee, EmployeeDocumentType, EmployeeMetadata } from '../../models/employee';
import { EmployeesService } from '../../core/services/employees.service';

interface PendingDocument {
  file: File;
  type: EmployeeDocumentType;
}

@Component({
  selector: 'app-employee-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './employee-form.component.html',
  styleUrl: './employee-form.component.scss'
})
export class EmployeeFormComponent {
  private readonly employeesService = inject(EmployeesService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly submitting = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  readonly selectedImageName = signal<string | null>(null);
  readonly isDragActive = signal(false);
  readonly pendingDocuments = signal<PendingDocument[]>([]);
  readonly existingEmployee = signal<Employee | null>(null);
  readonly downloadingDocumentId = signal<number | null>(null);
  readonly metadata = signal<EmployeeMetadata | null>(null);
  readonly roleDepartmentOptions = ['Teaching Staff', 'Non Teaching Staff'];

  private employeeId?: number;
  private selectedImage: File | null = null;

  readonly form = this.fb.nonNullable.group({
    first_name: ['', Validators.required],
    last_name: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    role: ['', Validators.required],
    password: ['', Validators.minLength(8)],
    employee_id: ['', Validators.required],
    joining_date: ['', Validators.required],
    employee_type: ['', Validators.required],
    designation: [''],
    qualification: [''],
    salary: [''],
    date_of_birth: ['', Validators.required],
    gender: ['', Validators.required],
    address: [''],
    emergency_contact: [''],
    aadhar_number: [''],
    pan_card: [''],
    status: ['', Validators.required],
    resignation_date: ['']
  });

  ngOnInit() {
    this.employeesService.metadata().subscribe({
      next: (meta) => {
        this.metadata.set(meta);
        if (!this.form.value.role && meta.roles.length) {
          this.form.patchValue({ role: meta.roles[0] ?? '' });
        }
        if (!this.form.value.employee_type && meta.employee_types.length) {
          this.form.patchValue({ employee_type: meta.employee_types[0] ?? '' });
        }
        if (!this.form.value.gender && meta.genders.length) {
          this.form.patchValue({ gender: meta.genders[0] ?? '' });
        }
        if (!this.form.value.status && meta.statuses.length) {
          this.form.patchValue({ status: meta.statuses[0] ?? '' });
        }
      }
    });

    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.employeeId = id;
      this.form.get('password')?.setValidators([Validators.minLength(8)]);
      this.form.get('password')?.updateValueAndValidity();
      this.loadEmployee(id);
    } else {
      this.form.get('password')?.setValidators([Validators.required, Validators.minLength(8)]);
      this.form.get('password')?.updateValueAndValidity();
    }
  }

  loadEmployee(id: number) {
    this.loading.set(true);
    this.employeesService.getById(id).subscribe({
      next: (employee) => {
        this.existingEmployee.set(employee);
        this.form.patchValue({
          first_name: employee.user?.first_name ?? '',
          last_name: employee.user?.last_name ?? '',
          email: employee.user?.email ?? '',
          phone: employee.user?.phone ?? '',
          role: employee.user?.role ?? '',
          employee_id: employee.employee_id ?? '',
          joining_date: employee.joining_date ?? '',
          employee_type: employee.employee_type ?? '',
          designation: employee.designation ?? '',
          qualification: employee.qualification ?? '',
          salary: employee.salary ? String(employee.salary) : '',
          date_of_birth: employee.date_of_birth ?? '',
          gender: employee.gender ?? '',
          address: employee.address ?? '',
          emergency_contact: employee.emergency_contact ?? '',
          aadhar_number: employee.aadhar_number ?? '',
          pan_card: employee.pan_number ?? '',
          status: employee.status ?? '',
          resignation_date: employee.resignation_date ?? ''
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load employee.');
      }
    });
  }

  onImageSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.selectedImage = file;
    this.selectedImageName.set(file?.name ?? null);
  }

  onDragOver(event: DragEvent) {
    event.preventDefault();
    this.isDragActive.set(true);
  }

  onDragLeave(event: DragEvent) {
    event.preventDefault();
    this.isDragActive.set(false);
  }

  onDrop(event: DragEvent) {
    event.preventDefault();
    this.isDragActive.set(false);
    const droppedFiles = Array.from(event.dataTransfer?.files || []);
    this.addDocuments(droppedFiles);
  }

  onDocumentsSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files || []);
    this.addDocuments(files);
    input.value = '';
  }

  removePendingDocument(index: number) {
    const current = [...this.pendingDocuments()];
    current.splice(index, 1);
    this.pendingDocuments.set(current);
  }

  changePendingDocumentType(index: number, type: EmployeeDocumentType) {
    const current = [...this.pendingDocuments()];
    const file = current[index];
    if (!file) {
      return;
    }
    current[index] = { ...file, type };
    this.pendingDocuments.set(current);
  }

  submit() {
    if (this.form.invalid || this.loading() || this.submitting()) {
      this.form.markAllAsTouched();
      return;
    }

    this.error.set(null);
    this.submitting.set(true);
    const payload = this.buildPayload();

    if (this.isEdit() && this.employeeId) {
      this.employeesService.update(this.employeeId, payload).subscribe({
        next: (response) => {
          this.submitting.set(false);
          this.router.navigate(['/employees', response.data.id]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.error.set(this.extractApiError(err, 'Unable to update employee.'));
        }
      });
      return;
    }

    this.employeesService.create(payload).subscribe({
      next: (response) => {
        this.submitting.set(false);
        this.router.navigate(['/employees', response.data.id]);
      },
      error: (err) => {
        this.submitting.set(false);
        this.error.set(this.extractApiError(err, 'Unable to create employee.'));
      }
    });
  }

  downloadExistingDocument(documentId: number) {
    if (!this.employeeId) {
      return;
    }
    const doc = this.existingEmployee()?.documents?.find((item) => item.id === documentId);
    if (!doc) {
      return;
    }

    this.downloadingDocumentId.set(documentId);
    this.employeesService.downloadDocument(this.employeeId, documentId).subscribe({
      next: (blob) => {
        const objectUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objectUrl;
        a.download = doc.original_name || doc.file_name;
        a.click();
        URL.revokeObjectURL(objectUrl);
        this.downloadingDocumentId.set(null);
      },
      error: () => {
        this.downloadingDocumentId.set(null);
        this.error.set('Unable to download document.');
      }
    });
  }

  private addDocuments(files: File[]) {
    if (!files.length) {
      return;
    }

    const allowedTypes = [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'image/jpeg',
      'image/png',
      'image/webp'
    ];

    const accepted: PendingDocument[] = [];
    const rejected: string[] = [];

    for (const file of files) {
      const isAllowedMime = allowedTypes.includes(file.type);
      const extension = file.name.split('.').pop()?.toLowerCase();
      const isAllowedExtension = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'].includes(extension || '');
      if (!isAllowedMime && !isAllowedExtension) {
        rejected.push(file.name);
        continue;
      }
      accepted.push({
        file,
        type: this.inferDocumentType(file.name)
      });
    }

    if (rejected.length) {
      this.error.set(`Unsupported file type: ${rejected.join(', ')}`);
    }

    this.pendingDocuments.set([...this.pendingDocuments(), ...accepted]);
  }

  private inferDocumentType(fileName: string): EmployeeDocumentType {
    const lower = fileName.toLowerCase();
    if (lower.includes('resume') || lower.includes('cv')) {
      return 'resume';
    }
    if (lower.includes('pan')) {
      return 'pan_card';
    }
    if (lower.includes('certificate')) {
      return 'certificate';
    }
    if (lower.includes('aadhaar') || lower.includes('aadhar') || lower.includes('id')) {
      return 'identity';
    }
    return 'other';
  }

  private buildPayload(): FormData {
    const raw = this.form.getRawValue();
    const formData = new FormData();

    const appendIfPresent = (key: string, value: string | null | undefined) => {
      if (value === null || value === undefined || value === '') {
        return;
      }
      formData.append(key, value);
    };

    appendIfPresent('first_name', raw.first_name);
    appendIfPresent('last_name', raw.last_name);
    appendIfPresent('email', raw.email);
    appendIfPresent('phone', raw.phone);
    appendIfPresent('role', raw.role);
    if (!this.isEdit() || raw.password) {
      appendIfPresent('password', raw.password);
    }
    appendIfPresent('employee_id', raw.employee_id);
    appendIfPresent('joining_date', raw.joining_date);
    appendIfPresent('employee_type', raw.employee_type);
    appendIfPresent('designation', raw.designation);
    appendIfPresent('qualification', raw.qualification);
    appendIfPresent('salary', raw.salary);
    appendIfPresent('date_of_birth', raw.date_of_birth);
    appendIfPresent('gender', raw.gender);
    appendIfPresent('address', raw.address);
    appendIfPresent('emergency_contact', raw.emergency_contact);
    appendIfPresent('aadhar_number', raw.aadhar_number);
    appendIfPresent('pan_card', raw.pan_card);
    appendIfPresent('status', raw.status);
    appendIfPresent('resignation_date', raw.resignation_date);

    if (this.selectedImage) {
      formData.append('image', this.selectedImage);
    }

    this.pendingDocuments().forEach((doc) => {
      formData.append('documents[]', doc.file);
      formData.append('document_types[]', doc.type);
    });

    return formData;
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

    if (payload?.errors && typeof payload.errors === 'object') {
      Object.entries(payload.errors).forEach(([field, value]) => {
        if (Array.isArray(value) && value.length > 0) {
          messages.push(`${field}: ${value.join(', ')}`);
        } else if (typeof value === 'string') {
          messages.push(`${field}: ${value}`);
        }
      });
    }

    return messages.length ? messages.join(' | ') : fallback;
  }
}
