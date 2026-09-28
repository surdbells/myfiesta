import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { ConfirmDialog, UiIcon } from '@myfiesta/ui';
import { Eye } from 'lucide-angular';
import { Api } from '../../core/api';
import { SessionStore } from '../../core/session';

/**
 * The strip across the top of every screen while myFiesta staff are viewing
 * an organization as it does.
 *
 * Unmissable on purpose, and not dismissable: the person looking at it must
 * never forget whose console this is, and anybody glancing at their screen
 * must be able to tell at once. It names the organization, the staff member,
 * and how long is left; End revokes the token on the server before this tab
 * forgets it.
 *
 * The countdown is a convenience. The server ends the session at the same
 * moment whatever this shows — it is the token's own expiry.
 */
@Component({
  selector: 'app-staff-banner',
  imports: [UiIcon],
  host: { class: 'contents' },
  template: `
    @if (session.impersonation(); as staff) {
      <section
        class="staff-banner sticky top-0 z-50 flex h-12 items-center gap-3 bg-warning px-4 text-sm text-text-inverse shadow-(--shadow-raised)"
        role="region"
        aria-label="Staff session"
      >
        <ui-icon class="shrink-0" [icon]="eyeIcon" size="sm" aria-hidden="true" />
        <p
          class="staff-banner__text min-w-0 flex-1 truncate"
          [attr.title]="
            'You are viewing ' +
            staff.organization.name +
            ' as myFiesta staff (' +
            staff.staff.name +
            '). Why: ' +
            staff.reason
          "
        >
          You are viewing <strong class="font-semibold">{{ staff.organization.name }}</strong> as
          myFiesta staff ({{ staff.staff.name }})
        </p>
        <span
          class="staff-banner__clock shrink-0 tabular-nums font-semibold"
          [attr.title]="'Ends at ' + endsAt()"
        >
          <span aria-hidden="true">{{ countdown() }}</span>
          <span class="sr-only">{{ minutesLeft() }} left</span>
        </span>
        <button
          type="button"
          class="staff-banner__end shrink-0 cursor-pointer rounded-md border border-current bg-transparent px-3 py-1 text-sm font-semibold text-inherit [font-family:inherit] hover:bg-[color-mix(in_srgb,currentColor_14%,transparent)] disabled:cursor-wait disabled:opacity-70"
          [disabled]="ending()"
          aria-label="End staff session"
          (click)="end()"
        >
          {{ ending() ? 'Ending…' : 'End' }}
        </button>
      </section>
    }
  `,
})
export class StaffBanner {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  protected readonly eyeIcon = Eye;

  private readonly now = signal(Date.now());

  readonly ending = signal(false);

  private readonly expiresAt = computed(() => {
    const staff = this.session.impersonation();

    return staff ? new Date(staff.expires_at).getTime() : 0;
  });

  /** Milliseconds left, never negative. */
  readonly remaining = computed(() => Math.max(0, this.expiresAt() - this.now()));

  readonly countdown = computed(() => {
    const seconds = Math.ceil(this.remaining() / 1000);
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
  });

  /** For screen readers: the minute, not a number that changes every second. */
  readonly minutesLeft = computed(() => {
    const minutes = Math.ceil(this.remaining() / 60_000);

    return minutes === 1 ? '1 minute' : `${minutes} minutes`;
  });

  readonly endsAt = computed(() =>
    this.expiresAt()
      ? new Date(this.expiresAt()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : '',
  );

  constructor() {
    const timer = setInterval(() => this.tick(), 1000);
    inject(DestroyRef).onDestroy(() => clearInterval(timer));
  }

  /**
   * Revoke on the server, then forget in this tab — once it is confirmed,
   * because a session ended by a stray click cannot be picked up again
   * without going back to the admin for a new one.
   */
  async end(): Promise<void> {
    const staff = this.session.impersonation();
    if (this.ending() || !staff) return;

    const sure = await this.confirmDialog.confirm({
      title: 'End the staff session?',
      body: `This tab stops acting as ${staff.organization.name}, and the session is closed for good.`,
      consequences: ['Opening the console again takes a new session from the admin, with a new reason.'],
      confirmLabel: 'End staff session',
      tone: 'default',
    });

    // Answered after the hour ran out: tick() has already left.
    if (!sure || this.ending() || !this.session.impersonation()) return;

    this.ending.set(true);

    this.api.endImpersonation().subscribe({
      next: () => this.leave(),
      // Forgotten here either way. A revoke that did not arrive still stops
      // at the session's own expiry, and the page after says which it was.
      error: () => this.leave('unconfirmed'),
    });
  }

  tick(): void {
    this.now.set(Date.now());

    if (this.session.impersonation() && this.remaining() === 0 && !this.ending()) {
      this.leave('expired');
    }
  }

  private leave(why?: 'expired' | 'unconfirmed'): void {
    this.session.clear();
    this.ending.set(false);
    void this.router.navigate(['/impersonate/ended'], {
      replaceUrl: true,
      queryParams: why ? { why } : undefined,
    });
  }
}
