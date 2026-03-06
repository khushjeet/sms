import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgFor, NgIf } from '@angular/common';
import { Subject } from '../../models/subject';
import { SubjectsService } from '../../core/services/subjects.service';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-subjects-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor],
  templateUrl: './subjects-list.component.html',
  styleUrl: './subjects-list.component.scss'
})
export class SubjectsListComponent {
  private readonly subjectsService = inject(SubjectsService);
  private readonly auth = inject(AuthService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly subjects = signal<Subject[]>([]);
  readonly search = signal('');
  readonly statusFilter = signal('');
  readonly typeFilter = signal('');
  readonly isTeacher = computed(() => this.auth.user()?.role === 'teacher');
  readonly canManage = computed(() => ['super_admin', 'school_admin'].includes(this.auth.user()?.role || ''));

  ngOnInit() {
    this.load();
  }

  load() {
    this.loading.set(true);
    this.error.set(null);

    this.subjectsService
      .list({
        per_page: 200,
        search: this.search() || undefined,
        status: this.statusFilter() || undefined,
        type: this.typeFilter() || undefined
      })
      .subscribe({
        next: (response) => {
          this.subjects.set(response.data);
          this.loading.set(false);
        },
        error: (err) => {
          this.loading.set(false);
          this.error.set(err?.error?.message || 'Unable to load subjects.');
        }
      });
  }

  onSearchInput(value: string) {
    this.search.set(value);
  }

  onStatusChange(value: string) {
    this.statusFilter.set(value);
    this.load();
  }

  onTypeChange(value: string) {
    this.typeFilter.set(value);
    this.load();
  }

  deleteSubject(id: number) {
    if (!confirm('Delete this subject?')) {
      return;
    }

    this.subjectsService.delete(id).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to delete subject.')
    });
  }
}
