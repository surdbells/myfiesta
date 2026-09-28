import { Component, input, output } from '@angular/core';
import { CircleAlert, RotateCw } from 'lucide-angular';
import { UiIcon } from './icon';
import { UiButton } from './button';

/**
 * A screen, or a section of one, that failed to load.
 *
 * Separate from the empty state on purpose. "No attendees yet" and "we could
 * not reach the server" look similar and mean opposite things — one is a
 * normal state of a new event, the other is a fault — and showing the empty
 * illustration for a failed request has told an organizer their sold-out show
 * has nobody coming.
 *
 * Retry is the point of the component. An error with no way forward is a dead
 * end that leaves reloading the whole page as the only option.
 */
@Component({
  selector: 'ui-error-state',
  imports: [UiButton, UiIcon],
  template: `
    <div class="err" role="alert">
      <span class="err__mark"><ui-icon [icon]="markIcon" /></span>

      <div class="err__body">
        <p class="err__title">{{ title() }}</p>
        <p class="err__detail">{{ detail() }}</p>

        @if (reference()) {
          <!-- Given so somebody can quote it to support. Selectable, and in a
               monospace face so it can be read aloud over a phone. -->
          <p class="err__ref">Reference <code>{{ reference() }}</code></p>
        }
      </div>

      <button uiButton variant="secondary" size="sm" type="button" (click)="retried.emit()">
        <ui-icon [icon]="retryIcon" size="sm" />
        Try again
      </button>
    </div>
  `,
  styles: `
    .err {
      display: flex;
      align-items: flex-start;
      gap: var(--space-4);
      padding: var(--space-5);
      background-color: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-card);
    }
    .err__mark {
      display: grid;
      place-items: center;
      flex-shrink: 0;
      width: 2rem;
      height: 2rem;
      color: var(--danger-text);
      background-color: color-mix(in srgb, var(--danger) 12%, transparent);
      border-radius: var(--radius-full);
    }
    .err__body { flex: 1; min-width: 0; display: grid; gap: var(--space-1); }
    .err__title { margin: 0; font-weight: var(--font-weight-medium); }
    .err__detail { margin: 0; font-size: var(--font-size-sm); color: var(--text-muted); }
    .err__ref { margin: var(--space-2) 0 0; font-size: var(--font-size-xs); color: var(--text-subtle); }
    .err__ref code { font-family: var(--font-family-mono); user-select: all; }
    @media (max-width: 560px) {
      .err { flex-direction: column; }
    }
  `,
})
export class UiErrorState {
  protected readonly markIcon = CircleAlert;
  protected readonly retryIcon = RotateCw;

  readonly title = input('Something went wrong');

  /**
   * What went wrong, in a sentence somebody can act on.
   *
   * Not the exception message. "SQLSTATE[08006]" tells an organizer nothing;
   * the caller is expected to translate.
   */
  readonly detail = input('We could not load this. It is usually temporary.');

  /** A request id, if the API returned one. */
  readonly reference = input<string | null>(null);

  readonly retried = output<void>();
}
