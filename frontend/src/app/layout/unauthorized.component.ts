import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-unauthorized',
  standalone: true,
  imports: [RouterLink],
  template: `
    <div class="message">
      <h2>Access denied</h2>
      <p>You do not have permission to view this page.</p>
      <a routerLink="/dashboard">Return to dashboard</a>
    </div>
  `,
  styles: [
    `
      .message {
        padding: 40px;
        background: #fff7ed;
        border-radius: 14px;
        border: 1px solid #fdba74;
      }
      a {
        color: #c2410c;
        font-weight: 600;
      }
    `
  ]
})
export class UnauthorizedComponent {}
