import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { BadgeCheck, Share2 } from 'lucide-angular';
import { Api } from '../../core/api';
import { OrganizerPage } from '../../core/api.types';
import { Seo } from '../../core/seo';
import { EventCard } from '../../shared/event-card';

/**
 * An organizer's own page.
 *
 * Every platform an organizer here would otherwise be using has one, and we
 * did not: the only link they could hand out was for one night at a time, so a
 * promoter's Instagram bio pointed either at a party that has already happened
 * or at nothing. Somebody who liked the night they went to had nowhere to find
 * out what was next.
 *
 * `/o/{slug}`, alongside the event slugs at the root. Two segments, so it
 * cannot collide with an event called "o" and does not take a word out of the
 * namespace organizers name their events in.
 *
 * There is no follow button here, deliberately. This site has no accounts —
 * guest checkout is the primary path and even the heart on a card is a
 * bookmark in this browser — and a button that asked somebody to sign in to
 * something that does not exist would be worse than its absence. Following
 * lives in the app, where there is an account to hang it on.
 */
@Component({
  selector: 'mf-organizer',
  imports: [RouterLink, UiIcon, EventCard],
  templateUrl: './organizer.html',
})
export class Organizer {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);

  protected readonly verifiedIcon = BadgeCheck;
  protected readonly shareIcon = Share2;

  readonly organizer = signal<OrganizerPage | null>(null);
  readonly notFound = signal(false);
  readonly shared = signal(false);

  /** Their mark when they have uploaded one; their initial when they have not. */
  readonly initial = computed(
    () => this.organizer()?.name.trim().charAt(0).toUpperCase() || '?',
  );

  constructor() {
    const slug = this.route.snapshot.paramMap.get('slug')!;

    this.api.organizer(slug).subscribe({
      next: ({ data }) => {
        this.organizer.set(data);
        this.seo.forOrganizer(data, `https://myfiesta.ca/o/${data.slug}`);
      },
      error: () => {
        this.notFound.set(true);
        // A page that says "not found" but answers 200 is one a search engine
        // will happily index under the organizer's name. forPrivatePage is
        // where the noindex lives; this is not private, it is simply not a
        // page worth listing.
        this.seo.forPrivatePage('Organizer not found');
      },
    });
  }

  async share(): Promise<void> {
    const organizer = this.organizer();
    if (!organizer) return;

    const url = `https://myfiesta.ca/o/${organizer.slug}`;

    try {
      if (navigator.share) {
        await navigator.share({ title: organizer.name, url });
      } else {
        await navigator.clipboard.writeText(url);
        this.shared.set(true);
        setTimeout(() => this.shared.set(false), 2000);
      }
    } catch {
      // Dismissed the sheet; nothing to clean up.
    }
  }
}
