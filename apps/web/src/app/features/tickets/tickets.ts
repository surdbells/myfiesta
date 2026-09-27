import { Component, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { Seo } from '../../core/seo';
import { TicketAccess } from '../../core/api.types';
import { AddToCalendar } from '../../shared/add-to-calendar';
import { ReceiptSection } from './receipt';

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
  imports: [RouterLink, AddToCalendar, ReceiptSection],
  templateUrl: './tickets.html',
})
export class Tickets {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly seo = inject(Seo);

  readonly order = signal<TicketAccess | null>(null);
  readonly loading = signal(true);
  readonly notFound = signal(false);

  /** The link is the whole credential, and everything here is done with it. */
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  /** The ticket being asked about before it is handed back. */
  readonly returning = signal<string | null>(null);
  readonly busy = signal(false);
  readonly notice = signal<string | null>(null);

  constructor() {
    this.seo.forPrivatePage('Your tickets');
    this.load();
  }

  private load(): void {
    this.api.ticketsByToken(this.token).subscribe({
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
   * Hand a ticket back, then ask the server what the page looks like now.
   *
   * Reloaded rather than patched in place: a returned ticket has no QR, no
   * code and a different set of things its holder can do, and that shape is
   * the server's answer rather than a second copy of the rules here.
   */
  giveBack(ticketId: string): void {
    this.act(this.api.returnTicket(this.token, ticketId));
  }

  keep(ticketId: string): void {
    this.act(this.api.keepTicket(this.token, ticketId));
  }

  private act(request: Observable<{ message: string; access: TicketAccess }>): void {
    if (this.busy()) return;

    this.busy.set(true);
    this.notice.set(null);

    request.subscribe({
      next: ({ message, access }) => {
        this.busy.set(false);
        this.returning.set(null);
        this.notice.set(message);
        // The server's own answer, not a second fetch: an identical GET can
        // come back from the hydration cache showing the page as it was.
        this.order.set(access);
      },
      error: (response) => {
        this.busy.set(false);
        this.returning.set(null);
        this.notice.set(response?.error?.message ?? 'That could not be done just now.');
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
