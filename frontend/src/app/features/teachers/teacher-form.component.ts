import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgFor, NgIf } from '@angular/common';
import { Teacher, TeacherDocumentType } from '../../models/teacher';
import { TeachersService } from '../../core/services/teachers.service';

interface PendingDocument {
  file: File;
  type: TeacherDocumentType;
}

@Component({
  selector: 'app-teacher-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './teacher-form.component.html',
  styleUrl: './teacher-form.component.scss'
})
export class TeacherFormComponent {
  private readonly teachersService = inject(TeachersService);
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
  readonly existingTeacher = signal<Teacher | null>(null);
  readonly downloadingDocumentId = signal<number | null>(null);
  readonly documentTypes: Array<{ label: string; value: TeacherDocumentType }> = [
    { label: 'Resume', value: 'resume' },
    { label: 'Identity', value: 'identity' },
    { label: 'Certificate', value: 'certificate' },
    { label: 'PAN Card', value: 'pan_card' },
    { label: 'Other', value: 'other' }
  ];

  private teacherId?: number;
  private selectedImage: File | null = null;

  readonly form = this.fb.nonNullable.group({
    first_name: ['', Validators.required],
    last_name: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    phone: [''],
    password: ['', Validators.minLength(8)],
    employee_id: ['', Validators.required],
    joining_date: ['', Validators.required],
    designation: ['Teacher'],
    department: [''],
    qualification: [''],
    salary: [''],
    date_of_birth: ['', Validators.required],
    gender: ['', Validators.required],
    address: [''],
    emergency_contact: [''],
    aadhar_number: [''],
    pan_card: [''],
    status: ['active'],
    resignation_date: ['']
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.teacherId = id;
      this.form.get('password')?.clearValidators();
      this.form.get('password')?.updateValueAndValidity();
      this.loadTeacher(id);
    } else {
      this.form.get('password')?.setValidators([Validators.required, Validators.minLength(8)]);
      this.form.get('password')?.updateValueAndValidity();
    }
  }

  loadTeacher(id: number) {
    this.loading.set(true);
    this.teachersService.getById(id).subscribe({
      next: (teacher) => {
        this.existingTeacher.set(teacher);
        this.form.patchValue({
          first_name: teacher.user?.first_name ?? '',
          last_name: teacher.user?.last_name ?? '',
          email: teacher.user?.email ?? '',
          phone: teacher.user?.phone ?? '',
          employee_id: teacher.employee_id ?? '',
          joining_date: teacher.joining_date ?? '',
          designation: teacher.designation ?? 'Teacher',
          department: teacher.department ?? '',
          qualification: teacher.qualification ?? '',
          salary: teacher.salary ? String(teacher.salary) : '',
          date_of_birth: teacher.date_of_birth ?? '',
          gender: teacher.gender ?? '',
          address: teacher.address ?? '',
          emergency_contact: teacher.emergency_contact ?? '',
          aadhar_number: teacher.aadhar_number ?? '',
          pan_card: teacher.pan_number ?? '',
          status: teacher.status ?? 'active',
          resignation_date: teacher.resignation_date ?? ''
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load teacher.');
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

  changePendingDocumentType(index: number, type: TeacherDocumentType) {
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

    if (this.isEdit() && this.teacherId) {
      this.teachersService.update(this.teacherId, payload).subscribe({
        next: (response) => {
          this.submitting.set(false);
          this.router.navigate(['/teachers', response.data.id]);
        },
        error: (err) => {
          this.submitting.set(false);
          this.error.set(this.extractApiError(err, 'Unable to update teacher.'));
        }
      });
      return;
    }

    this.teachersService.create(payload).subscribe({
      next: (response) => {
        this.submitting.set(false);
        this.router.navigate(['/teachers', response.data.id]);
      },
      error: (err) => {
        this.submitting.set(false);
        this.error.set(this.extractApiError(err, 'Unable to create teacher.'));
      }
    });
  }

  downloadExistingDocument(documentId: number) {
    if (!this.teacherId) {
      return;
    }
    const doc = this.existingTeacher()?.documents?.find((item) => item.id === documentId);
    if (!doc) {
      return;
    }

    this.downloadingDocumentId.set(documentId);
    this.teachersService.downloadDocument(this.teacherId, documentId).subscribe({
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

  private inferDocumentType(fileName: string): TeacherDocumentType {
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
    if (!this.isEdit() || raw.password) {
      appendIfPresent('password', raw.password);
    }
    appendIfPresent('employee_id', raw.employee_id);
    appendIfPresent('joining_date', raw.joining_date);
    appendIfPresent('designation', raw.designation);
    appendIfPresent('department', raw.department);
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

