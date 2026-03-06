import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf } from '@angular/common';
import { ClassesService } from '../../core/services/classes.service';

@Component({
  selector: 'app-class-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf],
  templateUrl: './class-form.component.html',
  styleUrl: './class-form.component.scss'
})
export class ClassFormComponent {
  private readonly classesService = inject(ClassesService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  private classId?: number;

  readonly form = this.fb.nonNullable.group({
    name: ['', Validators.required],
    numeric_order: ['', Validators.required],
    description: [''],
    status: ['active']
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.classId = id;
      this.loadClass(id);
    }
  }

  loadClass(id: number) {
    this.loading.set(true);
    this.classesService.getById(id).subscribe({
      next: (clazz) => {
        this.form.patchValue({
          name: clazz.name,
          numeric_order: String(clazz.numeric_order ?? ''),
          description: clazz.description ?? '',
          status: clazz.status ?? 'active'
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load class.');
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
      name: raw.name,
      numeric_order: Number(raw.numeric_order),
      description: raw.description || undefined,
      status: raw.status
    };

    if (this.isEdit() && this.classId) {
      this.classesService.update(this.classId, payload).subscribe({
        next: () => {
          this.loading.set(false);
          this.router.navigate(['/classes']);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to update class.');
        }
      });
      return;
    }

    this.classesService.create(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/classes']);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to create class.');
      }
    });
  }
}
