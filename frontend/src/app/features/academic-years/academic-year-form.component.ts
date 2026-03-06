import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf } from '@angular/common';
import { AcademicYearsService } from '../../core/services/academic-years.service';

@Component({
  selector: 'app-academic-year-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf],
  templateUrl: './academic-year-form.component.html',
  styleUrl: './academic-year-form.component.scss'
})
export class AcademicYearFormComponent {
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  private yearId?: number;

  readonly form = this.fb.nonNullable.group({
    name: ['', Validators.required],
    start_date: ['', Validators.required],
    end_date: ['', Validators.required],
    description: [''],
    is_current: [false]
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.yearId = id;
      this.loadYear(id);
    }
  }

  loadYear(id: number) {
    this.loading.set(true);
    this.academicYearsService.getById(id).subscribe({
      next: (year) => {
        this.form.patchValue({
          name: year.name,
          start_date: year.start_date,
          end_date: year.end_date,
          description: year.description ?? '',
          is_current: !!year.is_current
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load academic year.');
      }
    });
  }

  submit() {
    if (this.form.invalid || this.loading()) {
      this.form.markAllAsTouched();
      return;
    }

    this.loading.set(true);
    this.error.set(null);

    const raw = this.form.getRawValue();
    const payload: Record<string, unknown> = {
      ...raw,
      is_current: !!raw.is_current
    };

    if (this.isEdit() && this.yearId) {
      this.academicYearsService.update(this.yearId, payload).subscribe({
        next: () => {
          this.loading.set(false);
          this.router.navigate(['/academic-years']);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to update academic year.');
        }
      });
      return;
    }

    this.academicYearsService.create(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/academic-years']);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to create academic year.');
      }
    });
  }
}
