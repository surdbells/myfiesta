import { NgTemplateOutlet } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { ContactDetails } from '../../core/api.types';
import { Seo } from '../../core/seo';
import { DataRequestForm } from './data-request-form';

/** The pages that name the operator, and so need its details. */
const NAMES_THE_OPERATOR = new Set(['contact', 'terms', 'privacy', 'refunds']);

/** Written out for a reader, not as a code. */
const COUNTRIES: Record<string, string> = { CA: 'Canada', NG: 'Nigeria' };

/**
 * Help, terms, privacy, refunds and contact.
 *
 * The previous platform served these as static HTML off the API host and the
 * rebuild had none of them. They are not optional decoration: Stripe and
 * Paystack both require published terms and a privacy policy before an account
 * goes live, and CASL and the NDPA both require a reachable operator.
 *
 * One component rather than five. They share a shape — a title and prose — and
 * five near-identical components is five places for the styling to drift.
 *
 * The privacy page describes what the platform actually does, taken from
 * config/personal_data.php rather than from a template. That file is the map
 * the export and erasure jobs run from, so if the two disagree the policy is
 * the one that is wrong. The refund page is written the same way, from what
 * RefundService and Resale actually do.
 *
 * Who the operator is — name, inbox, postal address — comes from the API's
 * config rather than from here, because it is the operator's to set, not
 * ours to write. Until it is set, the pages say so.
 */
@Component({
  selector: 'app-info',
  imports: [NgTemplateOutlet, RouterLink, DataRequestForm],
  templateUrl: './info.html',
  styleUrl: './info.css',
})
export class Info {
  private readonly route = inject(ActivatedRoute);
  private readonly seo = inject(Seo);
  private readonly api = inject(Api);

  readonly page = computed(() => this.route.snapshot.data['page'] as string);

  /** Null until it arrives, and on the pages that do not need it. */
  readonly contact = signal<ContactDetails | null>(null);

  /** The API did not answer; the pages say where to look instead of going blank. */
  readonly contactMissing = signal(false);

  constructor() {
    const titles: Record<string, [string, string]> = {
      help: ['Help', 'Answers about tickets, refunds and getting into an event.'],
      terms: ['Terms', 'The terms you agree to when you buy or sell a ticket on myFiesta.'],
      privacy: ['Privacy', 'What we hold about you, why, and how to have it removed.'],
      refunds: ['Refunds', 'Who decides a refund, what comes back, and how long it takes.'],
      contact: ['Contact', 'How to reach us about an order, an event, or your data.'],
    };

    const page = this.route.snapshot.data['page'] as string;
    const [title, description] = titles[page] ?? ['myFiesta', ''];

    this.seo.forListing(title, description, `https://myfiesta.ca/${page}`);

    if (NAMES_THE_OPERATOR.has(page)) {
      this.api.contact().subscribe({
        next: ({ data }) => this.contact.set(data),
        error: () => this.contactMissing.set(true),
      });
    }
  }

  country(code: string): string {
    return COUNTRIES[code] ?? code;
  }
}
