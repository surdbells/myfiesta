import { Component, input } from '@angular/core';

/** Nothing here, and what to do about it. Never a bare "No results". */
@Component({
  selector: 'mf-empty',
  template: `
    <h2>{{ title() }}</h2>
    @if (hint()) {
      <p class="hint">{{ hint() }}</p>
    }
    <ng-content />
  `,
  styles: `
    :host {
      display: grid;
      justify-items: center;
      gap: var(--space-3);
      padding: var(--space-8) var(--space-5);
      text-align: center;
    }

    .hint {
      max-width: 34ch;
      color: var(--text-muted);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
    }
  `,
})
export class MfEmpty {
  readonly title = input.required<string>();
  readonly hint = input<string | null>(null);
}
