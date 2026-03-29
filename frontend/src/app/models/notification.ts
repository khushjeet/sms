import { PaginatedResponse } from './pagination';

export interface AppNotification {
  id: number;
  title: string;
  message: string;
  type: string;
  priority: 'normal' | 'important' | 'urgent' | 'action_required' | string;
  entity_type: string | null;
  entity_id: number | null;
  action_target: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string | null;
  meta?: Record<string, unknown> | null;
}

export interface NotificationUnreadCount {
  total: number;
  by_type: Record<string, number>;
}

export interface RecentNotificationsResponse {
  data: AppNotification[];
}

export type NotificationsPage = PaginatedResponse<AppNotification>;
