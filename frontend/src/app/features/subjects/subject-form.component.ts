import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf } from '@angular/common';
import { SubjectsService } from '../../core/services/subjects.service';

@Component({
  selector: 'app-subject-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf],
  templateUrl: './subject-form.component.html',
  styleUrl: './subject-form.component.scss'
})
export class SubjectFormComponent {
  private readonly subjectsService = inject(SubjectsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  private subjectId?: number;

  readonly form = this.fb.nonNullable.group({
    name: ['', Validators.required],
    subject_code: ['', [Validators.required, Validators.pattern(/^[A-Za-z0-9_-]+$/)]],
    short_name: [''],
    type: ['core'],
    status: ['active'],
    credits: [''],
    description: [''],
    board_code: [''],
    lms_code: [''],
    erp_code: [''],
    effective_from: [''],
    effective_to: ['']
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.subjectId = id;
      this.loadSubject(id);
    }
  }

  loadSubject(id: number) {
    this.loading.set(true);
    this.subjectsService.getById(id).subscribe({
      next: (subject) => {
        this.form.patchValue({
          name: subject.name,
          subject_code: subject.subject_code || subject.code,
          short_name: subject.short_name ?? '',
          type: subject.type ?? 'core',
          status: subject.status ?? 'active',
          credits: subject.credits != null ? String(subject.credits) : '',
          description: subject.description ?? '',
          board_code: subject.board_code ?? '',
          lms_code: subject.lms_code ?? '',
          erp_code: subject.erp_code ?? '',
          effective_from: subject.effective_from ?? '',
          effective_to: subject.effective_to ?? ''
        });
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load subject.');
      }
    });
  }

  submit() {
    if (this.form.invalid || this.loading()) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const payload: Record<string, unknown> = {
      name: raw.name.trim(),
      subject_code: raw.subject_code.trim(),
      short_name: raw.short_name || undefined,
      type: raw.type,
      is_active: raw.status === 'active',
      credits: raw.credits ? Number(raw.credits) : undefined,
      description: raw.description || undefined,
      board_code: raw.board_code || undefined,
      lms_code: raw.lms_code || undefined,
      erp_code: raw.erp_code || undefined,
      effective_from: raw.effective_from || undefined,
      effective_to: raw.effective_to || undefined
    };

    this.loading.set(true);
    this.error.set(null);

    if (this.isEdit() && this.subjectId) {
      this.subjectsService.update(this.subjectId, payload).subscribe({
        next: () => {
          this.loading.set(false);
          this.router.navigate(['/subjects']);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to update subject.');
        }
      });
      return;
    }

    this.subjectsService.create(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/subjects']);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to create subject.');
      }
    });
  }
}

