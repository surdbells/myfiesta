import { Component, computed, inject, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastStore, UiAlert, UiBadge, UiButton, UiConfirm, UiEmpty, UiErrorState, UiPageHeader, UiSkeleton } from '@myfiesta/ui';
import { API_BASE_URL, Api } from '../../core/api';
import { ApiKeySummary, Integrations, WebhookDelivery, WebhookEndpoint, WebhookEventName } from '../../core/api.types';
import { messageFor } from '../../core/errors';

/** A secret just made: the only moment it exists anywhere but the other system. */
interface Reveal {
  kind: 'webhook' | 'key';
  label: string;
  value: string;
}

/** What each event means to somebody choosing which to hear about. */
const EVENT_LABELS: Record<WebhookEventName, { title: string; hint: string }> = {
  'order.paid': { title: 'An order is paid', hint: 'Online or at the door, with the buyer and what they bought.' },
  'order.refunded': { title: 'An order is refunded', hint: 'All of it or part, with the amount and how many tickets stopped working.' },
  'ticket.checked_in': { title: 'Somebody is checked in', hint: 'The name on the ticket and how many it let in.' },
};

/**
 * Connecting the organization to its other systems.
 *
 * Two directions. A webhook sends each sale, refund or check-in to an address
 * the organizer gives us; a key lets their own system ask for events, orders
 * and attendees. Both carry buyers' names and addresses out of this platform,
 * which is why only an owner sees this screen at all.
 *
 * Secrets are shown once, in a panel that says so, with a button to copy
 * them. Nothing on the screen afterwards can show them again: lost means
 * replaced.
 */
@Component({
  selector: 'app-integrations',
  imports: [FormsModule, DatePipe, UiPageHeader, UiButton, UiAlert, UiBadge, UiConfirm, UiEmpty, UiErrorState, UiSkeleton],
  templateUrl: './integrations.html',
})
export class IntegrationsScreen {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  readonly apiBase = inject(API_BASE_URL);

  readonly labels = EVENT_LABELS;

  readonly data = signal<Integrations | null>(null);
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly refused = signal(false);

  readonly reveal = signal<Reveal | null>(null);
  readonly copied = signal(false);

  // Adding a webhook.
  readonly url = signal('');
  readonly description = signal('');
  readonly chosen = signal<ReadonlySet<WebhookEventName>>(new Set(['order.paid']));
  readonly adding = signal(false);
  readonly addError = signal<string | null>(null);

  // One endpoint's history, opened below it.
  readonly openHistory = signal<string | null>(null);
  readonly history = signal<WebhookDelivery[] | null>(null);
  readonly historyFailed = signal(false);

  /**
   * Nobody opens a delivery log to read the ones that worked.
   *
   * The whole list stays the default, because "nothing here" is only
   * reassuring when you can see what it is nothing out of — but one tick
   * gets to the twelve that failed among two hundred that did not.
   */
  readonly onlyTrouble = signal(false);

  readonly deliveries = computed(() => {
    const all = this.history();
    if (!all) return null;

    return this.onlyTrouble() ? all.filter((d) => d.status !== 'succeeded') : all;
  });

  /** For the count beside the tick, which is the reason to tick it. */
  readonly troubled = computed(() => (this.history() ?? []).filter((d) => d.status !== 'succeeded').length);
  readonly busyEndpoint = signal<string | null>(null);

  readonly removing = signal<WebhookEndpoint | null>(null);
  readonly removingBusy = signal(false);

  // Keys.
  readonly keyName = signal('');
  readonly making = signal(false);
  readonly keyError = signal<string | null>(null);
  readonly revoking = signal<ApiKeySummary | null>(null);
  readonly revokingBusy = signal(false);

  readonly canAdd = computed(() => this.url().trim() !== '' && this.chosen().size > 0 && !this.adding());

