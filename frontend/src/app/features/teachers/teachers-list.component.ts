import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { NgFor, NgIf } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Teacher } from '../../models/teacher';
import { TeachersService } from '../../core/services/teachers.service';

@Component({
  selector: 'app-teachers-list',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor, RouterLink],
  templateUrl: './teachers-list.component.html',
  styleUrl: './teachers-list.component.scss'
})
export class TeachersListComponent {
  private readonly teachersService = inject(TeachersService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly teachers = signal<Teacher[]>([]);
  readonly pagination = signal({ current_page: 1, last_page: 1, total: 0 });

  readonly filters = this.fb.nonNullable.group({
    search: [''],
    status: ['']
  });

  ngOnInit() {
    this.load();
  }

  load(page = 1) {
    this.loading.set(true);
    this.error.set(null);
    const { search, status } = this.filters.getRawValue();
    this.teachersService.list({
      search: search || undefined,
      status: status || undefined,
      page
    }).subscribe({
      next: (response) => {
        this.teachers.set(response.data);
        this.pagination.set({
          current_page: response.current_page,
          last_page: response.last_page,
          total: response.total
        });
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load teachers.');
      }
    });
  }

  applyFilters() {
    this.load(1);
  }

  previousPage() {
    const current = this.pagination().current_page;
    if (current > 1) {
      this.load(current - 1);
    }
  }

  nextPage() {
    const current = this.pagination().current_page;
    const last = this.pagination().last_page;
    if (current < last) {
      this.load(current + 1);
    }
  }

  teacherName(teacher: Teacher) {
    const user = teacher.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }
}

