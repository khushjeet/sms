import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { NgIf, NgFor } from '@angular/common';
import { SectionsService } from '../../core/services/sections.service';
import { ClassesService } from '../../core/services/classes.service';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { ClassModel } from '../../models/class';
import { AcademicYear } from '../../models/academic-year';

@Component({
  selector: 'app-section-form',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor],
  templateUrl: './section-form.component.html',
  styleUrl: './section-form.component.scss'
})
export class SectionFormComponent {
  private readonly sectionsService = inject(SectionsService);
  private readonly classesService = inject(ClassesService);
  private readonly academicYearsService = inject(AcademicYearsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly isEdit = signal(false);
  private sectionId?: number;

  readonly classes = signal<ClassModel[]>([]);
  readonly academicYears = signal<AcademicYear[]>([]);

  readonly form = this.fb.nonNullable.group({
    class_id: ['', Validators.required],
    academic_year_id: ['', Validators.required],
    name: ['', Validators.required],
    capacity: [''],
    class_teacher_id: [''],
    room_number: [''],
    status: ['active']
  });

  ngOnInit() {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (id) {
      this.isEdit.set(true);
      this.sectionId = id;
      this.disableImmutableFields();
      this.loadSection(id);
    }

    this.loadReferenceData();
  }

  disableImmutableFields() {
    ['class_id', 'academic_year_id'].forEach((field) => this.form.get(field)?.disable());
  }

  loadReferenceData() {
    this.classesService.list({ per_page: 100 }).subscribe({
      next: (response) => this.classes.set(response.data)
    });
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => this.academicYears.set(response.data)
    });
  }

  loadSection(id: number) {
    this.loading.set(true);
    this.sectionsService.getById(id).subscribe({
      next: (section) => {
        this.form.patchValue({
          class_id: String(section.class?.id ?? ''),
          academic_year_id: String(section.academicYear?.id ?? ''),
          name: section.name,
          capacity: section.capacity ? String(section.capacity) : '',
          room_number: section.room_number ?? '',
          status: section.status ?? 'active'
        });
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Unable to load section.');
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
    const payload = {
      class_id: Number(raw.class_id),
      academic_year_id: Number(raw.academic_year_id),
      name: raw.name,
      capacity: raw.capacity ? Number(raw.capacity) : undefined,
      class_teacher_id: raw.class_teacher_id ? Number(raw.class_teacher_id) : undefined,
      room_number: raw.room_number || undefined,
      status: raw.status
    };

    if (this.isEdit() && this.sectionId) {
      const updatePayload = {
        name: payload.name,
        capacity: payload.capacity,
        class_teacher_id: payload.class_teacher_id,
        room_number: payload.room_number,
        status: payload.status
      };
      this.sectionsService.update(this.sectionId, updatePayload).subscribe({
        next: () => {
          this.loading.set(false);
          this.router.navigate(['/sections']);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to update section.');
        }
      });
      return;
    }

    this.sectionsService.create(payload).subscribe({
      next: () => {
        this.loading.set(false);
        this.router.navigate(['/sections']);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to create section.');
      }
    });
  }
}
