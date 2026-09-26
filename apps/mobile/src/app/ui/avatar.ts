import { Component, computed, input } from '@angular/core';

/**
 * A person, or an organization, as a circle.
 *
 * The picture when there is one; otherwise their initials on a tint picked
 * from their name — the same name is always the same colour, so a list of a
 * team reads as people rather than as a column of grey dots.
 */
@Component({
  selector: 'mf-avatar',
  template: `
    @if (src()) {
      <img [src]="src()" alt="" />
    } @else {
      <span class="initials" [style.background]="tint()">{{ initials() }}</span>
    }
  `,
  host: {
    '[style.width.px]': 'size()',
    '[style.height.px]': 'size()',
    '[style.font-size.px]': 'size() * 0.38',
    '[attr.aria-hidden]': '"true"',
  },
  styles: `
    :host {
      display: inline-grid;
      flex: none;
      border-radius: var(--radius-full);
      overflow: hidden;
      background: var(--surface-inset);
    }

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .initials {
      display: grid;
      place-items: center;
      width: 100%;
      height: 100%;
      color: var(--text);
      font-weight: var(--font-weight-semibold);
      letter-spacing: 0.02em;
    }
  `,
})
export class MfAvatar {
  readonly name = input('');
  readonly src = input<string | null>(null);
  readonly size = input(40);

  protected readonly initials = computed(() => {
    const words = this.name().trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) return '?';

    return (words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase();
  });

  /** One of a few soft tints, chosen by the name so it never changes. */
  protected readonly tint = computed(() => {
    const tints = [
      'var(--primary-soft)',
      'var(--accent-soft)',
      'color-mix(in srgb, var(--info) 18%, transparent)',
      'color-mix(in srgb, var(--warning) 18%, transparent)',
      'color-mix(in srgb, var(--success) 18%, transparent)',
    ];
    let hash = 0;

    for (const letter of this.name()) hash = (hash * 31 + letter.charCodeAt(0)) >>> 0;

    return tints[hash % tints.length];
  });
}
