import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf, NgFor } from '@angular/common';
import { SectionsService } from '../../core/services/sections.service';
import { Section } from '../../models/section';

@Component({
  selector: 'app-sections-list',
  standalone: true,
  imports: [RouterLink, NgIf, NgFor],
  templateUrl: './sections-list.component.html',
  styleUrl: './sections-list.component.scss'
})
export class SectionsListComponent {
  private readonly sectionsService = inject(SectionsService);

  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly sections = signal<Section[]>([]);

  ngOnInit() {
    this.load();
  }

  load() {
    this.loading.set(true);
    this.sectionsService.list({ per_page: 200 }).subscribe({
      next: (response) => {
        this.sections.set(response.data);
        this.loading.set(false);
      },
      error: (err) => {
        this.loading.set(false);
        this.error.set(err?.error?.message || 'Unable to load sections.');
      }
    });
  }

  deleteSection(id: number) {
    if (!confirm('Delete this section?')) {
      return;
    }
    this.sectionsService.delete(id).subscribe({
      next: () => this.load(),
      error: (err) => this.error.set(err?.error?.message || 'Unable to delete section.')
    });
  }
}