  /** A request somebody can paste into a terminal to see a key work. */
  readonly example = computed(() => `curl ${this.apiBase}/api/v1/events \\\n  -H "Authorization: Bearer mf_live_…"`);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);

    this.api.integrations().subscribe({
      next: (data) => {
        this.data.set(data);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        response?.status === 403 ? this.refused.set(true) : this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  // --- webhooks ---------------------------------------------------------------

  toggleEvent(name: WebhookEventName, on: boolean): void {
    const next = new Set(this.chosen());
    on ? next.add(name) : next.delete(name);
    this.chosen.set(next);
  }

  addWebhook(): void {
    if (!this.canAdd()) return;

    this.adding.set(true);
    this.addError.set(null);

    this.api
      .addWebhook({ url: this.url().trim(), events: [...this.chosen()], description: this.description().trim() || null })
      .subscribe({
        next: ({ data, secret }) => {
          this.adding.set(false);
          this.url.set('');
          this.description.set('');
          this.chosen.set(new Set(['order.paid']));
          this.patch((all) => ({ ...all, endpoints: [...all.endpoints, data] }));
          this.show({ kind: 'webhook', label: data.url, value: secret });
        },
        error: (response: HttpErrorResponse) => {
          this.adding.set(false);
          this.addError.set(messageFor(response, 'That address could not be added.'));
        },
      });
  }

  sendTest(endpoint: WebhookEndpoint): void {
    this.busyEndpoint.set(endpoint.id);

    this.api.testWebhook(endpoint.id).subscribe({
      next: (delivery) => {
        this.busyEndpoint.set(null);
        this.toasts.show(
          delivery.status === 'succeeded'
            ? `Sent. Your system answered ${delivery.response_status}.`
            : 'Sent, but your system did not accept it. The history says what came back.',
          delivery.status === 'succeeded' ? 'success' : 'danger',
        );
        this.openHistory.set(null);
        this.toggleHistory(endpoint);
        this.refreshEndpoints();
      },
      error: (response: HttpErrorResponse) => {
        this.busyEndpoint.set(null);
        this.toasts.show(messageFor(response, 'The test could not be sent.'), 'danger');
      },
    });
  }

  setEnabled(endpoint: WebhookEndpoint, enabled: boolean): void {
    this.busyEndpoint.set(endpoint.id);

    this.api.updateWebhook(endpoint.id, { enabled }).subscribe({
      next: (updated) => {
        this.busyEndpoint.set(null);
        this.replace(updated);
        this.toasts.show(enabled ? 'On again. New sales will be sent there.' : 'Off. Nothing will be sent there until you turn it back on.', 'success');
      },
      error: (response: HttpErrorResponse) => {
        this.busyEndpoint.set(null);
        this.toasts.show(messageFor(response, 'That could not be changed.'), 'danger');
      },
    });
  }

  toggleHistory(endpoint: WebhookEndpoint): void {
    if (this.openHistory() === endpoint.id) {
      this.openHistory.set(null);

      return;
    }

    this.openHistory.set(endpoint.id);
    this.history.set(null);
    this.historyFailed.set(false);
    this.onlyTrouble.set(false);

    this.api.webhookDeliveries(endpoint.id).subscribe({
      next: (deliveries) => this.openHistory() === endpoint.id && this.history.set(deliveries),
      error: () => this.historyFailed.set(true),
    });
  }

  confirmRemove(): void {
    const endpoint = this.removing();
    if (!endpoint) return;

    this.removingBusy.set(true);

    this.api.removeWebhook(endpoint.id).subscribe({
      next: () => {
        this.removingBusy.set(false);
        this.removing.set(null);
        this.patch((all) => ({ ...all, endpoints: all.endpoints.filter((e) => e.id !== endpoint.id) }));
        this.toasts.show('Removed. Nothing more will be sent there.', 'success');
      },
      error: (response: HttpErrorResponse) => {
        this.removingBusy.set(false);
        this.removing.set(null);
        this.toasts.show(messageFor(response, 'That could not be removed.'), 'danger');
      },
    });
  }

  health(endpoint: WebhookEndpoint): { tone: 'success' | 'warning' | 'danger' | 'neutral'; label: string } {
    if (!endpoint.enabled) return { tone: 'danger', label: 'Off' };
    if (endpoint.consecutive_failures > 0 || endpoint.last_delivery?.status === 'failed') return { tone: 'warning', label: 'Failing' };
    if (endpoint.last_delivery?.status === 'pending') return { tone: 'warning', label: 'Retrying' };
    if (!endpoint.last_delivery) return { tone: 'neutral', label: 'Nothing sent yet' };

    return { tone: 'success', label: 'Working' };
  }

  // --- keys -------------------------------------------------------------------

  createKey(): void {
    const name = this.keyName().trim();
    if (!name || this.making()) return;

    this.making.set(true);
    this.keyError.set(null);

    this.api.createApiKey(name).subscribe({
      next: ({ data, key }) => {
        this.making.set(false);
        this.keyName.set('');
        this.patch((all) => ({ ...all, keys: [...all.keys, data] }));
        this.show({ kind: 'key', label: data.name, value: key });
      },
      error: (response: HttpErrorResponse) => {
        this.making.set(false);
        this.keyError.set(messageFor(response, 'That key could not be made.'));
      },
    });
  }

  confirmRevoke(): void {
    const key = this.revoking();
    if (!key) return;

    this.revokingBusy.set(true);

    this.api.revokeApiKey(key.id).subscribe({
      next: () => {
        this.revokingBusy.set(false);
        this.revoking.set(null);
        this.patch((all) => ({ ...all, keys: all.keys.filter((k) => k.id !== key.id) }));
        this.toasts.show('Revoked. Anything using it stops working now.', 'success');
      },
      error: (response: HttpErrorResponse) => {
        this.revokingBusy.set(false);
        this.revoking.set(null);
        this.toasts.show(messageFor(response, 'That key could not be revoked.'), 'danger');
      },
    });
  }

  // --- the one-time panel -----------------------------------------------------

  async copy(value: string): Promise<void> {
    try {
      await navigator.clipboard.writeText(value);
      this.copied.set(true);
    } catch {
      this.toasts.show('Copying is blocked here. Select it and copy it by hand.', 'danger');
    }
  }

  private show(reveal: Reveal): void {
    this.copied.set(false);
    this.reveal.set(reveal);
  }

  private patch(fn: (all: Integrations) => Integrations): void {
    const all = this.data();
    if (all) this.data.set(fn(all));
  }

  private replace(endpoint: WebhookEndpoint): void {
    this.patch((all) => ({ ...all, endpoints: all.endpoints.map((e) => (e.id === endpoint.id ? endpoint : e)) }));
  }

  /** After a test, so the badge says what just happened. */
  private refreshEndpoints(): void {
    this.api.integrations().subscribe({ next: (data) => this.patch((all) => ({ ...all, endpoints: data.endpoints })) });
  }
}
