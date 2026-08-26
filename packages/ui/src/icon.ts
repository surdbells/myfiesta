import { Component, computed, input } from '@angular/core';
import { LucideAngularModule, type LucideIconData } from 'lucide-angular';

export type { LucideIconData };

/** Three sizes, tied to the type scale rather than chosen per call site. */
export type IconSize = 'sm' | 'md' | 'lg';

/**
 * A Lucide icon, sized and labelled the same way everywhere.
 *
 * Lucide's own component is perfectly good and this wraps it for two reasons,
 * both of which are things that go wrong when every screen calls it directly.
 *
 * Size. An icon set is only tidy if the icons are the same size as each other
 * and the right size against the text beside them. Three named sizes, matched
 * to the type scale, is a smaller decision than a pixel count at every call
 * site — and a `size="18"` somewhere is what makes a row of icons look
 * hand-assembled.
 *
 * Labelling. Almost every icon here sits next to a word that already says what
 * it means, and a screen reader announcing "calendar days, Events" is worse
 * than one announcing "Events". So icons are hidden from assistive technology
 * by default, and the exception is explicit: an icon that is the *only* thing
 * in a control takes a `label`, and gets `role="img"` with an accessible name.
 *
 * The rule that follows is the one worth remembering — if you find yourself
 * writing an icon with no label inside a button with no text, the button has
 * no name at all, and this component will not give it one silently.
 */
@Component({
  selector: 'ui-icon',
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
      /* Never squashed by a flex parent. An icon that has been compressed to
         14x20 by the label beside it is the most obvious tell that a set was
         dropped in rather than designed with. */
      flex-shrink: 0;
      /* Follows the text it sits beside unless something overrides it. */
      color: currentColor;
    }
  `,
})
export class UiIcon {
  readonly icon = input.required<LucideIconData>();

  readonly size = input<IconSize>('md');

  /**
   * An accessible name, for an icon that is the only content of a control.
   *
   * Leave it off when there is a visible label beside the icon — that label
   * is already the name, and a second one is read out twice.
   */
  readonly label = input<string | null>(null);

  readonly pixels = computed(() => ({ sm: 16, md: 20, lg: 24 })[this.size()]);

  /**
   * Slightly lighter than Lucide's default 2.
   *
   * At 16px a 2px stroke is a sixth of the glyph and reads heavier than the
   * text it labels; 1.75 sits with a medium font weight without going wiry.
   */
  readonly stroke = computed(() => (this.size() === 'sm' ? 1.85 : 1.75));
}
