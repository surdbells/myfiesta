import { Component, computed, inject, input } from '@angular/core';
import { UiIcon } from '@myfiesta/ui';
import { ArrowRight, BadgeCheck, Percent, ScanLine, Ticket, Wallet } from 'lucide-angular';
import { CONSOLE_URL } from '../core/console-url';

/**
 * The other half of the business, addressed on the pages buyers read.
 *
 * Every organizer on this platform arrived as somebody who bought a ticket
 * first, looked at how the night was sold, and went looking for the door. That
 * door was not on the site anywhere.
 *
 * Four things, each true of the product as built: selling online, the door app
 * that keeps scanning without signal, payouts to a bank, and the fee model —
 * the strongest of them, because the service charge is added at checkout and
 * paid by the buyer, so the price an organizer sets is the amount they are
 * paid. No invented numbers and no quotes from organizers who do not exist.
 *
 * The percentage is the one the platform charges, sent with the front page
 * from the admin's own setting. A page that does not have it — the listing —
 * says "our service charge" rather than a figure that might be out of date.
 *
 * A component rather than markup in the home page, because it belongs at the
 * foot of the listing screen too — somebody who has just scrolled forty events
 * is somebody thinking about what it takes to be on that list.
 */
@Component({
  selector: 'app-organizer-pitch',
  imports: [UiIcon],
  templateUrl: './organizer-pitch.html',
})
export class OrganizerPitch {
  readonly consoleUrl = inject(CONSOLE_URL);

  /** The buyer's service charge per currency, as a person writes it ("8"). */
  readonly fees = input<Partial<Record<'CAD' | 'NGN', string>> | null | undefined>(null);

  /** The short version, for a smaller band at the foot of a listing. */
  readonly compact = input(false);

  protected readonly arrowIcon = ArrowRight;
  protected readonly verifiedIcon = BadgeCheck;

  /** "Our 8% service charge", or both rates where they differ. */
  readonly charge = computed(() => {
    const fees = this.fees();
    const cad = fees?.CAD;
    const ngn = fees?.NGN;

    if (cad && ngn && cad !== ngn) return `Our service charge — ${cad}% in Canada, ${ngn}% in Nigeria —`;
    if (cad ?? ngn) return `Our ${cad ?? ngn}% service charge`;

    return 'Our service charge';
  });

  readonly features = computed(() => [
    {
      icon: Ticket,
      title: 'Sell online in minutes',
      body: 'An event page with ticket tiers, presale codes and extras. Buyers check out as guests — through Stripe in Canada and Paystack in Nigeria.',
    },
    {
      icon: ScanLine,
      title: 'A door app that works offline',
      body: 'Scan tickets and sell at the door from your phone. When the signal drops it keeps scanning, and syncs when you are back online.',
    },
    {
      icon: Wallet,
      title: 'Payouts to your bank',
      body: 'Request a payout to your bank account from the console, with every sale, refund and fee on the record beside it.',
    },
    {
      icon: Percent,
      title: 'Fees you can explain',
      body: `${this.charge()} is added at checkout and paid by the buyer. The price you set is the price you are paid, and a sale at the door carries no charge at all.`,
    },
  ]);
}
