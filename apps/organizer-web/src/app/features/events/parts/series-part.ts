import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ConfirmDialog, UiButton } from '@myfiesta/ui';
import { switchMap, tap } from 'rxjs';
import { Api } from '../../../core/api';
import { OrganizerEventDetail, Series, SeriesSettings } from '../../../core/api.types';
import { messageFor } from '../../../core/errors';
import { SessionStore } from '../../../core/session';
import { SchedApi } from '../../sched/sched-api';

type Ending = 'never' | 'count' | 'until';

/** The choices as the Repeats panel words them. */
const HOW_OFTEN: Record<string, string> = { weekly: 'every week', fortnightly: 'every two weeks', monthly: 'every month' };

/**
 * A repeating night's settings — when it stops, whether each date puts itself
 * on sale and how long before — under the Repeats panel on its Overview.
 *
 * The SCHED track's own file. The Overview places it once and never edits it
 * again. `series` is null for a night that does not repeat, and the part then
 * draws nothing.
 *
 * How often it repeats is shown and never changed: every date already made,
 * and every ticket for them, was made for that pattern, so a different one is
 * a new series. Shortening a run removes the dates past its new end that
 * nobody has bought into and keeps the rest, which the confirmation says
 * before anything is removed. Putting dates on sale by themselves is putting
 * events on sale, so only a member who may (events.publish) turns it on, and
 * each date goes as them, asked again when it goes.
 *
 * `changed` hands back the event as it stands after a write, read again
 * from GET /organizer/events/{id} once the part's own call has answered (the
 * API puts each feature's field on it: OrganizerEventExtras), and the Overview
 * reads its series again with it, since shortening one removes dates.
 *
 * `contents`, so an empty part adds no box and no gap to the page.
 */
@Component({
  selector: 'app-series-part',
  host: { class: 'contents' },
  imports: [FormsModule, UiButton],
  template: `
    @if (active(); as s) {
      <section class="series-settings card mt-4 p-6" aria-labelledby="series-settings-heading">
        <h2 id="series-settings-heading" class="section mb-1 text-base font-semibold">How it repeats</h2>

        <p class="summary m-0 text-sm">{{ summary() }}</p>
        <p class="publishing m-0 mt-1 text-sm text-text-muted">{{ publishingSummary() }}</p>

        @if (scheduled().length > 0) {
          <ul class="going-on-sale m-0 mt-3 grid list-none gap-1 p-0 text-sm">
            @for (date of scheduled(); track date.id) {
              <li class="tabular-nums">
                {{ when(date.starts_at, false) }} <span class="text-text-muted">goes on sale {{ when(date.publish_at!, true) }}</span>
              </li>
            }
          </ul>
        }

        @if (canChange()) {
          @if (editing()) {
            <form class="series-form mt-4 grid gap-4" (ngSubmit)="save()">
              @if (session.canEditEvents()) {
                <fieldset class="m-0 grid gap-2 border-0 p-0">
                  <legend class="mb-1 text-sm font-medium">It stops</legend>
                  <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input class="radio" type="radio" name="ending" value="never" [checked]="ending() === 'never'" (change)="ending.set('never')" />
                    When I stop it
                  </label>
                  <label class="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                    <input class="radio" type="radio" name="ending" value="count" [checked]="ending() === 'count'" (change)="ending.set('count')" />
                    After
                    <input
                      id="seriesCount"
                      name="seriesCount"
                      class="w-20"
                      type="number"
                      min="2"
                      max="104"
                      step="1"
                      aria-label="How many dates in all"
                      [disabled]="ending() !== 'count'"
                      [ngModel]="countInput()"
                      (ngModelChange)="countInput.set($event)"
                    />
                    dates, counting the first
                  </label>
                  <label class="flex cursor-pointer flex-wrap items-center gap-2 text-sm">
                    <input class="radio" type="radio" name="ending" value="until" [checked]="ending() === 'until'" (change)="ending.set('until')" />
                    After the night on
                    <input
                      id="seriesUntil"
                      name="seriesUntil"
                      type="date"
                      aria-label="The last day"
                      [disabled]="ending() !== 'until'"
                      [ngModel]="untilInput()"
                      (ngModelChange)="untilInput.set($event)"
                    />
                  </label>
                </fieldset>
              }

              @if (session.canPublish()) {
                <fieldset class="m-0 grid gap-2 border-0 p-0">
                  <legend class="mb-1 text-sm font-medium">Going on sale</legend>
                  <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input class="check" type="checkbox" name="autoPublish" [checked]="autoInput()" (change)="autoInput.set(!autoInput())" />
                    Each date goes on sale by itself
                  </label>
                  @if (autoInput()) {
                    <label class="flex flex-wrap items-center gap-2 pl-6 text-sm">
                      <input
                        id="seriesDaysBefore"
                        name="seriesDaysBefore"
                        class="w-20"
                        type="number"
                        min="1"
                        max="365"
                        step="1"
                        aria-label="Days before each night"
                        [ngModel]="daysInput()"
                        (ngModelChange)="daysInput.set($event)"
                      />
                      days before its night. Leave it empty for as soon as the date is added.
                    </label>
                  }
                </fieldset>
              }

              <div class="flex flex-wrap items-center gap-3">
                <button uiButton type="submit" size="sm" [loading]="saving()" [disabled]="saving()">Save</button>
                <button
                  class="link cursor-pointer border-0 bg-transparent p-0 text-sm text-text-muted underline underline-offset-2 [font-family:inherit] hover:text-primary"
                  type="button"
                  (click)="editing.set(false)"
                >
                  Cancel
                </button>
              </div>
              <p class="help m-0 text-xs text-text-muted">
                It keeps repeating {{ howOften() }}. To repeat it on another pattern, stop this series and start a new one.
              </p>
            </form>
          } @else {
            <div class="mt-4">
              <button uiButton variant="secondary" size="sm" type="button" [disabled]="inReview()" (click)="edit(s)">Change how it repeats</button>
            </div>
            @if (inReview()) {
              <p class="help mt-2 mb-0 text-xs text-text-muted">Changes wait until the review is done.</p>
            }
          }
        }

        @if (error(); as message) {
          <p class="error mt-3 mb-0 text-sm text-danger-text" role="alert">{{ message }}</p>
        }
        @if (notice(); as message) {
          <p class="notice mt-3 mb-0 text-sm text-success" role="status">{{ message }}</p>
        }
      </section>
    }
  `,
})
export class SeriesPart {
  private readonly api = inject(Api);
  private readonly schedApi = inject(SchedApi);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly event = input.required<OrganizerEventDetail>();
  readonly series = input<Series | null>(null);
  readonly changed = output<OrganizerEventDetail>();

