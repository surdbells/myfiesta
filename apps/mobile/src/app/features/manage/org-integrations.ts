import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Copy, KeyRound, ListChecks, Pencil, Power, PowerOff, Send, Trash2, Webhook } from 'lucide-angular';
import type { ApiKeySummary, Integrations, WebhookDelivery, WebhookEndpoint, WebhookEventName } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfCheck,
  MfEmpty,
  MfField,
  MfIcon,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
} from '../../ui';

const EVENT_LABELS: Record<WebhookEventName, { title: string; hint: string }> = {
  'order.paid': { title: 'An order is paid', hint: 'Online or at the door, with the buyer and what they bought.' },
  'order.refunded': { title: 'An order is refunded', hint: 'All of it or part, with the amount and how many tickets stopped working.' },
  'ticket.checked_in': { title: 'Somebody is checked in', hint: 'The name on the ticket and how many it let in.' },
};

/** A secret or key, shown once — the only moment it exists anywhere but the other system. */
interface Reveal {
  kind: 'webhook' | 'key';
  label: string;
  value: string;
}

/**
 * Other systems told about what happens here — webhooks — and keys for other
 * systems to ask.
 *
 * A webhook's signing secret and an API key are each shown exactly once, when
 * they are made, with a way to copy them. After that only the last four of a
 * key are ever shown, and a lost one is replaced rather than recovered.
 */
