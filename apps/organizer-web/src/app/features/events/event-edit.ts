import { Component, computed, inject, signal } from '@angular/core';
import { ConfirmDialog, UiButton, UiSelect, type SelectOption } from '@myfiesta/ui';
import { RichTextEditor } from '../../shared/rich-text-editor';
import { PayLaterPart } from './parts/pay-later-part';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { OrganizerEventDetail } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { COUNTRY_OPTIONS, PROVINCE_OPTIONS, categoryOptions } from '../../core/places';
import {
  COMMON_ZONES,
  describeZone,
  isoToZonedWallClock,
  localZone,
  zonedWallClockToIso,
} from '../../core/zoned-time';

/**
 * Editing an event after it exists.
 *
 * The gap that mattered most: an event created with a typo in the title could
 * only be fixed in the database. The API has accepted a PATCH since Phase 4 and
 * nothing could send one.
 *
 * Two fields are deliberately absent. Currency cannot change, because orders
 * snapshot theirs and an event switching underneath them leaves the ledger
 * unable to explain itself — the API refuses it regardless. The slug cannot
 * change either: it is in shared messages, bios and printed QR codes, and
 * editing it breaks every link an organizer has handed out.
 */
@Component({
  selector: 'app-event-edit',
  imports: [FormsModule, UiButton, UiSelect, RichTextEditor, PayLaterPart],
  templateUrl: './event-edit.html',
})
export class EventEdit {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly eventId = eventIdFrom(this.route);
  readonly countryOptions = COUNTRY_OPTIONS;
  readonly provinceOptions = PROVINCE_OPTIONS;

  readonly categories = signal<string[]>([]);
  readonly categoryChoices = computed(() => categoryOptions(this.categories(), this.event()?.category ?? null));
  readonly describe = describeZone;

  /**
   * The list always contains the event's own zone.
   *
   * On the create form the list can be the launch markets plus wherever the
   * organizer is. Here it cannot: an event already set to a zone missing from
   * the list would render a select with nothing selected, and saving that form
   * would quietly move the event into whichever zone happened to be first.
   */
  readonly zones = computed(() => {
    const wanted = [this.form().timezone, localZone()].filter(Boolean);

    return [...new Set([...wanted, ...COMMON_ZONES])];
  });

  readonly zoneOptions = computed<SelectOption[]>(() =>
    this.zones().map((zone) => ({ value: zone, label: describeZone(zone), hint: zone })),
  );

  readonly event = signal<OrganizerEventDetail | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);

  readonly form = signal({
    title: '',
    description: '',
    country: 'CA',
    subdivision: 'ON',
    city: '',
    timezone: 'UTC',
    startsAt: '',
    endsAt: '',
    category: '',
    minAge: '',
    resaleEnabled: false,
  });

  readonly needsProvince = computed(() => this.form().country === 'CA');

  /** What the organizer will have after saving, echoed back before they do. */
  readonly preview = computed(() => {
    const { startsAt, timezone } = this.form();
    const iso = startsAt ? zonedWallClockToIso(startsAt, timezone) : null;

    if (!iso) return null;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
      timeZone: timezone,
    }).format(new Date(iso));
  });

  constructor() {
    this.api.eventCategories().subscribe({ next: ({ data }) => this.categories.set(data), error: () => undefined });

    this.api.event(this.eventId).subscribe({
      next: (event) => {
        this.event.set(event);
        this.loading.set(false);

        this.form.set({
          title: event.title,
          description: event.description ?? '',
          country: event.country ?? 'CA',
          subdivision: event.subdivision ?? 'ON',
          city: event.city,
          timezone: event.timezone,
          // Converted back into the wall clock they originally typed. Showing
          // the instant in any other zone and then saving would move the event
          // by the difference.
          startsAt: isoToZonedWallClock(event.starts_at, event.timezone) ?? '',
          endsAt: event.ends_at ? (isoToZonedWallClock(event.ends_at, event.timezone) ?? '') : '',
          category: event.category ?? '',
          minAge: event.min_age === null ? '' : String(event.min_age),
          resaleEnabled: event.resale_enabled,
        });
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load this event.'));
      },
    });
  }

  async submit(): Promise<void> {
    if (this.saving()) return;

    const form = this.form();
    const startsAt = zonedWallClockToIso(form.startsAt, form.timezone);

    if (!startsAt) {
      this.error.set('Choose when the event starts.');

      return;
    }

    if (!(await this.confirmSave(form.title.trim(), startsAt)) || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .updateEvent(this.eventId, {
        title: form.title.trim(),
        description: form.description.trim() || null,
        starts_at: startsAt,
        ends_at: form.endsAt ? zonedWallClockToIso(form.endsAt, form.timezone) : null,
        timezone: form.timezone,
        city: form.city.trim(),
        subdivision: this.needsProvince() ? form.subdivision : null,
        country: form.country,
        category: form.category.trim() || null,
        min_age: form.minAge ? Number(form.minAge) : null,
        resale_enabled: form.resaleEnabled,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.notice.set('Saved.');
        },
        error: (response) => {
          this.saving.set(false);
          this.error.set(messageFor(response, 'Those changes could not be saved.'));
        },
      });
  }

  done(): void {
    void this.router.navigate(['/events', this.eventId]);
  }

  /**
   * What saving does to this event, by where it stands.
   *
   * On sale, an edit is what buyers read the moment it is saved, with no
   * review in between — and a moved date is not emailed to anybody who
   * already holds a ticket. Both are worth knowing at the moment of saving.
   */
  private confirmSave(title: string, startsAt: string): Promise<boolean> {
    const event = this.event();
    const moved = !!event && new Date(event.starts_at).getTime() !== new Date(startsAt).getTime();
    const onSale = event?.status === 'published';
    const consequences: string[] = [];

    if (moved) consequences.push(`It now starts ${this.preview() ?? 'at the new time'}.`);
    if (moved && (event?.tickets_issued ?? 0) > 0) {
      consequences.push('People who already hold tickets are not emailed about the new time. Tell them from Messages.');
    }
    if (onSale) consequences.push('Taking it off sale and putting it back later sends it through review, since it is no longer what was approved.');

    return this.confirmDialog.confirm({
      title: `Save the changes to ${title || 'this event'}?`,
      body: onSale
        ? 'It is on sale: the event page shows the changes straight away, to everybody who opens it.'
        : 'The changes are saved to the draft. Nobody sees them until it is approved and on sale.',
      consequences,
      confirmLabel: 'Save changes',
      tone: 'default',
    });
  }
}
