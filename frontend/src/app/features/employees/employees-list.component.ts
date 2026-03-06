import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule } from '@angular/forms';
import { NgFor, NgIf } from '@angular/common';
import { RouterLink } from '@angular/router';
import { Employee, EmployeeMetadata } from '../../models/employee';
import { EmployeesService } from '../../core/services/employees.service';

@Component({
  selector: 'app-employees-list',
  standalone: true,
  imports: [ReactiveFormsModule, NgIf, NgFor, RouterLink],
  templateUrl: './employees-list.component.html',
  styleUrl: './employees-list.component.scss'
})
export class EmployeesListComponent {
  private readonly employeesService = inject(EmployeesService);
  private readonly fb = inject(FormBuilder);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly employees = signal<Employee[]>([]);
  readonly pagination = signal({ current_page: 1, last_page: 1, total: 0 });
  readonly metadata = signal<EmployeeMetadata | null>(null);

  readonly filters = this.fb.nonNullable.group({
    search: [''],
    status: [''],
    role: [''],
    employee_type: ['']
  });

  ngOnInit() {
    this.employeesService.metadata().subscribe({
      next: (meta) => this.metadata.set(meta)
    });
    this.load();
  }

  load(page = 1) {
    this.loading.set(true);
    this.error.set(null);
    const { search, status, role, employee_type } = this.filters.getRawValue();
    this.employeesService.list({
      search: search || undefined,
      status: status || undefined,
      role: role || undefined,
      employee_type: employee_type || undefined,
      page
    }).subscribe({
      next: (response) => {
        this.employees.set(response.data);
        this.pagination.set({
          current_page: response.current_page,
          last_page: response.last_page,
          total: response.total
        });
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load employees.');
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

  employeeName(employee: Employee) {
    const user = employee.user as any;
    return user?.full_name || `${user?.first_name ?? ''} ${user?.last_name ?? ''}`.trim() || '-';
  }
}

