import { Component, computed, inject, input, signal } from '@angular/core';
import { ToastStore, UiButton } from '@myfiesta/ui';
import { SITE_URL } from '../../core/site-url';

type Style = 'button' | 'inline';

/**
 * Selling this event from the organizer's own website.
 *
 * Two lines of HTML they paste wherever their site lets them. A button that
 * opens the tickets over their page is the default, because it fits every
 * layout and is still an ordinary link to the event if the script never
 * loads; the tickets laid into the page itself is the other choice, for a
 * page built around them.
 *
 * Nothing here needs a key or a setting: the script is public and only shows
 * what the event page already shows.
 */
@Component({
  selector: 'app-event-embed',
  imports: [UiButton],
  template: `
    <h2 class="section mb-3 text-base font-semibold">On your own site</h2>

    <div class="grid max-w-[44rem] gap-3">
      <p class="text-pretty text-sm text-text-muted">
        Paste this into your website and people buy without leaving it. Payment opens in its own tab; everything else
        happens on your page.
        @if (!published()) {
          It starts working once the event is on sale.
        }
      </p>

      <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="How it appears">
        @for (option of options; track option.value) {
          <button
            uiButton
            type="button"
            size="sm"
            role="radio"
            [variant]="style() === option.value ? 'soft' : 'ghost'"
            [attr.aria-checked]="style() === option.value"
            (click)="style.set(option.value); copied.set(false)"
          >
            {{ option.label }}
          </button>
        }
      </div>

      <pre class="m-0 overflow-x-auto rounded-md bg-surface-sunken px-3 py-2 font-mono text-xs leading-relaxed">{{ snippet() }}</pre>

      <div>
        <button uiButton type="button" variant="secondary" size="sm" (click)="copy()">{{ copied() ? 'Copied' : 'Copy code' }}</button>
      </div>
    </div>
  `,
})
export class EventEmbed {
  private readonly siteUrl = inject(SITE_URL);
  private readonly toasts = inject(ToastStore);

  readonly slug = input.required<string>();
  readonly published = input(false);

  readonly options: { value: Style; label: string }[] = [
    { value: 'button', label: 'A button' },
    { value: 'inline', label: 'In the page' },
  ];

  readonly style = signal<Style>('button');
  readonly copied = signal(false);

  readonly snippet = computed(() => {
    const script = `<script src="${this.siteUrl}/embed.js" async></script>`;
    const slug = this.slug();

    return this.style() === 'button'
      ? `<a href="${this.siteUrl}/${slug}" data-myfiesta-event="${slug}" data-myfiesta-mode="button">Buy tickets</a>\n${script}`
      : `<div data-myfiesta-event="${slug}"></div>\n${script}`;
  });

  async copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(this.snippet());
      this.copied.set(true);
    } catch {
      this.toasts.show('Copying is blocked here. Select the code and copy it by hand.', 'danger');
    }
  }
}
