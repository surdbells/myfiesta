import { Component, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { Clock, greeting } from '../../core/greeting';
import { SessionStore } from '../../core/session';
import { MfAvatar } from '../../ui';

/**
 * Who is signed in, over "What's on": their photo, or their initials, beside
 * a greeting for the time of day where they are and their first name. Tapping
 * it opens Settings, where the photo and the zone are changed.
 *
 * The time of day is read in the zone saved on the account, and in the
 * phone's own when there is none — so somebody whose account says Vancouver
 * is not wished a good evening at four in the afternoon by a phone still set
 * to Toronto. The account is asked for again quietly when this appears, so a
 * photo changed on another phone turns up here too.
 *
 * Only for an account: signed out there is nobody to greet by name, and a door
 * pass is a label for the night, not a person. In both cases it takes no room
 * at all, and the screen's subtitle says the greeting instead.
 */
@Component({
  selector: 'mf-profile-chip',
  imports: [MfAvatar],
  template: `
    @if (shown()) {
      <button type="button" class="chip" [attr.aria-label]="label()" (click)="open()">
        <mf-avatar [name]="name()" [src]="avatarUrl()" [size]="40" />
        <span class="lines">
          <span class="hello">{{ hello() }}</span>
          <span class="name">{{ firstName() }}</span>
        </span>
      </button>
    }
  `,
  styles: `
    :host {
      display: contents;
    }

    .chip {
      justify-self: start;
      display: inline-flex;
      align-items: center;
      gap: 12px;
      max-width: 100%;
      min-width: 0;
      margin-bottom: var(--space-3);
      padding: 6px 14px 6px 6px;
      border: 0;
      border-radius: var(--radius-full);
      background: var(--surface-raised);
      box-shadow: inset 0 0 0 1px var(--border-subtle);
      color: var(--text);
      font: inherit;
      text-align: left;
      cursor: pointer;
      transition: background-color 120ms ease;
    }

    .chip:active {
      background: var(--surface-hover);
    }

    .lines {
      display: grid;
      min-width: 0;
      line-height: 1.25;
    }

    .hello {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .name {
      overflow: hidden;
      font-size: var(--font-size-base);
      font-weight: var(--font-weight-semibold);
      text-overflow: ellipsis;
      white-space: nowrap;
    }
  `,
})
export class MfProfileChip {
  private readonly session = inject(SessionStore);
  private readonly clock = inject(Clock);
  private readonly router = inject(Router);

  readonly shown = computed(() => this.session.signedIn() && !this.session.locked());

  protected readonly name = computed(() => this.session.session()?.name ?? '');
  protected readonly avatarUrl = computed(() => this.session.session()?.avatarUrl ?? null);

  readonly firstName = computed(() => this.name().trim().split(/\s+/)[0] ?? '');

  /** "Good evening", in the account's zone, else the phone's. Moves on with the clock. */
  readonly hello = computed(() => greeting(this.clock.now(), this.session.session()?.timezone));

  protected readonly label = computed(() => `${this.hello()}, ${this.firstName()}. Open settings`);

  constructor() {
    void this.session.refreshAccount();
  }

  open(): void {
    void this.router.navigate(['/settings']);
  }
}
