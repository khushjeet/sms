import { NgFor, NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { EmployeesService } from '../../core/services/employees.service';
import {
  SchoolDetails,
  SchoolDetailsService
} from '../../core/services/school-details.service';
import {
  SchoolSignatures,
  SchoolSignaturesService
} from '../../core/services/school-signatures.service';
import { Employee } from '../../models/employee';
import { environment } from '../../../environments/environment';

type UploadMode = 'principal' | 'director' | 'employee';
type SignatureSlot = 'principal' | 'director';

@Component({
  selector: 'app-signature-upload',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule],
  templateUrl: './signature-upload.component.html',
  styleUrl: './signature-upload.component.scss'
})
export class SignatureUploadComponent {
  private readonly employeesService = inject(EmployeesService);
  private readonly schoolSignaturesService = inject(SchoolSignaturesService);
  private readonly schoolDetailsService = inject(SchoolDetailsService);
  private readonly fb = inject(FormBuilder);
  private readonly apiOrigin = environment.apiBaseUrl.replace(/\/api\/v\d+\/?$/, '');

  readonly loading = signal(false);
  readonly saving = signal(false);
  readonly savingDetails = signal(false);
  readonly error = signal<string | null>(null);
  readonly message = signal<string | null>(null);
  readonly employees = signal<Employee[]>([]);
  readonly selectedEmployee = signal<Employee | null>(null);
  readonly signatures = signal<SchoolSignatures | null>(null);
  readonly schoolDetails = signal<SchoolDetails | null>(null);
  readonly selectedFile = signal<File | null>(null);
  readonly selectedSchoolLogoFile = signal<File | null>(null);
  readonly schoolLogoPreview = signal<string | null>(null);

  readonly form = this.fb.nonNullable.group({
    mode: ['principal' as UploadMode],
    employee_search: [''],
    target_slot: ['principal' as SignatureSlot],
  });

  readonly schoolDetailsForm = this.fb.nonNullable.group({
    name: [''],
    website: [''],
    phone: [''],
    logo_url: [''],
    watermark_text: [''],
    watermark_logo_url: [''],
    address: [''],
    registration_number: [''],
    udise_code: [''],
  });

  ngOnInit() {
    this.loadSignatures();
    this.loadSchoolDetails();
  }

  onModeChange() {
    this.selectedFile.set(null);
    this.selectedEmployee.set(null);
    this.employees.set([]);
    this.error.set(null);
    this.message.set(null);

    if (this.form.controls.mode.value === 'employee') {
      this.loadEmployees();
    }
  }