@Component({
  selector: 'mf-org-integrations',
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfCheck, MfIcon],
  template: `
    <mf-screen title="Integrations" back backTo="/manage" refreshable [busy]="loading()" (refresh)="load()">
      @if (error(); as message) {
        <mf-empty title="Could not load your integrations" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (all(); as a) {
        <section class="block">
          <div class="heading-row">
            <h2 class="heading">Webhooks</h2>
            <button mfButton size="sm" variant="secondary" (click)="startWebhook()"><mf-icon [icon]="hookIcon" size="sm" /> Add</button>
          </div>
          <p class="note">We call your address when something happens, signed so you know it was us.</p>

          @if (a.endpoints.length === 0) {
            <mf-card quiet><p class="muted">None yet.</p></mf-card>
          } @else {
            <ul class="items">
              @for (e of a.endpoints; track e.id) {
                <li>
                  <mf-card tappable (click)="webhookMenu(e)">
                    <div class="top">
                      <span class="url">{{ e.url }}</span>
                      <mf-badge [tone]="health(e).tone">{{ health(e).label }}</mf-badge>
                    </div>
                    @if (e.description) {
                      <p class="sub">{{ e.description }}</p>
                    }
                    <p class="sub">{{ describeEvents(e.events) }}</p>
                    @if (e.disabled_reason) {
                      <p class="warn">{{ e.disabled_reason }}</p>
                    } @else if (e.last_delivery; as d) {
                      <p class="meta">Last: {{ d.event }} · {{ d.response_status ?? 'no answer' }} · {{ since(d.at) }}</p>
                    }
                  </mf-card>
                </li>
              }
            </ul>
          }
        </section>

        <section class="block">
          <div class="heading-row">
            <h2 class="heading">API keys</h2>
            <button mfButton size="sm" variant="secondary" (click)="startKey()"><mf-icon [icon]="keyIcon" size="sm" /> New key</button>
          </div>
          <p class="note">For your own systems to read your events and orders.</p>

          @if (a.keys.length === 0) {
            <mf-card quiet><p class="muted">None yet.</p></mf-card>
          } @else {
            <ul class="keys">
              @for (k of a.keys; track k.id) {
                <li>
                  <span class="who">
                    <span class="name">{{ k.name }}</span>
                    <span class="sub mono">…{{ k.last_four }} · {{ k.last_used_at ? 'used ' + since(k.last_used_at) : 'never used' }}</span>
                  </span>
                  <button mfButton size="sm" variant="ghost" (click)="revokeKey(k)">Revoke</button>
                </li>
              }
            </ul>
          }
        </section>
      } @else {
        <mf-card><mf-skeleton height="8rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="hooking()" [heading]="editing() ? 'What it hears about' : 'New webhook'" closable (closed)="hooking.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        @if (!editing()) {
          <mf-field label="Address" hint="https only.">
            <input type="url" inputmode="url" autocapitalize="off" autocomplete="off" [value]="url()" (input)="url.set($any($event.target).value)" placeholder="https://hooks.example.com/myfiesta" />
          </mf-field>
        }
        <div class="checks">
          @for (name of eventNames(); track name) {
            <mf-check [label]="labels[name].title" [hint]="labels[name].hint" [value]="chosen().includes(name)" (valueChange)="toggle(name, $event)" />
          }
        </div>
        <mf-field label="What it is for" optional>
          <input [value]="description()" (input)="description.set($any($event.target).value)" maxlength="200" placeholder="Our CRM" />
        </mf-field>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="hooking.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!hookReady()" (click)="saveWebhook()">{{ editing() ? 'Save' : 'Add webhook' }}</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="naming()" heading="New API key" subheading="Name it for the system that will use it." closable (closed)="naming.set(false)">
      <mf-field label="Name" [error]="keyError()">
        <input [value]="keyName()" (input)="keyName.set($any($event.target).value)" maxlength="80" placeholder="Accounting export" />
      </mf-field>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="naming.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!keyName().trim()" (click)="createKey()">Make key</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="!!reveal()" [heading]="reveal()?.kind === 'key' ? 'Your new key' : 'Signing secret'" [subheading]="reveal()?.label ?? null" (closed)="dismissReveal()">
      @if (reveal(); as r) {
        <p class="once">Copy it now. It is not shown again — a lost one is replaced, not recovered.</p>
        <p class="secret">{{ r.value }}</p>
      }
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="copySecret()"><mf-icon [icon]="copyIcon" size="sm" /> Copy</button>
        <button mfButton (click)="reveal.set(null)">I have kept it</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="!!deliveries()" heading="Recent deliveries" [subheading]="deliveriesFor()?.url ?? null" closable (closed)="deliveries.set(null)">
      @if (deliveries(); as list) {
        @if (list.length === 0) {
          <p class="muted">Nothing sent yet.</p>
        } @else {
          <ul class="deliveries">
            @for (d of list; track d.id) {
              <li>
                <span class="who">
                  <span class="name">{{ d.event }}</span>
                  <span class="sub">{{ since(d.created_at) }} · {{ d.attempts }} {{ d.attempts === 1 ? 'try' : 'tries' }}@if (d.response_status) { · {{ d.response_status }} }</span>
                  @if (d.response_excerpt && d.status === 'failed') {
                    <span class="excerpt">{{ d.response_excerpt }}</span>
                  }
                </span>
                <mf-badge [tone]="d.status === 'succeeded' ? 'success' : d.status === 'failed' ? 'danger' : 'warning'">{{ d.status === 'succeeded' ? 'Delivered' : d.status === 'failed' ? 'Failed' : 'Retrying' }}</mf-badge>
              </li>
            }
          </ul>
        }
      }
    </mf-sheet>
  `,
  styles: `
    .block {
      display: grid;
      gap: var(--space-3);
      margin-bottom: var(--space-6);
    }

    .heading-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .heading {
      font-size: var(--font-size-lg);
    }

    .note,
    .muted {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .note {
      margin-top: calc(var(--space-2) * -1);
    }

    .items {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .top {
      display: flex;
      align-items: flex-start;
      gap: var(--space-2);
    }

    .url {
      flex: 1;
      min-width: 0;
      font-family: var(--font-family-mono);
      font-size: var(--font-size-sm);
      overflow-wrap: anywhere;
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .mono {
      font-family: var(--font-family-mono);
    }

    .meta {
      margin-top: var(--space-2);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .warn {
      margin-top: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--danger-text);
    }

    .keys,
    .deliveries {
      display: grid;
      margin: 0;
      padding: 0;
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
    }

    .keys li,
    .deliveries li {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      padding: var(--space-3) var(--space-4);
    }

    .keys li + li,
    .deliveries li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .who {
      flex: 1;
      display: grid;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-semibold);
    }

    .excerpt {
      margin-top: var(--space-1);
      font-family: var(--font-family-mono);
      font-size: var(--font-size-xs);
      color: var(--danger-text);
      overflow-wrap: anywhere;
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .checks {
      display: grid;
    }

    .once {
      margin-bottom: var(--space-3);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--warning) 12%, transparent);
      font-size: var(--font-size-sm);
    }

    .secret {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-md);
      background: var(--surface-inset);
      font-family: var(--font-family-mono);
      font-size: var(--font-size-sm);
      overflow-wrap: anywhere;
      user-select: all;
    }

    .form-error {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--danger) 10%, transparent);
      color: var(--danger-text);
      font-size: var(--font-size-sm);
    }
  `,
})
export class OrgIntegrations implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly all = signal<Integrations | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected readonly hooking = signal(false);
  protected readonly editing = signal<WebhookEndpoint | null>(null);
  protected readonly url = signal('');
  protected readonly description = signal('');
  protected readonly chosen = signal<WebhookEventName[]>(['order.paid']);
  protected readonly formError = signal<string | null>(null);

  protected readonly naming = signal(false);
  protected readonly keyName = signal('');
  protected readonly keyError = signal<string | null>(null);

  protected readonly reveal = signal<Reveal | null>(null);
  private readonly copied = signal(false);
  protected readonly deliveries = signal<WebhookDelivery[] | null>(null);
  protected readonly deliveriesFor = signal<WebhookEndpoint | null>(null);

  protected readonly labels = EVENT_LABELS;
  protected readonly hookIcon = Webhook;
  protected readonly keyIcon = KeyRound;
  protected readonly copyIcon = Copy;
  protected readonly since = ago;

  protected readonly eventNames = computed(() => this.all()?.events ?? (Object.keys(EVENT_LABELS) as WebhookEventName[]));

  protected readonly hookReady = computed(
    () => !this.busy() && this.chosen().length > 0 && (!!this.editing() || /^https:\/\/[^\s/]+\.[^\s]+/.test(this.url().trim())),
  );

  ngOnInit(): void {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      this.all.set(await this.organizer.integrations());
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected health(e: WebhookEndpoint): { tone: 'success' | 'warning' | 'danger' | 'neutral'; label: string } {
    if (!e.enabled) return { tone: 'danger', label: 'Off' };
    if (e.consecutive_failures > 0 || e.last_delivery?.status === 'failed') return { tone: 'warning', label: 'Failing' };
    if (e.last_delivery?.status === 'pending') return { tone: 'warning', label: 'Retrying' };
    if (!e.last_delivery) return { tone: 'neutral', label: 'Nothing sent' };
    return { tone: 'success', label: 'Working' };
  }

  protected describeEvents(events: WebhookEventName[]): string {
    return events.map((e) => EVENT_LABELS[e]?.title ?? e).join(' · ');
  }

  protected toggle(name: WebhookEventName, on: boolean): void {
    this.chosen.update((all) => (on ? [...all, name] : all.filter((n) => n !== name)));
  }

  protected startWebhook(): void {
    this.editing.set(null);
    this.url.set('');
    this.description.set('');
    this.chosen.set(['order.paid']);
    this.formError.set(null);
    this.hooking.set(true);
  }

  protected async saveWebhook(): Promise<void> {
    if (!this.hookReady()) return;

    const editing = this.editing();
    const url = editing?.url ?? this.url().trim();
    const events = this.chosen();

    // Buyers' names and emails leave myFiesta for this address with every
    // sale, so where they go, and which of them, is said back first.
    const sure = await this.dialogs.confirm({
      title: editing ? `Change what ${url} hears about?` : `Send sales to ${url}?`,
      body: `From now on, ${events.join(', ')} ${events.length === 1 ? 'is' : 'are'} sent there as ${events.length === 1 ? 'it happens' : 'they happen'}, with the buyer's or ticket holder's name and email.`,
      consequences: editing ? [] : ['The signing secret is shown once, straight after. Save it then.'],
      confirmLabel: editing ? 'Save changes' : 'Add the address',
      tone: 'default',
    });

    if (!sure || this.busy()) return;

    this.busy.set(true);
    this.formError.set(null);

    try {
      if (editing) {
        await this.organizer.updateWebhook(editing.id, { events: this.chosen(), description: this.description().trim() || null });
        this.hooking.set(false);
        this.toasts.show('Saved.', 'success');
      } else {
        const { data, secret } = await this.organizer.addWebhook({
          url: this.url().trim(),
          events: this.chosen(),
          description: this.description().trim() || null,
        });
        this.hooking.set(false);
        this.copied.set(false);
        this.reveal.set({ kind: 'webhook', label: data.url, value: secret });
      }

      await this.load();
    } catch (error) {
      this.formError.set(messageOf(error, 'That could not be saved.'));
    } finally {
      this.busy.set(false);
    }
  }

  protected async webhookMenu(e: WebhookEndpoint): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: e.url,
      subtitle: this.health(e).label,
      actions: [
        { key: 'test', label: 'Send a test', icon: Send, disabled: !e.enabled },
        { key: 'log', label: 'Recent deliveries', icon: ListChecks },
        { key: 'edit', label: 'Change what it hears about', icon: Pencil },
        e.enabled ? { key: 'off', label: 'Turn it off', icon: PowerOff } : { key: 'on', label: 'Turn it back on', icon: Power },
        { key: 'remove', label: 'Remove', icon: Trash2, danger: true },
      ],
    });

    try {
      switch (chosen) {
        case 'test': {
          const sure = await this.dialogs.confirm({
            title: `Send a test to ${e.url}?`,
            body: 'A test delivery goes there now, so you can see your system take it. It says nothing happened.',
            consequences: ['It carries no order and no buyer.'],
            confirmLabel: 'Send a test',
            tone: 'default',
          });

          if (!sure) return;

          const d = await this.organizer.testWebhook(e.id);
          this.toasts.show(d.status === 'succeeded' ? `Delivered — it answered ${d.response_status}.` : 'Sent. It did not answer with a success; see recent deliveries.', d.status === 'succeeded' ? 'success' : 'danger');
          break;
        }
        case 'log':
          this.deliveriesFor.set(e);
          this.deliveries.set(await this.organizer.webhookDeliveries(e.id));
          return;
        case 'edit':
          this.editing.set(e);
          this.chosen.set([...e.events]);
          this.description.set(e.description ?? '');
          this.formError.set(null);
          this.hooking.set(true);
          return;
        case 'on':
        case 'off': {
          const sure = await this.dialogs.confirm(
            chosen === 'on'
              ? {
                  title: `Turn ${e.url} back on?`,
                  body: 'New sales are sent there again, from now on.',
                  consequences: ['Sales made while it was off are not sent.'],
                  confirmLabel: 'Turn it on',
                  tone: 'default',
                }
              : {
                  title: `Turn off ${e.url}?`,
                  body: 'Nothing is sent there until you turn it back on.',
                  consequences: ['Sales made while it is off are not sent later.'],
                  confirmLabel: 'Turn it off',
                  tone: 'danger',
                },
          );

          if (!sure) return;

          await this.organizer.updateWebhook(e.id, { enabled: chosen === 'on' });
          this.toasts.show(chosen === 'on' ? 'Back on.' : 'Turned off.', 'success');
          break;
        }
        case 'remove': {
          const sure = await this.dialogs.confirm({
            title: `Stop sending to ${e.url}?`,
            body: 'Nothing more is sent there, and its history goes with it.',
            consequences: ['To start again you would add it back and get a new secret.'],
            confirmLabel: 'Remove the address',
            tone: 'danger',
          });

          if (!sure) return;
          await this.organizer.removeWebhook(e.id);
          this.toasts.show('Removed.', 'success');
          break;
        }
        default:
          return;
      }

      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }

  protected startKey(): void {
    this.keyName.set('');
    this.keyError.set(null);
    this.naming.set(true);
  }

  protected async createKey(): Promise<void> {
    const name = this.keyName().trim();
    if (!name) return;

    const sure = await this.dialogs.confirm({
      title: `Make a key called ${name}?`,
      body: "Whatever holds it can read this organization's events, orders and attendees through the API, until you revoke it.",
      consequences: ['The key is shown once, straight after. Save it then.'],
      confirmLabel: 'Make the key',
      tone: 'default',
    });

    if (!sure || this.busy()) return;

    this.busy.set(true);
    this.keyError.set(null);

    try {
      const { data, key } = await this.organizer.createApiKey(name);
      this.naming.set(false);
      this.copied.set(false);
      this.reveal.set({ kind: 'key', label: data.name, value: key });
      await this.load();
    } catch (error) {
      this.keyError.set(messageOf(error, 'That key could not be made.'));
    } finally {
      this.busy.set(false);
    }
  }

  protected async revokeKey(k: ApiKeySummary): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: `Revoke ${k.name}?`,
      body: 'Anything using it stops working straight away.',
      consequences: ['This cannot be undone: you would make a new key and put it where this one was.'],
      confirmLabel: 'Revoke the key',
      tone: 'danger',
    });

    if (!sure) return;

    try {
      await this.organizer.revokeApiKey(k.id);
      this.toasts.show('Revoked.', 'success');
      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }

  /** A swipe away from something shown once asks first, unless it was copied. */
  protected async dismissReveal(): Promise<void> {
    if (!this.copied()) {
      const sure = await this.dialogs.confirm({
        title: 'Close without copying?',
        body: 'It is not shown again. If it is lost, you will have to make a new one.',
        confirmLabel: 'Close without copying',
        tone: 'danger',
      });

      if (!sure) return;
    }

    this.reveal.set(null);
  }

  protected async copySecret(): Promise<void> {
    const r = this.reveal();
    if (!r) return;

    try {
      await navigator.clipboard.writeText(r.value);
      this.copied.set(true);
      this.toasts.show('Copied.', 'success');
    } catch {
      this.toasts.show('Copying is blocked here. Press on it and copy it by hand.', 'danger');
    }
  }
}
