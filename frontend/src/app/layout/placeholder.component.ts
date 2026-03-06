import { Component, Input, inject } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

@Component({
  selector: 'app-placeholder',
  standalone: true,
  template: `
    <div class="message">
      <h2>{{ resolvedTitle }}</h2>
      <p>This module will be implemented step-by-step according to the SRS.</p>
    </div>
  `,
  styles: [
    `
      .message {
        padding: 32px;
        border-radius: 14px;
        border: 1px dashed #94a3b8;
        background: #f8fafc;
      }
    `
  ]
})
export class PlaceholderComponent {
  private readonly route = inject(ActivatedRoute);

  @Input() title = 'Module';

  get resolvedTitle(): string {
    return (this.route.snapshot.data['title'] as string | undefined) || this.title;
  }
}
