import { Component, inject, signal } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { TicketAccess } from '../../core/api.types';

/**
 * The tickets somebody bought, and the QR a door reads.
 *
 * This is the screen the platform was missing entirely: the scanner endpoint,
 * partial admission and the ledger all existed, and there was nowhere to put a
 * ticket in front of a camera. The confirmation email printed the code as text
 * and somebody was expected to read it out at the door.
 *
 * No sign-in. Guest checkout is the primary path, so most people holding a
 * ticket have no account — the token in the link is the whole credential.
 */
@Component({
  selector: 'app-tickets',
  imports: [RouterLink],
  templateUrl: './tickets.html',
  styleUrl: './tickets.css',
})
export class Tickets {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly sanitizer = inject(DomSanitizer);

  readonly order = signal<TicketAccess | null>(null);
  readonly loading = signal(true);
  readonly notFound = signal(false);

  constructor() {
    const token = this.route.snapshot.paramMap.get('token') ?? '';

    this.api.ticketsByToken(token).subscribe({
      next: (order) => {
        this.order.set(order);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.notFound.set(true);
      },
    });
  }

  /**
   * The QR arrives as SVG markup and has to be trusted to render.
   *
   * Safe because of where it comes from, not because of what it looks like:
   * the server builds it from a ticket code that the platform generated itself
   * out of a fixed alphabet, and the code is escaped into the label. Nothing a
   * buyer or organizer ever typed reaches this string.
   */
  /**
   * The start time in the venue's zone.
   *
   * Never the reader's. Somebody who bought a Toronto ticket while in Lagos
   * needs to know when to be at the door in Toronto, and a ticket showing the
   * wrong one is the single most expensive mistake this screen could make.
   */
  when(starts: string, timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
      dateStyle: 'full',
      timeStyle: 'short',
      timeZone,
    }).format(new Date(starts));
  }

  qr(svg: string): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(svg);
  }

  /** Ticket brightness matters at a door. */
  requestFullBrightness(): void {
    // Screen Wake Lock keeps the display from dimming mid-queue. Not available
    // everywhere and not worth a polyfill — the page works without it.
    const nav = navigator as Navigator & { wakeLock?: { request(type: 'screen'): Promise<unknown> } };

    void nav.wakeLock?.request('screen').catch(() => undefined);
  }
}
