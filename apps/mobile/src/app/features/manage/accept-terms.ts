import { Component, computed, inject, signal } from '@angular/core';
import { Browser } from '@capacitor/browser';
import { AccountTerms } from '../../core/account-terms';
import { Discover } from '../../core/discovery';
import { MfButton, MfCard, ToastStore } from '../../ui';

/**
 * "Please accept our terms", at the top of Manage for an organizer who never
 * has, or not these words.
 *
 * A card, not a sheet: everything in Manage keeps working while it waits, and
 * somebody opening the app to check tonight's sales should not have to get
 * past three pages of legal text first. The box starts unticked, like every
 * other one, and the pages open in the browser because they are the site's.
 */
@Component({
  selector: 'mf-accept-terms',
  imports: [MfCard, MfButton],
  template: `
    @if (asking(); as standing) {
      <mf-card class="terms">
        <p class="title">{{ standing.accepted_version ? 'Our terms have changed' : 'Please accept our terms' }}</p>
        <p class="muted">
          @if (standing.accepted_version) {
            The terms, the privacy policy or the refund policy have changed since you last accepted them.
          } @else {
            Your account has not yet accepted the terms, the privacy policy and the refund policy.
          }
          Everything here keeps working while you read them.
        </p>

        <!-- A link inside the label opens its page rather than ticking the box. -->
        <label class="agree">
          <input type="checkbox" name="accept_terms" [checked]="ticked()" (change)="ticked.set($any($event.target).checked)" />
          <span>
            I accept the
            <a [href]="page('/terms')" (click)="open($event, '/terms')">terms</a>,
            the <a [href]="page('/privacy')" (click)="open($event, '/privacy')">privacy policy</a>
            and the <a [href]="page('/refunds')" (click)="open($event, '/refunds')">refund policy</a>.
          </span>
        </label>

        @if (terms.error(); as said) {
          <p class="error" role="alert">{{ said }}</p>
        }

        <div class="actions">
          <button mfButton size="sm" [loading]="terms.saving()" [disabled]="!ticked()" (click)="accept()">Accept</button>
          <button mfButton size="sm" variant="ghost" [disabled]="terms.saving()" (click)="terms.later()">Not now</button>
        </div>
      </mf-card>
    }
  `,
  styles: `
    :host {
      display: block;
    }

    .terms {
      display: block;
      margin-bottom: var(--space-4);
    }

    .title {
      font-weight: var(--font-weight-semibold);
    }

    .muted {
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .agree {
      display: flex;
      align-items: flex-start;
      gap: var(--space-3);
      min-height: var(--mf-tap);
      margin-top: var(--space-3);
      color: var(--text);
      font-size: var(--font-size-sm);
      line-height: var(--font-leading-snug);
      cursor: pointer;
    }

    .agree input {
      flex: none;
      width: 22px;
      height: 22px;
      margin: 0;
      accent-color: var(--primary);
    }

    .agree a {
      color: var(--primary-text);
    }

    .error {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--danger-text);
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-2);
    }
  `,
})
export class MfAcceptTerms {
  readonly terms = inject(AccountTerms);
  private readonly discover = inject(Discover);
  private readonly toasts = inject(ToastStore);

  readonly ticked = signal(false);

  readonly asking = computed(() => {
    const standing = this.terms.standing();

    return standing && !standing.accepted && !this.terms.putOff() ? standing : null;
  });

  constructor() {
    void this.terms.check();
  }

  /** One of the site's own pages, whole: the app serves none of them. */
  page(path: string): string {
    return this.discover.siteBase() + path;
  }

  /** In the browser, like the checkout, so Manage stays where it was. */
  open(event: Event, path: string): void {
    event.preventDefault();
    void Browser.open({ url: this.page(path) }).catch(() => undefined);
  }

  async accept(): Promise<void> {
    if (!this.ticked()) return;

    if (await this.terms.accept()) this.toasts.show('Thank you. Your acceptance is saved.', 'success');
  }
}
