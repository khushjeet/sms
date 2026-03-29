import { inject, Injectable, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { tap } from 'rxjs/operators';
import { ApiClient } from './api-client.service';
import {
  AppNotification,
  NotificationUnreadCount,
  NotificationsPage,
  RecentNotificationsResponse
} from '../../models/notification';

@Injectable({
  providedIn: 'root'
})
export class NotificationsService {
  private readonly api = inject(ApiClient);

  readonly unreadCount = signal<NotificationUnreadCount>({ total: 0, by_type: {} });
  readonly recentItems = signal<AppNotification[]>([]);

  list(params?: {
    page?: number;
    per_page?: number;
    type?: string;
    status?: 'read' | 'unread' | 'all';
  }) {
    return this.api.get<NotificationsPage>('notifications', params);
  }

  fetchUnreadCount() {
    return this.api.get<NotificationUnreadCount>('notifications/unread-count').pipe(
      tap((response) => this.unreadCount.set({
        total: response.total ?? 0,
        by_type: response.by_type ?? {}
      }))
    );
  }

  fetchRecent(limit = 5) {
    return this.api.get<RecentNotificationsResponse>('notifications/recent', { limit }).pipe(
      tap((response) => this.recentItems.set(response.data ?? []))
    );
  }

  refreshBellState(limit = 5) {
    return forkJoin({
      unread: this.fetchUnreadCount(),
      recent: this.fetchRecent(limit),
    });
  }

  markRead(id: number) {
    return this.api.post<{ message: string; data: AppNotification }>(`notifications/${id}/read`, {}).pipe(
      tap((response) => {
        const updated = response.data;
        this.recentItems.update((items) =>
          items.map((item) => item.id === updated.id ? updated : item)
        );
        this.unreadCount.update((state) => ({
          ...state,
          total: Math.max(0, state.total - 1),
        }));
      })
    );
  }

  markAllRead() {
    return this.api.post<{ message: string; updated_count: number }>('notifications/mark-all-read', {}).pipe(
      tap(() => {
        this.recentItems.update((items) =>
          items.map((item) => ({ ...item, is_read: true, read_at: item.read_at ?? new Date().toISOString() }))
        );
        this.unreadCount.set({ total: 0, by_type: {} });
      })
    );
  }
}