  readonly editing = signal(false);
  readonly saving = signal(false);
  readonly ending = signal<Ending>('never');
  readonly countInput = signal<number | string>('');
  readonly untilInput = signal('');
  readonly autoInput = signal(false);
  readonly daysInput = signal<number | string>('');
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  /** Only a series still repeating has anything to change. */
  readonly active = computed(() => {
    const series = this.series();

    return series && series.status === 'active' ? series : null;
  });

  readonly inReview = computed(() => this.event().status === 'in_review');
  /**
   * Changing a series is editing its event, whatever is changed; whether its
   * dates go on sale by themselves is offered on top only to a member who may
   * put events on sale.
   */
  readonly canChange = computed(() => this.session.canEditEvents());

  readonly howOften = computed(() => HOW_OFTEN[this.active()?.frequency ?? ''] ?? 'on its pattern');

  /** "Repeats every week, for 8 dates in all." */
  readonly summary = computed(() => {
    const series = this.active();
    if (!series) return '';

    const often = this.howOften();

    if (series.count) return `Repeats ${often}, for ${series.count} dates in all.`;
    if (series.until) return `Repeats ${often}, until the night on ${this.day(series.until)}.`;

    return `Repeats ${often}, until you stop it. Dates are added six months ahead.`;
  });

  readonly publishingSummary = computed(() => {
    const series = this.active();
    if (!series) return '';

    if (!series.auto_publish) return 'Each new date is a draft until somebody puts it on sale.';
    if (series.on_sale_days_before == null) return 'Each new date goes on sale by itself as soon as it is added.';

    const days = series.on_sale_days_before;

    return `Each date goes on sale by itself ${days === 1 ? '1 day' : `${days} days`} before its night.`;
  });

  /** Upcoming drafts waiting for a time, so the organizer sees what goes when. */
  readonly scheduled = computed(() =>
    (this.active()?.occurrences ?? []).filter((date) => date.status === 'draft' && !!date.publish_at).slice(0, 8),
  );

  /** A time in the venue's zone; the zone named with the hour. */
  when(iso: string, withTime: boolean): string {
    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'short',
      day: 'numeric',
      month: 'short',
      ...(withTime ? { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' } : {}),
      timeZone: this.event().timezone,
    }).format(new Date(iso));
  }

  /** "2026-12-18" as "Friday, December 18, 2026", read as the calendar day it is. */
  day(date: string): string {
    return new Intl.DateTimeFormat('en-CA', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
      new Date(`${date}T12:00:00Z`),
    );
  }

