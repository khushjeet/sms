import { Injectable, signal } from '@angular/core';

type StudentTheme = 'light' | 'dark';

@Injectable({ providedIn: 'root' })
export class StudentThemeService {
  private readonly storageKey = 'student-portal-theme';
  readonly theme = signal<StudentTheme>(this.getInitialTheme());

  isDark(): boolean {
    return this.theme() === 'dark';
  }

  toggleTheme(): void {
    const nextTheme: StudentTheme = this.isDark() ? 'light' : 'dark';
    this.theme.set(nextTheme);
    this.persistTheme(nextTheme);
  }

  private getInitialTheme(): StudentTheme {
    if (typeof window === 'undefined') {
      return 'light';
    }

    const storedTheme = window.localStorage.getItem(this.storageKey);
    if (storedTheme === 'light' || storedTheme === 'dark') {
      return storedTheme;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  private persistTheme(theme: StudentTheme): void {
    if (typeof window === 'undefined') {
      return;
    }

    window.localStorage.setItem(this.storageKey, theme);
  }
}
