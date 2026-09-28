import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, ToastStore, UiButton, UiField, UiModal } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { WaitlistPage } from '../../core/api.types';
import { messageFor } from '../../core/errors';

/**
 * The waitlist, on the screen where tickets are released.
 *
 * Shown only once somebody has joined. Telling them is a choice about how
 * many: releasing twenty places to four hundred people sends most of them to
 * a sold-out page, so the organizer picks the first so many.
 */
@Component({
  selector: 'app-event-waitlist',
  imports: [FormsModule, UiButton, UiField, UiModal],
  template: `
    @if (page(); as page) {
      @if (total() > 0) {
        <section class="mb-6 rounded-lg border border-border bg-surface-raised p-6" aria-labelledby="waitlist-heading">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 id="waitlist-heading" class="text-xs font-semibold uppercase tracking-[0.08em] text-text-subtle">Waitlist</h2>
              <p class="mt-2 text-lg font-semibold tabular-nums">
                {{ page.summary.waiting }} waiting
                <span class="text-sm font-normal text-text-muted">· {{ page.summary.waiting_tickets }} {{ page.summary.waiting_tickets === 1 ? 'ticket' : 'tickets' }} wanted</span>
              </p>
              <p class="mt-1 text-sm text-text-muted tabular-nums">
                {{ page.summary.notified }} told · {{ page.summary.purchased }} got in
              </p>
            </div>

            <div class="grid justify-items-end gap-1 max-sm:justify-items-start">
              <button uiButton type="button" [disabled]="page.summary.waiting === 0 || !page.on_sale" (click)="openTell()">Tell the waitlist</button>
              @if (!page.on_sale && page.summary.waiting > 0) {
                <p class="max-w-[28ch] text-right text-xs text-text-muted max-sm:text-left">Release tickets first — reopen a tier or add places.</p>
              }
            </div>
          </div>

          <ul class="m-0 mt-4 grid list-none gap-px overflow-hidden rounded-md border border-border bg-border p-0 text-sm">
            @for (entry of page.data.slice(0, 8); track entry.id) {
              <li class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-4 bg-surface-raised px-4 py-2">
                <span class="min-w-0 truncate">{{ entry.name || entry.email }} <span class="text-text-subtle">{{ entry.name ? entry.email : '' }}</span></span>
                <span class="tabular-nums text-text-muted">×{{ entry.quantity }}</span>
                <span class="text-xs text-text-subtle">{{ label(entry.status) }}</span>
              </li>
            }
          </ul>
          @if (page.meta.total > 8) {
            <p class="mt-2 text-xs text-text-muted">and {{ page.meta.total - 8 }} more, in the order they joined.</p>
          }
        </section>
      }
    }

    <ui-modal [open]="telling()" heading="Tell the waitlist" (dismissed)="telling.set(false)">
      <div class="grid gap-4">
        <p class="text-sm text-text-muted">
          They get an email saying tickets are available, first come first served. Earliest to join are told first.
        </p>
        <ui-field label="How many people" [hint]="'Of ' + (page()?.summary?.waiting ?? 0) + ' waiting.'">
          <input name="limit" type="number" min="1" [max]="page()?.summary?.waiting ?? 1" [ngModel]="limit()" (ngModelChange)="limit.set($event)" />
        </ui-field>
        <ui-field label="A line from you" optionalMark hint="Shown in the email. What was released, or when doors open.">
          <textarea name="note" maxlength="500" rows="3" [ngModel]="note()" (ngModelChange)="note.set($event)"></textarea>
        </ui-field>
      </div>
      <ng-container modalActions>
        <button uiButton variant="secondary" type="button" (click)="telling.set(false)">Cancel</button>
        <button uiButton type="button" [loading]="sending()" [disabled]="sending() || !validLimit()" (click)="tell()">
          Email {{ limit() || 0 }} {{ Number(limit()) === 1 ? 'person' : 'people' }}
        </button>
      </ng-container>
    </ui-modal>
  `,
})
export class EventWaitlist implements OnInit {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly eventId = input.required<string>();

  protected readonly Number = Number;

  readonly page = signal<WaitlistPage | null>(null);
  readonly telling = signal(false);
  readonly sending = signal(false);
  readonly limit = signal<number | string>(1);
  readonly note = signal('');

  readonly total = computed(() => {
    const s = this.page()?.summary;

    return s ? s.waiting + s.notified + s.purchased : 0;
  });

  readonly validLimit = computed(() => {
    const n = Number(this.limit());

    return Number.isInteger(n) && n >= 1 && n <= (this.page()?.summary.waiting ?? 0);
  });

  ngOnInit(): void {
    this.load();
  }

  /** Called by the tickets screen after a tier changes, since that can open sales. */
  load(): void {
    this.api.waitlist(this.eventId()).subscribe({
      next: (page) => this.page.set(page),
      error: () => undefined,
    });
  }

  openTell(): void {
    this.limit.set(this.page()?.summary.waiting ?? 1);
    this.note.set('');
    this.telling.set(true);
  }

  async tell(): Promise<void> {
    if (!this.validLimit() || this.sending()) return;

    const n = Number(this.limit());
    const people = `${n} ${n === 1 ? 'person' : 'people'}`;
    const waiting = this.page()?.summary.waiting ?? n;

    // An email cannot be called back, and telling too many sends most of
    // them to a sold-out page: the number is said once more before it goes.
    const sure = await this.confirmDialog.confirm({
      title: `Email the first ${people} on the waitlist?`,
      body: `${n === 1 ? 'They hear' : 'They all hear'} straight away that tickets are available, first come first served.`,
      consequences: [`The other ${Math.max(0, waiting - n)} keep waiting.`],
      confirmLabel: `Email ${people}`,
      tone: 'default',
    });

    if (!sure || this.sending()) return;

    this.sending.set(true);

    this.api.notifyWaitlist(this.eventId(), n, this.note().trim() || null).subscribe({
      next: ({ message }) => {
        this.sending.set(false);
        this.telling.set(false);
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.sending.set(false);
        this.toasts.show(messageFor(response, 'The waitlist could not be told.'), 'danger');
      },
    });
  }

  label(status: string): string {
    return { waiting: 'Waiting', notified: 'Told', purchased: 'Got in', left: 'Left' }[status] ?? status;
  }
}
