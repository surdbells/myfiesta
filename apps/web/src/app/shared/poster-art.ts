import { Component, computed, input } from '@angular/core';
import { LucideAngularModule } from 'lucide-angular';
import { categoryGround, categoryIcon } from './category-art';

/**
 * The picture for a night nobody has uploaded a poster for yet.
 *
 * Plenty of organizers publish before their artwork is ready. A grey box
 * reads as an image that failed to load; this reads as a decision — the
 * category's own ground and mark, the night's initial large across it.
 * Decorative, so hidden from assistive technology: the title is written
 * beside it anyway.
 */
@Component({
  selector: 'app-poster-art',
  imports: [LucideAngularModule],
  host: { 'aria-hidden': 'true', class: 'block h-full w-full' },
  template: `
    <span class="relative grid h-full w-full place-items-center overflow-hidden" [style.background]="ground()">
      <lucide-icon
        class="pointer-events-none absolute -right-[6%] -bottom-[12%] text-neutral-0 opacity-15"
        [img]="icon()"
        [size]="glyph()"
        [strokeWidth]="1.25"
      />
      @if (initial()) {
        <span class="figure relative text-[clamp(2.5rem,9vw,4.5rem)] text-neutral-0 opacity-90">{{ initial() }}</span>
      }
    </span>
  `,
})
export class PosterArt {
  readonly title = input<string>('');
  readonly category = input<string | null>(null);
  /** Leave the letter out, where the words already sit on the picture. */
  readonly letter = input(true);
  /** How large the faint mark is drawn, in pixels. */
  readonly glyph = input(160);

  readonly ground = computed(() => categoryGround(this.category() ?? this.title()));
  readonly icon = computed(() => categoryIcon(this.category()));
  readonly initial = computed(() => (this.letter() ? this.title().trim().charAt(0).toUpperCase() : ''));
}
