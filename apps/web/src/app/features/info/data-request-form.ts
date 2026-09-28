import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog } from '@myfiesta/ui';
import { Api } from '../../core/api';

/**
 * Asking for your data, or asking to be forgotten.
 *
 * On the privacy page rather than behind an account, because most people
 * holding a ticket here have never made one — an erasure request that
 * required signing in would be closed to exactly the people who most often
 * want one.
 *
 * The reply is the same whichever address is typed and whether or not it is
 * known here. Anything else would make this a way to find out who has an
 * account.
 */
@Component({
  selector: 'mf-data-request-form',
  imports: [FormsModule],
  template: `
    <form class="request" (ngSubmit)="submit()">
      @if (sent()) {
        <p class="sent" role="status">
          If that address is on myFiesta, a link is on its way to it. The link is how we know the request is
          yours — nothing happens until you follow it, and it stops working after a day.
        </p>
      } @else {
        <div class="row">
          <label class="field">
            <span>Your email address</span>
            <input
              name="privacyEmail"
              type="email"
              autocomplete="email"
              required
              placeholder="you@example.com"
              [ngModel]="email()"
              (ngModelChange)="email.set($event)"
            />
          </label>

          <label class="field">
            <span>What you want</span>
            <select name="privacyKind" [ngModel]="kind()" (ngModelChange)="kind.set($event)">
              <option value="export">A copy of everything you hold about me</option>
              <option value="erasure">Erase everything you hold about me</option>
            </select>
          </label>
        </div>

        <button type="submit" [disabled]="!ready() || sending()">
          {{ sending() ? 'Sending…' : 'Send me the link' }}
        </button>

        @if (failed()) {
          <p class="failed" role="alert">That could not be sent just now. Try again in a moment.</p>
        }
      }
    </form>
  `,
  styles: `
    .request {
      display: grid;
      gap: var(--space-4);
      margin: var(--space-4) 0 var(--space-6);
      padding: var(--space-5);
      background: var(--surface-raised);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
    }
    .row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
      gap: var(--space-4);
    }
    .field {
      display: grid;
      gap: var(--space-2);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
    }
    input,
    select {
      height: 2.75rem;
      padding: 0 var(--space-3);
      font: inherit;
      font-weight: 400;
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--field-border);
      border-radius: var(--radius-md);
    }
    button {
      justify-self: start;
      height: 2.75rem;
      padding: 0 var(--space-5);
      font: inherit;
      font-weight: var(--font-weight-semibold);
      color: var(--on-primary);
      background: var(--primary);
      border: 0;
      border-radius: var(--radius-md);
      cursor: pointer;
    }
    button[disabled] {
      opacity: 0.6;
      cursor: default;
    }
    .sent,
    .failed {
      margin: 0;
    }
    .failed {
      color: var(--danger);
    }
  `,
})
export class DataRequestForm {
  private readonly api = inject(Api);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly email = signal('');
  readonly kind = signal<'export' | 'erasure'>('export');
  readonly sending = signal(false);
  readonly sent = signal(false);
  readonly failed = signal(false);

  readonly ready = computed(() => this.email().trim().includes('@'));

  async submit(): Promise<void> {
    if (!this.ready() || this.sending()) return;

    const email = this.email().trim();

    // Which of the two, said back: the dropdown sits beside the box, and
    // asking for a copy and asking to be erased read alike at a glance.
    const sure = await this.confirmDialog.confirm(
      this.kind() === 'erasure'
        ? {
            title: 'Ask to be erased?',
            body: `A link goes to ${email}. Once it is followed, myFiesta forgets that address, as far as the law allows.`,
            consequences: [
              'Orders and tickets are kept as financial records, with your name and address taken off them.',
              'Nothing happens until the link is followed, and it stops working after a day.',
            ],
            confirmLabel: 'Send the erasure link',
            tone: 'danger',
          }
        : {
            title: 'Ask for a copy of your data?',
            body: `A link goes to ${email}. Once it is followed, a copy of everything myFiesta holds about that address is sent there.`,
            consequences: ['Nothing happens until the link is followed, and it stops working after a day.'],
            confirmLabel: 'Send me the link',
            tone: 'default',
          },
    );

    if (!sure || this.sending()) return;

    this.sending.set(true);
    this.failed.set(false);

    this.api.requestMyData(this.kind(), email).subscribe({
      next: () => {
        this.sending.set(false);
        this.sent.set(true);
      },
      error: () => {
        this.sending.set(false);
        this.failed.set(true);
      },
    });
  }
}
