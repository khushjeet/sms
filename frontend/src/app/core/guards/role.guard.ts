import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

export const roleGuard: CanActivateFn = (route) => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const roles = (route.data['roles'] as string[] | undefined) ?? [];

  if (!auth.isAuthenticated()) {
    return router.parseUrl('/login');
  }

  if (roles.length === 0) {
    return true;
  }

  const user = auth.user();
  if (user && roles.includes(user.role)) {
    return true;
  }

  return router.parseUrl('/unauthorized');
};
