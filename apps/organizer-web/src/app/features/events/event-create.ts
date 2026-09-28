import { Component, computed, inject, signal } from '@angular/core';
import { ConfirmDialog, UiButton, UiSelect, type SelectOption } from '@myfiesta/ui';
import { RichTextEditor } from '../../shared/rich-text-editor';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { COUNTRIES, COUNTRY_OPTIONS, PROVINCE_OPTIONS, categoryOptions } from '../../core/places';
import { COMMON_ZONES, describeZone, localZone, zonedWallClockToIso } from '../../core/zoned-time';

/** Where a new event is, until the organizer says otherwise. */
const DEFAULT_COUNTRY = 'CA';

@Component({
  selector: 'app-event-create',
  imports: [FormsModule, RouterLink, UiButton, UiSelect, RichTextEditor],
  templateUrl: './event-create.html',
})
export class EventCreate {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);

  readonly countryOptions = COUNTRY_OPTIONS;
  readonly provinceOptions = PROVINCE_OPTIONS;
  readonly describe = describeZone;

  readonly kindOptions: SelectOption[] = [
    { value: 'ticketed', label: 'Ticketed — sold publicly' },
    { value: 'invitation', label: 'Invitation — guests you invite' },
  ];

  readonly categories = signal<string[]>([]);
  readonly categoryChoices = computed(() => categoryOptions(this.categories()));

  /** The organizer's own zone first, then the ones we sell in. */
  readonly zones = computed(() => {
    const mine = localZone();

    return COMMON_ZONES.includes(mine as (typeof COMMON_ZONES)[number])
      ? [...COMMON_ZONES]
      : [mine, ...COMMON_ZONES];
  });

  readonly zoneOptions = computed<SelectOption[]>(() =>
    this.zones().map((zone) => ({ value: zone, label: describeZone(zone), hint: zone })),
  );

  readonly title = signal('');
  readonly kind = signal<'ticketed' | 'invitation'>('ticketed');
  readonly description = signal('');
  readonly country = signal(DEFAULT_COUNTRY);
  readonly subdivision = signal('ON');
  readonly city = signal('');
  /**
   * The default country's zone, as choosing that country sets it — not the
   * browser's. A Toronto night made on a laptop in Lagos otherwise started at
   * Lagos time unless somebody noticed the zone below the clock.
   */
  readonly timezone = signal(COUNTRIES.find((c) => c.code === DEFAULT_COUNTRY)?.zone ?? localZone());
  readonly startsAt = signal('');
  readonly endsAt = signal('');
  readonly category = signal('');
  readonly minAge = signal('');

  constructor() {
    this.api.eventCategories().subscribe({ next: ({ data }) => this.categories.set(data), error: () => undefined });
  }

  readonly saving = signal(false);
  readonly error = signal<string | null>(null);

  /** Derived, never chosen separately — and permanent once the event exists. */
  readonly currency = computed(
    () => COUNTRIES.find((c) => c.code === this.country())?.currency ?? 'CAD',
  );

  readonly needsProvince = computed(() => this.country() === 'CA');

  /**
   * What the organizer will actually have created.
   *
   * Shown back to them before saving because the wall clock they typed and the
   * zone they picked combine into an instant they cannot otherwise check, and
   * the currency is chosen for them by the country and can never be changed.
   */
  readonly preview = computed(() => {
    const iso = this.startsAt() ? zonedWallClockToIso(this.startsAt(), this.timezone()) : null;

    if (!iso) return null;

    return new Intl.DateTimeFormat('en-CA', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
      timeZone: this.timezone(),
    }).format(new Date(iso));
  });

  onCountryChange(code: string): void {
    this.country.set(code);

    const country = COUNTRIES.find((c) => c.code === code);

    if (country) {
      // A helpful default, not a lock — an Ontario company can run a Vancouver
      // night, and the zone stays editable.
      this.timezone.set(country.zone);
      this.subdivision.set(code === 'CA' ? 'ON' : '');
    }
  }

  async submit(): Promise<void> {
    if (this.saving()) return;

    const organization = this.session.current();

    if (!organization) {
      this.error.set('No organization selected.');

      return;
    }

    const startsAt = zonedWallClockToIso(this.startsAt(), this.timezone());

    if (!startsAt) {
      this.error.set('Choose when the event starts.');

      return;
    }

    const endsAt = this.endsAt() ? zonedWallClockToIso(this.endsAt(), this.timezone()) : null;
    const title = this.title().trim();

    // The two things that cannot be taken back once it exists — the currency
    // the country chose, and the instant the clock and zone make — said at
    // the moment of creating it, not only in the preview above the button.
    const sure = await this.confirmDialog.confirm({
      title: `Create ${title || 'this event'}?`,
      body: `It is saved as a draft for ${organization.name}${this.preview() ? `, starting ${this.preview()}` : ''}. Nobody can see it or buy a ticket until you send it for review and it is approved.`,
      consequences: [`Its tickets are sold in ${this.currency()}, and that cannot be changed later.`],
      confirmLabel: 'Create the draft',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.error.set(null);

    this.api
      .createEvent({
        organization_id: organization.id,
        title,
        kind: this.kind(),
        description: this.description().trim() || null,
        currency: this.currency(),
        starts_at: startsAt,
        ends_at: endsAt,
        timezone: this.timezone(),
        city: this.city().trim(),
        subdivision: this.needsProvince() ? this.subdivision() : null,
        country: this.country(),
        category: this.category().trim() || null,
        min_age: this.minAge() ? Number(this.minAge()) : null,
      })
      .subscribe({
        next: () => {
          // Back to the list rather than into the new event: the next thing to
          // do is add tickets, and the list is where the event is now visible
          // as a draft.
          void this.router.navigate(['/events']);
        },
        error: (response) => {
          this.saving.set(false);
          this.error.set(messageFor(response, 'That event could not be created.'));
        },
      });
  }
}
