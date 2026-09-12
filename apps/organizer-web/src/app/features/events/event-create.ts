import { Component, computed, inject, signal } from '@angular/core';
import { UiButton } from '@myfiesta/ui';
import { RichTextEditor } from '../../shared/rich-text-editor';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { COMMON_ZONES, describeZone, localZone, zonedWallClockToIso } from '../../core/zoned-time';

/** Currency follows the country, because in practice it always does. */
const COUNTRIES = [
  { code: 'CA', name: 'Canada', currency: 'CAD' as const, zone: 'America/Toronto' },
  { code: 'NG', name: 'Nigeria', currency: 'NGN' as const, zone: 'Africa/Lagos' },
];

/** Canada charges tax by province, so the province is not optional there. */
const PROVINCES = [
  { code: 'AB', name: 'Alberta' },
  { code: 'BC', name: 'British Columbia' },
  { code: 'MB', name: 'Manitoba' },
  { code: 'NB', name: 'New Brunswick' },
  { code: 'NL', name: 'Newfoundland and Labrador' },
  { code: 'NS', name: 'Nova Scotia' },
  { code: 'NT', name: 'Northwest Territories' },
  { code: 'NU', name: 'Nunavut' },
  { code: 'ON', name: 'Ontario' },
  { code: 'PE', name: 'Prince Edward Island' },
  { code: 'QC', name: 'Quebec' },
  { code: 'SK', name: 'Saskatchewan' },
  { code: 'YT', name: 'Yukon' },
];

@Component({
  selector: 'app-event-create',
  imports: [FormsModule, RouterLink, UiButton, RichTextEditor],
  templateUrl: './event-create.html',
})
export class EventCreate {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  readonly countries = COUNTRIES;
  readonly provinces = PROVINCES;
  readonly describe = describeZone;

  /** The organizer's own zone first, then the ones we sell in. */
  readonly zones = computed(() => {
    const mine = localZone();

    return COMMON_ZONES.includes(mine as (typeof COMMON_ZONES)[number])
      ? [...COMMON_ZONES]
      : [mine, ...COMMON_ZONES];
  });

  readonly title = signal('');
  readonly kind = signal<'ticketed' | 'invitation'>('ticketed');
  readonly description = signal('');
  readonly country = signal('CA');
  readonly subdivision = signal('ON');
  readonly city = signal('');
  readonly timezone = signal(localZone());
  readonly startsAt = signal('');
  readonly endsAt = signal('');
  readonly category = signal('');
  readonly minAge = signal('');

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

  submit(): void {
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

    this.saving.set(true);
    this.error.set(null);

    this.api
      .createEvent({
        organization_id: organization.id,
        title: this.title().trim(),
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
