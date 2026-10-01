import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, UiButton } from '@myfiesta/ui';
import { switchMap, tap } from 'rxjs';
import { Api } from '../../../core/api';
import { OrganizerEventDetail } from '../../../core/api.types';
import { messageFor } from '../../../core/errors';
import { SessionStore } from '../../../core/session';
import { describeZone, isoToZonedWallClock, zonedWallClockToIso } from '../../../core/zoned-time';
import { SchedApi } from '../../sched/sched-api';

/**
 * Going on sale at a set time (`publish_at`), beside the button that sends an
 * event for review, on its Overview.
 *
 * The SCHED track's own file. The Overview places it once and never edits it
 * again.
 *
 * At the time set, the night is sent the way the Submit button would send it,
 * as the member who set the time (events:go-live): straight on sale when
 * myFiesta's approval stands for it as it is, otherwise to review, going on
 * sale once approved. An event approved before its time waits for it. So the
 * part says which of those will happen, and the way to be on sale exactly on
 * time: send it for review now.
 *
 * Who may set the time is who may put the event on sale (events.publish);
 * everybody who can open the event sees that a time is set. Only a draft or
 * an event waiting for review has a time to set: one on sale is on sale, and
 * a cancelled or finished one never will be.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * takes it from there: its own copy, the workspace header, the series panel.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-schedule-part',
  host: { class: 'contents' },
  imports: [FormsModule, UiButton],
  template: `
    @if (shown()) {
      <section class="schedule mb-6 grid gap-2 rounded-md border border-border bg-surface-raised p-4 text-sm" aria-labelledby="schedule-heading">
        <h2 id="schedule-heading" class="m-0 text-base font-semibold">Going on sale</h2>

        @if (publishAt(); as at) {
          <p class="scheduled m-0">
            Set to go on sale by itself on <strong>{{ when(at) }}</strong>.
          </p>
          <p class="m-0 text-text-muted">{{ whatHappensThen() }}</p>
        } @else if (canSet()) {
          <p class="m-0 text-text-muted">
            Choose a time and it goes on sale by itself then, so you do not have to be at a screen when tickets open.
          </p>
        }

        @if (canSet()) {
          @if (editing()) {
            <form class="schedule-form mt-2 flex flex-wrap items-end gap-3" (ngSubmit)="save()">
              <div class="field min-w-0">
                <label for="publishAt">Goes on sale at ({{ zoneName() }})</label>
                <input
                  id="publishAt"
                  name="publishAt"
                  type="datetime-local"
                  required
                  [ngModel]="timeInput()"
                  (ngModelChange)="timeInput.set($event)"
                />
              </div>
              <button uiButton type="submit" size="sm" [loading]="saving()" [disabled]="saving()">
                {{ publishAt() ? 'Change the time' : 'Set the time' }}
              </button>
              <button
                class="link cursor-pointer border-0 bg-transparent p-0 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-primary"
                type="button"
                (click)="editing.set(false)"
              >
                Cancel
              </button>
            </form>
            <p class="help m-0 text-xs text-text-muted">The venue's time, before the event starts.</p>
          } @else {
            <div class="mt-1 flex flex-wrap items-center gap-3">
              <button uiButton variant="secondary" size="sm" type="button" (click)="edit()">
                {{ publishAt() ? 'Change the time' : 'Set a time to go on sale' }}
              </button>
              @if (publishAt()) {
                <button
                  class="link cursor-pointer border-0 bg-transparent p-0 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-danger"
                  type="button"
                  (click)="clear()"
                >
                  Stop waiting for this time
                </button>
              }
            </div>
          }
        }

        @if (error(); as message) {
          <p class="error m-0 text-sm text-danger-text" role="alert">{{ message }}</p>
        }
        @if (notice(); as message) {
          <p class="notice m-0 text-sm text-success" role="status">{{ message }}</p>
        }
      </section>
    }
  `,
})
export class SchedulePart {
  private readonly api = inject(Api);
  private readonly schedApi = inject(SchedApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly event = input.required<OrganizerEventDetail>();
  readonly changed = output<OrganizerEventDetail>();

  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly timeInput = signal('');
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  readonly publishAt = computed(() => this.event().publish_at ?? null);

  /** A draft, or one waiting for review, that has not started: the only ones with a time to wait for. */
  readonly open = computed(() => {
    const event = this.event();

    return (
      (event.status === 'draft' || event.status === 'in_review') &&
      event.sales_ended !== true &&
      new Date(event.starts_at).getTime() > Date.now()
    );
  });

  readonly canSet = computed(() => this.open() && this.session.canPublish());

  /** Somebody who cannot set it still sees that a time is set. */
  readonly shown = computed(() => this.open() && (this.canSet() || this.publishAt() !== null));

  readonly zoneName = computed(() => describeZone(this.event().timezone));

  /** What the time will do, as the server would answer if it came now. */
  readonly whatHappensThen = computed(() => {
    const event = this.event();

    if (event.review.suspended) {
      return 'Your organization’s sales are suspended, so it waits until that is lifted, and goes on sale then if it has not started.';
    }
    if (event.status === 'in_review') {
      return 'It is waiting for review. Approved before then, it waits for this time; approved after, it goes on sale as soon as it is approved.';
    }
    if (event.review.on_submit === 'publish') {
      return 'myFiesta has approved it as it is, so it goes straight on sale then, and the people who follow you are told. Change anything a buyer sees and it goes to review at that time instead.';
    }

    return 'At that time it is sent to myFiesta for review, and goes on sale once it is approved. To be on sale right on time, submit it for review now: approved early, it waits for this time.';
  });

  /** A time in the venue's zone, with the zone named. */
  when(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
      timeZone: this.event().timezone,
    }).format(new Date(iso));
  }

  /** Open the form, at the time already set, or empty. */
  edit(): void {
    this.error.set(null);
    this.notice.set(null);

    const at = this.publishAt();
    this.timeInput.set(at ? (isoToZonedWallClock(at, this.event().timezone) ?? '') : '');
    this.editing.set(true);
  }

  /** Set or move the time, once the organizer has read what it will do. */
  async save(): Promise<void> {
    const event = this.event();

    this.error.set(null);
    this.notice.set(null);

    const iso = this.timeInput() ? zonedWallClockToIso(this.timeInput(), event.timezone) : null;

    // Said here rather than as a refusal from the server, so nobody confirms
    // a time that is then turned down.
    if (!iso) {
      this.error.set('Choose the day and time it goes on sale.');

      return;
    }
    if (new Date(iso).getTime() <= Date.now()) {
      this.error.set('Choose a time that has not passed yet.');

      return;
    }
    if (new Date(iso).getTime() >= new Date(event.starts_at).getTime()) {
      this.error.set('Choose a time before the event starts.');

      return;
    }

    const shown = this.when(iso);
    const approved = event.review.on_submit === 'publish' && event.status === 'draft';

    this.saving.set(true);

    const done = await this.write(
      {
        title: `Put ${event.title} on sale at ${shown}?`,
        body: approved
          ? 'It is approved as it is, so at that time it goes on sale without another review.'
          : 'At that time it is sent to myFiesta the way Submit sends it: on sale once it is approved.',
        consequences: [
          'It is sent as you. If by then you can no longer put events on sale, it is not sent, and everybody who can is emailed why.',
          'If something a buyer sees changes before then, it goes to review at that time instead of on sale.',
          'You can change the time, or stop waiting for it, until then.',
        ],
        confirmLabel: 'Set the time',
      },
      iso,
    );

    this.saving.set(false);

    if (!done) return;

    this.editing.set(false);
    this.notice.set(`It goes on sale by itself on ${shown}. We email you when it does.`);
  }

  /** Stop waiting for the time. It stays as it is otherwise. */
  async clear(): Promise<void> {
    const at = this.publishAt();
    if (!at) return;

    this.error.set(null);
    this.notice.set(null);

    const done = await this.write(
      {
        title: `Stop waiting for ${this.when(at)}?`,
        body: 'It does not go on sale by itself. It stays as it is until somebody puts it on sale or submits it for review.',
        consequences: ['Nothing else about the event changes, and any approval it has stands.'],
        confirmLabel: 'Stop waiting',
      },
      null,
    );

    if (done) this.notice.set('It no longer goes on sale by itself.');
  }

  /** Ask, then write and read the event again, handing it to the Overview. */
  private write(question: { title: string; body: string; consequences: string[]; confirmLabel: string }, publishAt: string | null): Promise<boolean> {
    const eventId = this.event().id;
    let fresh: OrganizerEventDetail | null = null;

    return this.confirmDialog
      .confirm({
        ...question,
        busyLabel: 'Saving…',
        tone: 'default',
        run: () =>
          this.schedApi.setPublishAt(eventId, publishAt).pipe(
            switchMap(() => this.api.event(eventId)),
            tap((event) => (fresh = event)),
          ),
        failure: (response) => messageFor(response, 'The time could not be saved.'),
      })
      .then((done) => {
        if (done && fresh) this.changed.emit(fresh);

        return done;
      });
  }
}
