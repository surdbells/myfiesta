import { Component, computed, input } from '@angular/core';
import { LucideAngularModule, type LucideIconData } from 'lucide-angular';

export type { LucideIconData };

export type MfIconSize = 'sm' | 'md' | 'lg' | 'xl';

/**
 * A Lucide icon, sized and labelled the same way everywhere.
 *
 * The same rules as the web kit's ui-icon, so the three apps draw the same
 * glyphs at the same weights: four named sizes rather than a pixel count at
 * every call site, and hidden from a screen reader unless the icon is the only
 * thing in its control — in which case it takes a `label` and says so.
 *
 * `bold` is for the one place an icon has to say "you are here" without a
 * second colour: the active tab.
 */
@Component({
  selector: 'mf-icon',
  imports: [LucideAngularModule],
  template: `
    <lucide-icon
      [img]="icon()"
      [size]="pixels()"
      [strokeWidth]="stroke()"
      [attr.aria-hidden]="label() ? null : 'true'"
      [attr.role]="label() ? 'img' : null"
      [attr.aria-label]="label()"
    />
  `,
  styles: `
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      color: currentColor;
    }
  `,
})
export class MfIcon {
  readonly icon = input.required<LucideIconData>();
  readonly size = input<MfIconSize>('md');
  readonly label = input<string | null>(null);
  readonly bold = input(false);

  readonly pixels = computed(() => ({ sm: 16, md: 20, lg: 24, xl: 28 })[this.size()]);

  readonly stroke = computed(() => (this.bold() ? 2.35 : this.size() === 'sm' ? 1.85 : 1.75));
}
