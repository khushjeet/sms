import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-not-found',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div class="message">
      <h2>Page not found</h2>
      <p>The page you are looking for does not exist.</p>
      <a routerLink="/dashboard">Return to dashboard</a>
    </div>
  `,
  styles: [
    `
      .message {
        padding: 40px;
        background: #f1f5f9;
        border-radius: 14px;
        border: 1px solid #cbd5f5;
      }
      a {
        color: #2563eb;
        font-weight: 600;
      }
    `
  ]
})
export class NotFoundComponent {}