  loadEmployees() {
    this.loading.set(true);
    this.error.set(null);

    this.employeesService
      .list({
        status: 'active',
        search: this.form.controls.employee_search.value.trim() || undefined,
        per_page: 100
      })
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.employees.set(response.data ?? []);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load employees.');
        }
      });
  }

  selectEmployee(employeeId: string) {
    const id = Number(employeeId);
    if (!id) {
      this.selectedEmployee.set(null);
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    this.employeesService
      .getById(id)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (employee) => this.selectedEmployee.set(employee),
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load employee details.');
        }
      });
  }

  onFileSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    this.selectedFile.set(input.files?.[0] ?? null);
    input.value = '';
  }

  async onSchoolLogoSelected(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    this.clearSchoolLogoPreview();
    this.selectedSchoolLogoFile.set(null);

    if (file) {
      const optimizedFile = await this.optimizeSchoolLogo(file);
      this.selectedSchoolLogoFile.set(optimizedFile);
      this.schoolLogoPreview.set(URL.createObjectURL(optimizedFile));
      this.schoolDetailsForm.controls.logo_url.setValue('');

      if (optimizedFile.size < file.size) {
        const savedKb = Math.max(1, Math.round((file.size - optimizedFile.size) / 1024));
        this.message.set(`School logo optimized before upload. Saved about ${savedKb} KB.`);
      }
    }

    input.value = '';
  }

  clearFile() {
    this.selectedFile.set(null);
  }

  clearSchoolLogoFile() {
    this.selectedSchoolLogoFile.set(null);
    this.clearSchoolLogoPreview();
  }

  deleteSignature(slot: SignatureSlot) {
    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.schoolSignaturesService
      .delete(slot)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.signatures.set(response.data);
          this.message.set(`${this.slotLabel(slot)} signature deleted successfully.`);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to delete signature.');
        }
      });
  }

  save() {
    const file = this.selectedFile();
    if (!file) {
      this.error.set('Choose a signature image first.');
      return;
    }

    if (this.form.controls.mode.value === 'employee' && !this.selectedEmployee()) {
      this.error.set('Select an employee first.');
      return;
    }

    const payload = new FormData();
    const slot = this.currentSlot();
    payload.append(slot === 'principal' ? 'principal_signature' : 'director_signature', file);

    this.saving.set(true);
    this.error.set(null);
    this.message.set(null);

    this.schoolSignaturesService
      .update(payload)
      .pipe(finalize(() => this.saving.set(false)))
      .subscribe({
        next: (response) => {
          this.signatures.set(response.data);
          this.selectedFile.set(null);
          this.message.set(`${this.slotLabel(slot)} signature uploaded successfully.`);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to upload signature.');
        }
      });
  }

  saveSchoolDetails() {
    const name = this.schoolDetailsForm.controls.name.value.trim();
    if (!name) {
      this.error.set('School name is required.');
      return;
    }

    const payload = new FormData();
    payload.append('name', name);
    payload.append('website', this.schoolDetailsForm.controls.website.value.trim());
    payload.append('phone', this.schoolDetailsForm.controls.phone.value.trim());
    payload.append('logo_url', this.schoolDetailsForm.controls.logo_url.value.trim());
    payload.append('watermark_text', this.schoolDetailsForm.controls.watermark_text.value.trim());
    payload.append('watermark_logo_url', this.schoolDetailsForm.controls.watermark_logo_url.value.trim());
    payload.append('address', this.schoolDetailsForm.controls.address.value.trim());
    payload.append('registration_number', this.schoolDetailsForm.controls.registration_number.value.trim());
    payload.append('udise_code', this.schoolDetailsForm.controls.udise_code.value.trim());

    const logoFile = this.selectedSchoolLogoFile();
    if (logoFile) {
      payload.append('logo', logoFile);
    }

    this.savingDetails.set(true);
    this.error.set(null);
    this.message.set(null);

    this.schoolDetailsService
      .update(payload)
      .pipe(finalize(() => this.savingDetails.set(false)))
      .subscribe({
        next: (response) => {
          this.schoolDetails.set(response.data);
          this.patchSchoolDetailsForm(response.data);
          this.clearSchoolLogoFile();
          this.message.set(response.message || 'School details saved successfully.');
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to save school details.');
        }
      });
  }

  employeeName(employee: Employee): string {
    const user = employee.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }

  fileUrl(path?: string | null): string | null {
    if (!path) {
      return null;
    }
    if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:')) {
      return path;
    }

    return `${this.apiOrigin}/storage/${path.replace(/^public\//, '').replace(/^\/+/, '')}`;
  }

  schoolLogoPreviewUrl(): string | null {
    return this.schoolLogoPreview() || this.fileUrl(this.schoolDetailsForm.controls.logo_url.value);
  }

  slotLabel(slot: SignatureSlot): string {
    return slot === 'principal' ? 'Principal' : 'Director';
  }

  currentMode(): UploadMode {
    return this.form.controls.mode.value;
  }

  currentSlot(): SignatureSlot {
    const mode = this.currentMode();
    if (mode === 'principal' || mode === 'director') {
      return mode;
    }

    return this.form.controls.target_slot.value;
  }

  private loadSignatures() {
    this.loading.set(true);
    this.error.set(null);

    this.schoolSignaturesService
      .get()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (signatures) => this.signatures.set(signatures),
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load current signatures.');
        }
      });
  }

  private loadSchoolDetails() {
    this.loading.set(true);
    this.error.set(null);

    this.schoolDetailsService
      .get()
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (details) => {
          this.schoolDetails.set(details);
          this.patchSchoolDetailsForm(details);
        },
        error: (err) => {
          this.error.set(err?.error?.message || 'Unable to load school details.');
        }
      });
  }

  private patchSchoolDetailsForm(details: SchoolDetails | null) {
    this.schoolDetailsForm.patchValue({
      name: details?.name || '',
      website: details?.website || '',
      phone: details?.phone || '',
      logo_url: details?.logo_url || '',
      watermark_text: details?.watermark_text || '',
      watermark_logo_url: details?.watermark_logo_url || '',
      address: details?.address || '',
      registration_number: details?.registration_number || '',
      udise_code: details?.udise_code || '',
    }, { emitEvent: false });
  }

  private clearSchoolLogoPreview() {
    const preview = this.schoolLogoPreview();
    if (preview) {
      URL.revokeObjectURL(preview);
    }
    this.schoolLogoPreview.set(null);
  }

  private async optimizeSchoolLogo(file: File): Promise<File> {
    if (!file.type.startsWith('image/')) {
      return file;
    }

    try {
      const dataUrl = await this.readFileAsDataUrl(file);
      const image = await this.loadImage(dataUrl);
      const maxDimension = 1200;
      const scale = Math.min(1, maxDimension / Math.max(image.naturalWidth || image.width, image.naturalHeight || image.height));
      const width = Math.max(1, Math.round((image.naturalWidth || image.width || 1) * scale));
      const height = Math.max(1, Math.round((image.naturalHeight || image.height || 1) * scale));
      const mimeType = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
      const quality = mimeType === 'image/png' ? undefined : 0.82;
      const blob = await this.drawResizedImage(image, width, height, mimeType, quality);

      if (!blob || blob.size === 0 || blob.size >= file.size) {
        return file;
      }

      const extension = mimeType === 'image/png' ? 'png' : 'jpg';
      const safeName = file.name.replace(/\.[^.]+$/, '') || 'school-logo';
      return new File([blob], `${safeName}.${extension}`, {
        type: mimeType,
        lastModified: Date.now(),
      });
    } catch {
      return file;
    }
  }

  private readFileAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve((reader.result as string) || '');
      reader.onerror = () => reject(new Error('Unable to read image file.'));
      reader.readAsDataURL(file);
    });
  }

  private loadImage(source: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('Unable to load image.'));
      image.src = source;
    });
  }

  private drawResizedImage(
    image: HTMLImageElement,
    width: number,
    height: number,
    mimeType: 'image/png' | 'image/jpeg',
    quality?: number
  ): Promise<Blob | null> {
    return new Promise((resolve) => {
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;

      const context = canvas.getContext('2d');
      if (!context) {
        resolve(null);
        return;
      }

      if (mimeType === 'image/jpeg') {
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
      }

      context.drawImage(image, 0, 0, width, height);
      canvas.toBlob((blob) => resolve(blob), mimeType, quality);
    });
  }
}



