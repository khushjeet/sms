import { HttpClient, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { environment } from '../../../environments/environment';

@Injectable({
  providedIn: 'root'
})
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = environment.apiBaseUrl.replace(/\/$/, '');

  get<T>(path: string, params?: Record<string, string | number | boolean | null | undefined>) {
    return this.http.get<T>(this.resolve(path), { params: this.toParams(params) });
  }

  getText(path: string, params?: Record<string, string | number | boolean | null | undefined>) {
    return this.http.get(this.resolve(path), { params: this.toParams(params), responseType: 'text' });
  }

  getBlob(path: string, params?: Record<string, string | number | boolean | null | undefined>) {
    return this.http.get(this.resolve(path), { params: this.toParams(params), responseType: 'blob' });
  }

  post<T>(path: string, body: unknown) {
    return this.http.post<T>(this.resolve(path), body);
  }

  put<T>(path: string, body: unknown) {
    return this.http.put<T>(this.resolve(path), body);
  }

  delete<T>(path: string) {
    return this.http.delete<T>(this.resolve(path));
  }

  private resolve(path: string): string {
    if (path.startsWith('http://') || path.startsWith('https://')) {
      return path;
    }
    if (path.startsWith('/')) {
      return new URL(path, this.baseUrl).toString();
    }
    const trimmed = path.replace(/^\//, '');
    return `${this.baseUrl}/${trimmed}`;
  }

  private toParams(params?: Record<string, string | number | boolean | null | undefined>) {
    if (!params) {
      return undefined;
    }
    let httpParams = new HttpParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === null || value === undefined) {
        return;
      }
      httpParams = httpParams.set(key, String(value));
    });
    return httpParams;
  }
}
