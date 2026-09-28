import { Component, computed, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, ToastStore, UiBadge, UiButton, UiConfirm } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { DoorPass, DoorPassState } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { shortEventTime } from '../../core/event-time';

/** A pass just made: the only moment its link exists anywhere but the phone. */
interface FreshPass {
  label: string;
  link: string;
  qr: string;
}

/**
 * Handing the door to somebody for the night.
 *
 * Venue staff, a friend on the guest list desk, the promoter's cousin — none
 * of them should need an account, a password, or a place on the team to scan
 * tickets for four hours. A pass is one link for one phone for one event, and
 * it stops on its own when the night is over.
 */
@Component({
  selector: 'app-door-passes',
  imports: [FormsModule, UiButton, UiBadge, UiConfirm],
  templateUrl: './door-passes.html',
})
export class DoorPasses {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);

  readonly eventId = input.required<string>();
  readonly timezone = input<string>('UTC');

  readonly passes = signal<DoorPass[]>([]);
  readonly expiresAt = signal<string | null>(null);
  readonly loaded = signal(false);
  readonly failed = signal(false);

  readonly label = signal('');
  readonly making = signal(false);
  readonly makeError = signal<string | null>(null);
  readonly fresh = signal<FreshPass | null>(null);
  readonly copied = signal(false);

  readonly revoking = signal<DoorPass | null>(null);
  readonly revokingBusy = signal(false);

  readonly canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

  readonly live = computed(() => this.passes().filter((p) => p.state === 'waiting' || p.state === 'active'));
  readonly past = computed(() => this.passes().filter((p) => p.state !== 'waiting' && p.state !== 'active'));

  readonly until = computed(() => {
    const at = this.expiresAt();

    return at ? shortEventTime(at, this.timezone()) : null;
  });

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.api.doorPasses(this.eventId()).subscribe({
      next: ({ data, expires_at }) => {
        this.passes.set(data);
        this.expiresAt.set(expires_at);
        this.loaded.set(true);
        this.failed.set(false);
      },
      error: () => {
        this.loaded.set(true);
        this.failed.set(true);
      },
    });
  }

  async make(): Promise<void> {
    const label = this.label().trim();
    if (!label || this.making()) return;

    const until = this.until();

    const sure = await this.confirmDialog.confirm({
      title: `Make a door pass for ${label}?`,
      body: `Whoever opens its link can scan tickets for this event on one phone${until ? `, until ${until}` : ''}. Nothing else: no guest list, no orders.`,
      consequences: ['The link is shown once, here. Send it straight away, or make another.', 'You can take it back at any time.'],
      confirmLabel: 'Make the pass',
      tone: 'default',
    });

    if (!sure || this.making()) return;

    this.making.set(true);
    this.makeError.set(null);

    this.api.issueDoorPass(this.eventId(), label).subscribe({
      next: ({ data, link, qr }) => {
        this.making.set(false);
        this.label.set('');
        this.copied.set(false);
        this.fresh.set({ label: data.label, link, qr });
        this.passes.set([data, ...this.passes()]);
      },
      error: (response) => {
        this.making.set(false);
        this.makeError.set(messageFor(response, 'That door pass could not be made.'));
      },
    });
  }

  async copy(link: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(link);
      this.copied.set(true);
    } catch {
      this.toasts.show('Copying is blocked here. Press on the link and copy it by hand.', 'danger');
    }
  }

  async share(pass: FreshPass): Promise<void> {
    try {
      await navigator.share({ title: `Door pass · ${pass.label}`, url: pass.link });
    } catch {
      // Dismissed, or the share sheet failed: the link is still on screen.
    }
  }

  confirmRevoke(): void {
    const pass = this.revoking();
    if (!pass) return;

    this.revokingBusy.set(true);

    this.api.revokeDoorPass(this.eventId(), pass.id).subscribe({
      next: ({ message }) => {
        this.revokingBusy.set(false);
        this.revoking.set(null);
        if (this.fresh()?.label === pass.label) this.fresh.set(null);
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.revokingBusy.set(false);
        this.revoking.set(null);
        this.toasts.show(messageFor(response, 'That pass could not be taken back.'), 'danger');
      },
    });
  }

  stateLabel(state: DoorPassState): string {
    return { waiting: 'Not opened yet', active: 'Scanning', expired: 'Expired', revoked: 'Taken back', ended: 'Ended' }[state];
  }

  stateTone(state: DoorPassState): 'success' | 'warning' | 'neutral' {
    return state === 'active' ? 'success' : state === 'waiting' ? 'warning' : 'neutral';
  }

  time(iso: string): string {
    return shortEventTime(iso, this.timezone());
  }
}
