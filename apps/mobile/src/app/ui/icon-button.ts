import { Component, booleanAttribute, input } from '@angular/core';
import { MfIcon, type LucideIconData } from './icon';

/**
 * A round button that is only an icon: the app bar's actions, a row's menu.
 *
 * The label is required, because an icon-only button with no name is a button
 * a screen reader announces as "button" — and the compiler is the only place
 * that mistake can be stopped before it ships.
 *
 * `badge` puts a dot on it: something waiting behind this action.
 */
@Component({
  selector: 'button[mfIconButton], a[mfIconButton]',
  imports: [MfIcon],
  template: `
    <mf-icon [icon]="icon()" [size]="size() === 'sm' ? 'sm' : 'md'" />
    @if (badge()) {
      <span class="dot" aria-hidden="true"></span>
    }
  `,
  host: {
    '[attr.aria-label]': 'label()',
    '[attr.title]': 'label()',
    '[class.tonal]': 'tone() === "tonal"',
    '[class.over]': 'tone() === "over"',
    '[class.small]': 'size() === "sm"',
  },
  styles: `
    :host {
      position: relative;
      display: inline-grid;
      place-items: center;
      width: 40px;
      height: 40px;
      /* The tap target is the full 48px even where the circle is 40. */
      margin: 4px;
      padding: 0;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--text);
      cursor: pointer;
      text-decoration: none;
      transition:
        background-color 120ms ease,
        transform 90ms ease;
    }

    :host::before {
      content: '';
      position: absolute;
      inset: -4px;
    }

    :host(:active) {
      background: var(--surface-hover);
      transform: scale(0.94);
    }

    :host(:focus-visible) {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }

    :host([disabled]) {
      opacity: 0.4;
      pointer-events: none;
    }

    /* On a filled chip: for a bar sitting over a photograph. */
    :host(.tonal) {
      background: var(--surface-inset);
    }

    :host(.over) {
      background: rgb(8 12 9 / 0.52);
      color: var(--color-neutral-0);
      backdrop-filter: blur(12px) saturate(1.4);
    }

    :host(.small) {
      width: 32px;
      height: 32px;
      margin: 8px;
    }

    .dot {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 8px;
      height: 8px;
      border-radius: var(--radius-full);
      background: var(--danger);
      box-shadow: 0 0 0 2px var(--surface);
    }
  `,
})
export class MfIconButton {
  readonly icon = input.required<LucideIconData>();
  readonly label = input.required<string>();
  readonly tone = input<'plain' | 'tonal' | 'over'>('plain');
  readonly size = input<'sm' | 'md'>('md');
  readonly badge = input(false, { transform: booleanAttribute });
}
