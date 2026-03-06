import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { ClassesService } from '../../core/services/classes.service';
import { ClassModel } from '../../models/class';

@Component({
  selector: 'app-classes-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor],
  templateUrl: './classes-list.component.html',
  styleUrl: './classes-list.component.scss'
})
export class ClassesListComponent {
  private readonly classesService = inject(ClassesService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly classes = signal<ClassModel[]>([]);

  ngOnInit() {
    this.load();
  }

  load() {
    this.loading.set(true);
    this.classesService.list({ per_page: 100 }).subscribe({
      next: (response) => {
        this.classes.set(response.data);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load classes.');
      }
    });
  }

  deleteClass(id: number) {
    if (!confirm('Delete this class?')) {
      return;
    }
    this.classesService.delete(id).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to delete class.')
    });
  }
}
