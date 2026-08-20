import { Component, computed, inject } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Seo } from '../../core/seo';

/**
 * Help, terms, privacy and contact.
 *
 * The previous platform served these as static HTML off the API host and the
 * rebuild had none of them. They are not optional decoration: Stripe and
 * Paystack both require published terms and a privacy policy before an account
 * goes live, and CASL and the NDPA both require a reachable operator.
 *
 * One component rather than four. They share a shape — a title and prose — and
 * four near-identical components is four places for the styling to drift.
 *
 * The privacy page describes what the platform actually does, taken from
 * config/personal_data.php rather than from a template. That file is the map
 * the export and erasure jobs run from, so if the two disagree the policy is
 * the one that is wrong.
 */
@Component({
  selector: 'app-info',
  imports: [RouterLink],
  templateUrl: './info.html',
  styleUrl: './info.css',
})
export class Info {
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);

  readonly page = computed(() => this.route.snapshot.data['page'] as string);

  constructor() {
    const titles: Record<string, [string, string]> = {
      help: ['Help', 'Answers about tickets, refunds and getting into an event.'],
      terms: ['Terms', 'The terms you agree to when you buy or sell a ticket on myFiesta.'],
      privacy: ['Privacy', 'What we hold about you, why, and how to have it removed.'],
      contact: ['Contact', 'How to reach us about an order, an event, or your data.'],
    };

    const page = this.route.snapshot.data['page'] as string;
    const [title, description] = titles[page] ?? ['myFiesta', ''];

    this.seo.forListing(title, description, `https://myfiesta.ca/${page}`);
  }
}
