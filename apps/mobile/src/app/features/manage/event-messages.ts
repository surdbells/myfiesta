import { Component, OnInit, computed, inject, input, signal } from '@angular/core';
import { PenLine } from 'lucide-angular';
import type { AttendeeMessage, MessageAudience, OrganizerEventDetail, PageMeta } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { fieldErrors, messageOf } from '../../core/errors';
import { ago } from '../../core/when';
import {
  Dialogs,
  MfBadge,
  MfButton,
  MfCard,
  MfEmpty,
  MfField,
  MfIconButton,
  MfScreen,
  MfSheet,
  MfSkeleton,
  MfSwitch,
  ToastStore,
} from '../../ui';
import { EventContext } from './event-context';

/**
 * Writing to everybody holding a ticket: the door moved, the set times, bring
 * ID.
 *
 * How many it reaches is said before it is sent, and so is who it skips —
 * people who turned event emails off. Overriding that is a switch named for
 * what it does, because the label is the only place the choice is made plain.
 */
@Component({
  selector: 'mf-event-messages',
  imports: [MfScreen, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfSwitch],
  template: `
    <mf-screen title="Messages" [subtitle]="event()?.title ?? null" back [backTo]="'/manage/events/' + id()" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="writeIcon" label="Write to ticket holders" (click)="compose()"></button>

      @if (audience(); as a) {
        <mf-card class="reach" quiet>
          <p><strong class="figure">{{ a.reachable }}</strong> of {{ a.holders }} ticket holders can be emailed</p>
          @if (a.holders - a.reachable > 0) {
            <p class="muted">{{ a.holders - a.reachable }} turned event emails off.</p>
          }
        </mf-card>
      }

      @if (error(); as message) {
        <mf-empty title="Could not load the messages" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (messages(); as all) {
        @if (all.length === 0) {
          <mf-empty title="Nothing sent yet" hint="The event name, date and venue go in every message, so you only write what changed.">
            <button mfButton (click)="compose()">Write one</button>
          </mf-empty>
        } @else {
          <ul class="items">
            @for (message of all; track message.id) {
              <li>
                <mf-card tappable (click)="reading.set(message)">
                  <div class="top">
                    <h3>{{ message.subject }}</h3>
                    @if (message.important) {
                      <mf-badge tone="warning">Important</mf-badge>
                    }
                  </div>
                  <p class="excerpt">{{ message.body }}</p>
                  <p class="meta">
                    @if (message.sent_at) {
                      Sent {{ since(message.sent_at) }}
                      @if (message.recipients !== null) {
                        · {{ message.recipients }} {{ message.recipients === 1 ? 'person' : 'people' }}
                      }
                    } @else {
                      {{ message.status === 'failed' ? 'Did not send' : 'Sending…' }}
                    }
                  </p>
                </mf-card>
              </li>
            }
          </ul>

          @if (hasMore()) {
            <button mfButton class="more" variant="secondary" block [loading]="loading()" (click)="more()">Show older</button>
          }
        }
      } @else {
        <mf-card><mf-skeleton height="4rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="!!reading()" [heading]="reading()?.subject ?? ''" [subheading]="reading()?.sent_at ? 'Sent ' + since(reading()!.sent_at) : null" closable (closed)="reading.set(null)">
      @if (reading(); as message) {
        <p class="body">{{ message.body }}</p>
        <p class="meta">
          @if (message.recipients !== null) {
            Reached {{ message.recipients }}.
          }
          @if (message.suppressed) {
            {{ message.suppressed }} skipped — they turned event emails off.
          }
        </p>
      }
    </mf-sheet>

    <mf-sheet [open]="writing()" heading="Write to ticket holders" subheading="The event, date and venue are added for you." closable (closed)="writing.set(false)">
      <div class="form">
        @if (formError(); as message) {
          <p class="form-error" role="alert">{{ message }}</p>
        }
        <mf-field label="Subject" [limit]="160" [count]="subject().length" [error]="err('subject')">
          <input [value]="subject()" (input)="subject.set($any($event.target).value)" maxlength="160" placeholder="Doors now open at 9" />
        </mf-field>
        <mf-field label="Message" [limit]="4000" [count]="body().length" [error]="err('body')">
          <textarea class="tall" [value]="body()" (input)="body.set($any($event.target).value)" maxlength="4000" placeholder="What changed, and what they should do about it."></textarea>
        </mf-field>
        <mf-switch
          label="They need this before they travel"
          hint="Sends even to people who turned event emails off."
          [checked]="important()"
          (changed)="important.set($event)"
        />
        <div class="reach-now" [class.warn]="important() && skipped() > 0">
          <p><strong class="figure">{{ willReach() }}</strong> {{ willReach() === 1 ? 'person' : 'people' }} will get this.</p>
          @if (skipped() > 0) {
            <p>
              @if (important()) {
                Includes {{ skipped() }} who turned event emails off. Only when not knowing would waste their journey.
              } @else {
                {{ skipped() }} turned event emails off and will not.
              }
            </p>
          }
        </div>
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="writing.set(false)">Not now</button>
        <button mfButton [loading]="sending()" [disabled]="!ready()" (click)="send()">Send</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .reach {
      display: block;
      margin-bottom: var(--space-4);
    }

    .reach p {
      font-size: var(--font-size-sm);
    }

    .reach .figure {
      font-size: var(--font-size-lg);
    }

    .muted {
      color: var(--text-muted);
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

    h3 {
      flex: 1;
      font-size: var(--font-size-base);
    }

    .excerpt {
      display: -webkit-box;
      margin-top: var(--space-1);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .meta {
      margin-top: var(--space-2);
      font-size: var(--font-size-xs);
      color: var(--text-subtle);
    }

    .body {
      white-space: pre-wrap;
      line-height: 1.55;
    }

    .more {
      margin-top: var(--space-4);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .tall {
      min-height: 10rem;
    }

    .reach-now {
      display: grid;
      gap: var(--space-1);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--surface-inset);
      font-size: var(--font-size-sm);
    }

    .reach-now.warn {
      background: color-mix(in srgb, var(--warning) 14%, transparent);
    }

    .reach-now .figure {
      font-size: var(--font-size-lg);
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
export class EventMessages implements OnInit {
  readonly id = input.required<string>();

  private readonly organizer = inject(Organizer);
  private readonly context = inject(EventContext);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly event = signal<OrganizerEventDetail | null>(null);
  protected readonly messages = signal<AttendeeMessage[] | null>(null);
  protected readonly audience = signal<MessageAudience | null>(null);
  protected readonly meta = signal<PageMeta | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);

  protected readonly reading = signal<AttendeeMessage | null>(null);
  protected readonly writing = signal(false);
  protected readonly subject = signal('');
  protected readonly body = signal('');
  protected readonly important = signal(false);
  protected readonly sending = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly errors = signal<Record<string, string>>({});

  protected readonly writeIcon = PenLine;
  protected readonly since = ago;

  protected readonly skipped = computed(() => {
    const a = this.audience();
    return a ? a.holders - a.reachable : 0;
  });

  protected readonly willReach = computed(() => {
    const a = this.audience();
    if (!a) return 0;
    return this.important() ? a.holders : a.reachable;
  });

  protected readonly ready = computed(
    () => this.subject().trim() !== '' && this.body().trim() !== '' && this.willReach() > 0 && !this.sending(),
  );

  ngOnInit(): void {
    this.event.set(this.context.peek(this.id()));
    void this.context.get(this.id()).then((e) => this.event.set(e)).catch(() => undefined);
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      const page = await this.organizer.messages(this.id(), 1);
      this.messages.set(page.data);
      this.meta.set(page.meta);
      this.audience.set(page.audience);
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected hasMore(): boolean {
    const m = this.meta();
    return !!m && m.current_page < m.last_page;
  }

  protected async more(): Promise<void> {
    this.loading.set(true);

    try {
      const page = await this.organizer.messages(this.id(), (this.meta()?.current_page ?? 1) + 1);
      this.messages.update((rows) => [...(rows ?? []), ...page.data]);
      this.meta.set(page.meta);
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.loading.set(false);
    }
  }

  protected err(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  protected compose(): void {
    this.formError.set(null);
    this.errors.set({});
    this.writing.set(true);
  }

  protected async send(): Promise<void> {
    if (!this.ready()) return;

    const reach = this.willReach();
    const people = `${reach} ${reach === 1 ? 'person' : 'people'}`;
    const sure = await this.dialogs.confirm({
      title: `Send “${this.subject().trim()}” to ${people}?`,
      body: `It goes straight away to everybody holding a ticket for this event${this.important() ? ', even those who asked not to hear from you' : ' who may be written to'}, and cannot be called back.`,
      confirmLabel: `Send to ${people}`,
      tone: 'default',
    });

    if (!sure || this.sending()) return;

    this.sending.set(true);
    this.formError.set(null);
    this.errors.set({});

    try {
      const result = await this.organizer.sendMessage(this.id(), {
        subject: this.subject().trim(),
        body: this.body().trim(),
        important: this.important(),
      });

      this.writing.set(false);
      this.subject.set('');
      this.body.set('');
      this.important.set(false);
      this.toasts.show(result.message, 'success');
      await this.load();
    } catch (error) {
      this.errors.set(fieldErrors(error));
      this.formError.set(Object.keys(this.errors()).length ? null : messageOf(error));
    } finally {
      this.sending.set(false);
    }
  }
}
