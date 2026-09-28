import { Component, computed, input } from '@angular/core';
import { UiIcon } from '@myfiesta/ui';
import { Flame } from 'lucide-angular';
import { type Availability, availabilityBadge } from '@myfiesta/shared/availability';

/**
 * "Almost sold out", "Only 4 left", "Sold out" — or nothing.
 *
 * The words come from the shared rule (packages/shared/availability) so the
 * site and the phone say the same thing about the same tier; this only draws
 * them. A solid fill with its own label colour, from the scarce and sold
 * tokens, because the same badge sits on a poster of any colour and on a
 * card in either theme, and has to read on all of them.
 *
 * Nothing at all when there is nothing to say: a card that says "Available"
 * on every night says nothing on any of them.
 *
 * Its own face and tracking, because it is set inside headings — beside a
 * ticket's name — whose display face and tight tracking squeeze a pill of
 * small words into a smudge.
 */
@Component({
  selector: 'app-availability',
  imports: [UiIcon],
  template: `
    @if (badge(); as shown) {
      <span
        class="availability inline-flex items-center gap-1 whitespace-nowrap rounded-full font-sans font-semibold normal-case leading-none tracking-normal shadow-(--shadow-card)"
        [class]="
          (shown.tone === 'sold' ? 'bg-sold text-on-sold' : 'bg-scarce text-on-scarce') +
          (size() === 'sm' ? ' px-2.5 py-1.5 text-xs' : ' px-3 py-1.5 text-sm')
        "
        [attr.data-tone]="shown.tone"
      >
        @if (shown.tone === 'scarce') {
          <ui-icon [icon]="flameIcon" size="sm" class="-ml-0.5 scale-[0.85]" />
        }
        {{ shown.label }}
      </span>
    }
  `,
})
export class AvailabilityBadge {
  readonly value = input<Availability | null | undefined>(null);

  /** Name the count where there is one: beside a ticket, not on a card. */
  readonly exact = input(false);

  readonly size = input<'sm' | 'md'>('sm');

  protected readonly flameIcon = Flame;

  readonly badge = computed(() => availabilityBadge(this.value(), { exact: this.exact() }));
}