  /** Open the form at the settings as they stand. */
  edit(series: Series): void {
    this.error.set(null);
    this.notice.set(null);
    this.ending.set(series.count ? 'count' : series.until ? 'until' : 'never');
    this.countInput.set(series.count ?? '');
    this.untilInput.set(series.until ?? '');
    this.autoInput.set(series.auto_publish ?? false);
    this.daysInput.set(series.on_sale_days_before ?? '');
    this.editing.set(true);
  }

  /** What the form asks for that differs from the series, or null when it cannot be sent. */
  changes(series: Series): SeriesSettings | null {
    const settings: SeriesSettings = {};

    if (this.session.canEditEvents()) {
      const ending = this.ending();

      if (ending === 'count') {
        const count = Number(this.countInput());

        if (!Number.isInteger(count) || count < 2 || count > 104) {
          this.error.set('Repeat it for 2 to 104 dates, counting the first.');

          return null;
        }
        if (count !== series.count) settings.count = count;
      } else if (ending === 'until') {
        const until = this.untilInput();

        if (!/^\d{4}-\d{2}-\d{2}$/.test(until)) {
          this.error.set('Choose the day of the last night.');

          return null;
        }
        if (until !== series.until) settings.until = until;
      } else if (series.count || series.until) {
        settings.count = null;
        settings.until = null;
      }
    }

    if (this.session.canPublish()) {
      const auto = this.autoInput();
      const raw = this.daysInput();
      const days = auto && raw !== '' && raw !== null ? Number(raw) : null;

      if (days !== null && (!Number.isInteger(days) || days < 1 || days > 365)) {
        this.error.set('Put each date on sale 1 to 365 days before its night, or leave it empty for as soon as it is added.');

        return null;
      }

      if (auto !== !!series.auto_publish || (auto && days !== (series.on_sale_days_before ?? null))) {
        settings.auto_publish = auto;
        if (auto) settings.on_sale_days_before = days;
      }
    }

    return settings;
  }

  /** Save what changed, once the organizer has read what it will do. */
  async save(): Promise<void> {
    const series = this.active();
    if (!series) return;

    this.error.set(null);
    this.notice.set(null);

    const settings = this.changes(series);
    if (!settings) return;

    if (Object.keys(settings).length === 0) {
      this.editing.set(false);

      return;
    }

    const consequences: string[] = [];
    const ends = 'count' in settings || 'until' in settings;

    if (ends) {
      consequences.push(
        'Dates after the new end that nobody has bought a ticket for are deleted.',
        'Dates after it that people hold tickets for are kept, exactly as they are.',
        'If it now runs longer, the new dates are added straight away, as drafts.',
      );
    }
    if (settings.auto_publish === true) {
      consequences.push(
        'Each date goes on sale as you. A date that is this approved night, unchanged, goes straight on sale; one that has changed goes to myFiesta for review first.',
        'A date you took off sale, or one myFiesta sent back, is left as it is for you to send yourself.',
        'If you can no longer put events on sale when a date’s time comes, it stops, and everybody who can is emailed.',
      );
    }
    if (settings.auto_publish === false) {
      consequences.push('Dates waiting for the series’ time stay drafts. A time somebody set on a date of its own stays.');
    }

    let said = '';
    let fresh: OrganizerEventDetail | null = null;
    const eventId = this.event().id;

    this.saving.set(true);

    const done = await this.confirmDialog.confirm({
      title: `Change how ${this.event().title} repeats?`,
      body: this.describe(settings),
      consequences,
      confirmLabel: 'Save the changes',
      busyLabel: 'Saving…',
      tone: ends ? 'danger' : 'default',
      run: () =>
        this.schedApi.updateSeries(eventId, settings).pipe(
          tap((result) => (said = result.message)),
          switchMap(() => this.api.event(eventId)),
          tap((event) => (fresh = event)),
        ),
      failure: (response) => messageFor(response, 'Those changes could not be saved.'),
    });

    this.saving.set(false);

    if (!done) return;

    this.editing.set(false);
    this.notice.set(said);
    if (fresh) this.changed.emit(fresh);
  }

  /** The change in a sentence, for the confirmation. */
  private describe(settings: SeriesSettings): string {
    const said: string[] = [];

    if (settings.count) said.push(`It runs for ${settings.count} dates in all.`);
    else if (settings.until) said.push(`Its last night is on ${this.day(settings.until)}.`);
    else if ('count' in settings || 'until' in settings) said.push('It repeats until you stop it.');

    if (settings.auto_publish === true) {
      const days = settings.on_sale_days_before;
      said.push(
        days == null
          ? 'Each date goes on sale by itself as soon as it is added.'
          : `Each date goes on sale by itself ${days === 1 ? '1 day' : `${days} days`} before its night.`,
      );
    } else if (settings.auto_publish === false) {
      said.push('Its dates no longer go on sale by themselves.');
    }

    return said.join(' ');
  }
}
