import { Component, input } from '@angular/core';

/**
 * A surface with a title and somewhere to put actions.
 *
 * Exists because "a bordered box with a heading" was being re-declared in nine
 * screens with nine slightly different paddings. The header is optional: a card
 * that is only a container should not be forced to invent a title.
 */
@Component({
  selector: 'ui-card',
  template: `
    @if (heading() || subtitle()) {
      <header class="card__head">
        <div>
          @if (heading()) {
            <h2 class="card__title">{{ heading() }}</h2>
          }
          @if (subtitle()) {
            <p class="card__sub">{{ subtitle() }}</p>
          }
        </div>
        <div class="card__actions"><ng-content select="[cardActions]" /></div>
      </header>
    }

    <div class="card__body" [class.card__body--flush]="flush()">
      <ng-content />
    </div>
  `,
  styles: `
    :host {
      display: block;
      background-color: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-card);
    }
    .card__head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: var(--space-4);
      flex-wrap: wrap;
      padding: var(--space-5) var(--space-5) 0;
    }
    .card__title {
      margin: 0;
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
      letter-spacing: var(--font-tracking-tight);
    }
    .card__sub {
      margin: var(--space-1) 0 0;
      max-width: 60ch;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }
    .card__actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }
    .card__body { padding: var(--space-5); }
    /* For a card whose content is its own edge-to-edge surface — a table, a
       list of rows — where the card's padding would double the row's. */
    .card__body--flush { padding: 0; }
  `,
})
export class UiCard {
  readonly heading = input<string | null>(null);
  readonly subtitle = input<string | null>(null);
  readonly flush = input(false);
}
