import { Component, DestroyRef, inject, input, model, output } from '@angular/core';
import { Search, X } from 'lucide-angular';
import { MfIcon } from './icon';

/**
 * A search box for the top of a list.
 *
 * A pill with the magnifier inside and a clear button once something is
 * typed. It reports `searched` a moment after typing stops, so a screen asks
 * the server once per pause rather than once per letter — a guest list of four
 * thousand names is not searched twelve times for "Adebayo".
 */
@Component({
  selector: 'mf-search',
  imports: [MfIcon],
  template: `
    <label class="box">
      <mf-icon class="glass" [icon]="glass" size="sm" />
      <input
        type="search"
        inputmode="search"
        enterkeyhint="search"
        autocomplete="off"
        autocapitalize="off"
        spellcheck="false"
        [placeholder]="placeholder()"
        [attr.aria-label]="placeholder()"
        [value]="value()"
        (input)="typed($any($event.target).value)"
        (keydown.enter)="now()"
      />
      @if (value()) {
        <button type="button" class="clear" aria-label="Clear search" (click)="clear()">
          <mf-icon [icon]="cross" size="sm" />
        </button>
      }
    </label>
  `,
  styles: `
    :host {
      display: block;
    }

    .box {
      display: flex;
      align-items: center;
      gap: var(--space-2);
      height: 44px;
      padding: 0 var(--space-2) 0 var(--space-4);
      border-radius: var(--radius-full);
      background: var(--surface-inset);
      box-shadow: inset 0 0 0 1px var(--border);
      transition: box-shadow 120ms ease;
    }

    .box:focus-within {
      background: var(--surface-raised);
      box-shadow:
        inset 0 0 0 2px var(--primary),
        var(--focus-ring);
    }

    .glass {
      color: var(--text-subtle);
    }

    input {
      flex: 1;
      min-width: 0;
      border: 0;
      outline: none;
      background: transparent;
      color: var(--text);
      font: inherit;
      font-size: var(--font-size-base);
    }

    input::placeholder {
      color: var(--text-subtle);
    }

    /* The browser's own clear button, which this replaces. */
    input::-webkit-search-cancel-button {
      display: none;
    }

    .clear {
      display: grid;
      place-items: center;
      width: 32px;
      height: 32px;
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-hover);
      color: var(--text-muted);
      cursor: pointer;
    }
  `,
})
export class MfSearch {
  readonly placeholder = input('Search');
  /** How long typing has to stop before `searched` fires. */
  readonly wait = input(280);

  readonly value = model('');
  readonly searched = output<string>();

  protected readonly glass = Search;
  protected readonly cross = X;

  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.timer && clearTimeout(this.timer));
  }

  protected typed(text: string): void {
    this.value.set(text);

    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.now(), this.wait());
  }

  protected now(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    this.searched.emit(this.value().trim());
  }

  protected clear(): void {
    this.value.set('');
    this.now();
  }
}
