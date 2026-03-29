export interface AuthUser {
  id: number;
  email: string;
  role: 'super_admin' | 'school_admin' | 'accountant' | 'teacher' | 'parent' | 'student' | 'staff' | 'hr' | 'principal';
  first_name: string;
  last_name: string;
  phone?: string | null;
  avatar?: string | null;
  avatar_url?: string | null;
  full_name?: string;
  status: 'active' | 'inactive' | 'suspended' | string;
}

export interface LoginResponse {
  token: string;
  token_type: 'Bearer' | string;
  expires_at: string;
  user: AuthUser;
}

export interface AuthSession {
  token: string;
  expires_at: string;
  user: AuthUser;
}
