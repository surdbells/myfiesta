import { Component, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ConfirmDialog } from '@myfiesta/ui';
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
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly order = signal<TicketAccess | null>(null);
  readonly loading = signal(true);
  readonly notFound = signal(false);

  /** The link is the whole credential, and everything here is done with it. */
  private readonly token = this.route.snapshot.paramMap.get('token') ?? '';

  /**
   * The ticket a request is running for. Every other ticket's link waits
   * too, but only this one says it is being given back or kept: a page-wide
   * "Giving it back…" on the ticket beside it reads as that one going.
   */
  readonly working = signal<string | null>(null);
  readonly busy = computed(() => this.working() !== null);
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
  async giveBack(ticketId: string): Promise<void> {
    if (this.busy()) return;

    // Said plainly before it happens: the money comes when somebody takes the
    // place, not now — the part people get wrong — and the ticket stops
    // working straight away either way.
    const sure = await this.confirmDialog.confirm({
      title: `Give back your ${this.describe(ticketId)}?`,
      body: 'It stops working straight away, and you get back what you paid once somebody takes the place.',
      consequences: ['If nobody takes it, nothing is paid back. You can keep it again until somebody does.'],
      confirmLabel: 'Give it back',
      tone: 'danger',
    });

    if (sure) this.act(ticketId, this.api.returnTicket(this.token, ticketId));
  }

  async keep(ticketId: string): Promise<void> {
    if (this.busy()) return;

    const sure = await this.confirmDialog.confirm({
      title: `Keep your ${this.describe(ticketId)}?`,
      body: 'It works again at the door straight away, and nobody else can take the place.',
      consequences: ['Nothing is paid back.'],
      confirmLabel: 'Keep the ticket',
      tone: 'default',
    });

    if (sure) this.act(ticketId, this.api.keepTicket(this.token, ticketId));
  }

  /** "General ticket for Afro Fest", from what the page already holds. */
  private describe(ticketId: string): string {
    const order = this.order();
    const ticket = order?.tickets.find((t) => t.id === ticketId);

    return `${ticket?.type ? `${ticket.type} ` : ''}ticket${order ? ` for ${order.event.title}` : ''}`;
  }

  private act(ticketId: string, request: Observable<{ message: string; access: TicketAccess }>): void {
    if (this.busy()) return;

    this.working.set(ticketId);
    this.notice.set(null);

    request.subscribe({
      next: ({ message, access }) => {
        this.working.set(null);
        this.notice.set(message);
        // The server's own answer, not a second fetch: an identical GET can
        // come back from the hydration cache showing the page as it was.
        this.order.set(access);
      },
      error: (response) => {
        this.working.set(null);
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
