import { Component, booleanAttribute, input, output } from '@angular/core';

/**
 * The frame every screen sits in: a header that stays, a body that scrolls.
 *
 * Not ion-header / ion-toolbar. Those carry iOS and Material chrome, a
 * platform-dependent title alignment and a height this app does not want; what
 * is actually needed is a strip that respects the notch and gets out of the
 * way.
 *
 * The header keeps its own background rather than being transparent, so a
 * ticket QR scrolling under it does not turn the title unreadable.
 */
@Component({
  selector: 'mf-screen',
  template: `
    <header class="bar">
      @if (back()) {
        <button class="icon" type="button" aria-label="Back" (click)="backed.emit()">
          <span class="chevron" aria-hidden="true"></span>
        </button>
      }

      <div class="titles">
        <h1>{{ title() }}</h1>
        @if (subtitle()) {
          <p class="sub">{{ subtitle() }}</p>
        }
      </div>

      <div class="actions">
        <ng-content select="[screenActions]" />
      </div>
    </header>

    <main class="body" [class.flush]="flush()">
      <ng-content />
    </main>
  `,
  styles: `
    :host {
      display: grid;
      /* minmax(0, ...) on the column as well as the row.
         A grid track is max-content by default, so without this the whole
         screen takes the width of its widest child — one header button too
         many and the frame is wider than the phone, with every screen under
         it shifted sideways. */
      grid-template-columns: minmax(0, 1fr);
      grid-template-rows: auto minmax(0, 1fr);
      width: 100%;
      height: 100%;
      background: var(--surface-sunken);
    }

    .bar {
      display: flex;
      min-width: 0;
      align-items: center;
      gap: var(--space-3);
      padding: calc(var(--mf-safe-top) + var(--space-4)) var(--space-5) var(--space-3);
      background: var(--surface);
      border-bottom: 1px solid var(--border-subtle);
    }

    .titles {
      flex: 1;
      min-width: 0;
    }

    h1 {
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-bold);
      letter-spacing: var(--font-tracking-tight);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .actions {
      display: flex;
      align-items: center;
      gap: var(--space-2);
    }

    .icon {
      display: grid;
      place-items: center;
      width: var(--mf-tap);
      height: var(--mf-tap);
      margin-left: calc(var(--space-3) * -1);
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--text);
      cursor: pointer;
    }

    .icon:active {
      background: var(--surface-hover);
    }

    .chevron {
      width: 0.6rem;
      height: 0.6rem;
      border-left: 2px solid currentColor;
      border-bottom: 2px solid currentColor;
      transform: rotate(45deg) translate(2px, -2px);
    }

    .body {
      overflow-y: auto;
      overscroll-behavior-y: contain;
      padding: var(--space-5);
      /* Room under the last card for the home indicator and a thumb. */
      padding-bottom: calc(var(--mf-safe-bottom) + var(--space-8));
    }

    .body.flush {
      padding: 0 0 calc(var(--mf-safe-bottom) + var(--space-6));
    }
  `,
})
export class MfScreen {
  readonly title = input.required<string>();
  readonly subtitle = input<string | null>(null);
  readonly back = input(false, { transform: booleanAttribute });

  /** No padding on the body: for full-bleed lists and the camera. */
  readonly flush = input(false, { transform: booleanAttribute });

  readonly backed = output<void>();
}
