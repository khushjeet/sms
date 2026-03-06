import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { AcademicYearsService } from '../../core/services/academic-years.service';
import { AcademicYear } from '../../models/academic-year';

@Component({
  selector: 'app-academic-years-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor],
  templateUrl: './academic-years-list.component.html',
  styleUrl: './academic-years-list.component.scss'
})
export class AcademicYearsListComponent {
  private readonly academicYearsService = inject(AcademicYearsService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly years = signal<AcademicYear[]>([]);

  ngOnInit() {
    this.load();
  }

  load() {
    this.loading.set(true);
    this.academicYearsService.list({ per_page: 100 }).subscribe({
      next: (response) => {
        this.years.set(response.data);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load academic years.');
      }
    });
  }

  setCurrent(id: number) {
    this.academicYearsService.setCurrent(id).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to set current year.')
    });
  }

  closeYear(id: number) {
    if (!confirm('Close this academic year? This will lock enrollments.')) {
      return;
    }
    this.academicYearsService.close(id, {}).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to close academic year.')
    });
  }

  deleteYear(id: number) {
    if (!confirm('Delete this academic year?')) {
      return;
    }
    this.academicYearsService.delete(id).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to delete academic year.')
    });
  }
}
