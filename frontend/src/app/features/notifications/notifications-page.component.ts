import { DatePipe, NgFor, NgIf } from '@angular/common';
import { Component, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { NotificationsService } from '../../core/services/notifications.service';
import { AppNotification } from '../../models/notification';

@Component({
  selector: 'app-notifications-page',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe],
  templateUrl: './notifications-page.component.html',
  styleUrl: './notifications-page.component.scss'
})
export class NotificationsPageComponent {
  private readonly notificationsService = inject(NotificationsService);
  private readonly router = inject(Router);

  readonly loading = signal(false);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly items = signal<AppNotification[]>([]);
  readonly status = signal<'all' | 'read' | 'unread'>('all');
  readonly pagination = signal({ current_page: 1, last_page: 1, per_page: 20, total: 0 });

  ngOnInit(): void {
    this.load();
  }

  load(page = 1): void {
    this.loading.set(true);
    this.error.set(null);

    this.notificationsService.list({
      page,
      per_page: this.pagination().per_page,
      status: this.status()
    }).subscribe({
      next: (response) => {
        this.items.set(response.data ?? []);
        this.pagination.set({
          current_page: response.current_page ?? 1,
          last_page: response.last_page ?? 1,
          per_page: response.per_page ?? 20,
          total: response.total ?? 0
        });
        this.loading.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Unable to load notifications.');
        this.loading.set(false);
      }
    });
  }

  setStatus(status: 'all' | 'read' | 'unread'): void {
    this.status.set(status);
    this.load(1);
  }

  open(item: AppNotification): void {
    this.busy.set(true);

    const finish = () => {
      this.busy.set(false);
      if (item.action_target) {
        this.router.navigateByUrl(item.action_target);
      }
    };

    if (item.is_read) {
      finish();
      return;
    }

    this.notificationsService.markRead(item.id).subscribe({
      next: () => {
        this.items.update((items) =>
          items.map((row) =>
            row.id === item.id
              ? { ...row, is_read: true, read_at: row.read_at ?? new Date().toISOString() }
              : row
          )
        );
        finish();
      },
      error: () => finish()
    });
  }

  markAllRead(): void {
    this.busy.set(true);
    this.notificationsService.markAllRead().subscribe({
      next: () => {
        this.items.update((items) =>
          items.map((item) => ({ ...item, is_read: true, read_at: item.read_at ?? new Date().toISOString() }))
        );
        this.busy.set(false);
      },
      error: (err) => {
        this.error.set(err?.error?.message || 'Unable to mark notifications as read.');
        this.busy.set(false);
      }
    });
  }

  previousPage(): void {
    const current = this.pagination().current_page;
    if (current > 1) {
      this.load(current - 1);
    }
  }

  nextPage(): void {
    const { current_page, last_page } = this.pagination();
    if (current_page < last_page) {
      this.load(current_page + 1);
    }
  }
}
