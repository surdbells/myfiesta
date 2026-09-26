import { Component, booleanAttribute, computed, input, model } from '@angular/core';
import type { OrganizerEventDetail } from '@myfiesta/api-types';
import { COMMON_ZONES, describeZone, isoToZonedWallClock, localZone, zonedWallClockToIso } from '@myfiesta/shared/zoned-time';
import { COUNTRIES, COUNTRY_OPTIONS, PROVINCE_OPTIONS, categoryOptions } from '@myfiesta/shared/places';
import { MfChoices, MfField, MfSelect, MfSwitch, type MfChoice, type MfOption } from '../../ui';

/** An event as its form holds it: wall-clock times in the event's own zone, text as typed. */
export interface EventDraft {
  title: string;
  kind: 'ticketed' | 'invitation';
  description: string;
  country: string;
  subdivision: string;
  city: string;
  timezone: string;
  startsAt: string;
  endsAt: string;
  category: string;
  minAge: string;
  resaleEnabled: boolean;
}

export function blankEvent(): EventDraft {
  return {
    title: '',
    kind: 'ticketed',
    description: '',
    country: 'CA',
    subdivision: 'ON',
    city: '',
    timezone: localZone(),
    startsAt: '',
    endsAt: '',
    category: '',
    minAge: '',
    resaleEnabled: false,
  };
}

export function draftOf(event: OrganizerEventDetail): EventDraft {
  return {
    title: event.title,
    kind: event.kind,
    description: plainText(event.description),
    country: event.country,
    subdivision: event.subdivision ?? '',
    city: event.city,
    timezone: event.timezone,
    startsAt: isoToZonedWallClock(event.starts_at, event.timezone) ?? '',
    endsAt: event.ends_at ? (isoToZonedWallClock(event.ends_at, event.timezone) ?? '') : '',
    category: event.category ?? '',
    minAge: event.min_age === null ? '' : String(event.min_age),
    resaleEnabled: event.resale_enabled,
  };
}

/**
 * What the API is sent. Times become instants in the event's zone — not the
 * phone's, which is wherever the organizer happens to be standing.
 */
export function bodyOf(draft: EventDraft): Record<string, unknown> {
  return {
    title: draft.title.trim(),
    starts_at: zonedWallClockToIso(draft.startsAt, draft.timezone),
    ends_at: draft.endsAt ? zonedWallClockToIso(draft.endsAt, draft.timezone) : null,
    timezone: draft.timezone,
    city: draft.city.trim(),
    subdivision: draft.country === 'CA' ? draft.subdivision : null,
    country: draft.country,
    category: draft.category.trim() || null,
    min_age: draft.minAge ? Number(draft.minAge) : null,
  };
}

/** Whether a description carries formatting a plain text box would lose: lists, bold, links, headings. */
export function isFormatted(html: string | null): boolean {
  return !!html && /<(?!\/?(p|br)\b)[a-z][^>]*>/i.test(html);
}

/**
 * A description's words, laid out as paragraphs.
 *
 * The phone edits descriptions as plain text. The server turns blank-line
 * paragraphs back into HTML, so a description that was only ever paragraphs
 * round-trips untouched; one with formatting is only sent when it is changed.
 */
export function plainText(html: string | null): string {
  if (!html) return '';

  const doc = new DOMParser().parseFromString(
    html
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|h2|h3|li|blockquote)>/gi, '\n\n')
      .replace(/<li[^>]*>/gi, '• '),
    'text/html',
  );

  return (doc.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim();
}

/**
 * The fields of an event, shared by creating one and changing one.
 *
 * Currency is never asked for: it follows the country, and once an event
 * exists it cannot change, because every order on it is in that currency.
 */
@Component({
  selector: 'mf-event-form',
  imports: [MfField, MfSelect, MfChoices, MfSwitch],
  template: `
    <div class="form">
      <mf-field label="Name" [limit]="160" [count]="draft().title.length" [error]="err('title')">
        <input [value]="draft().title" (input)="set('title', $any($event.target).value)" maxlength="160" placeholder="Detty December Warm-Up" />
      </mf-field>

      @if (creating()) {
        <mf-choices legend="Who can find it" [options]="kinds" [value]="draft().kind" (valueChange)="set('kind', $any($event))" />
      }

      <p class="group-label">When <span class="zone">{{ zoneName() }}</span></p>
      <mf-field label="Starts" [error]="err('starts_at')">
        <input type="datetime-local" [value]="draft().startsAt" (input)="set('startsAt', $any($event.target).value)" />
      </mf-field>
      <mf-field label="Ends" optional [error]="err('ends_at')">
        <input type="datetime-local" [value]="draft().endsAt" [min]="draft().startsAt" (input)="set('endsAt', $any($event.target).value)" />
      </mf-field>
      @if (preview(); as p) {
        <p class="preview">{{ p }}</p>
      }
      <mf-select heading="Time zone" subheading="Where the doors are, not where you are." [options]="zoneOptions()" [value]="draft().timezone" (valueChange)="set('timezone', $event ?? draft().timezone)" />

      <p class="group-label">Where</p>
      <mf-select
        heading="Country"
        [subheading]="creating() ? 'Sets the currency — ' + currency() + ' — which cannot change later.' : null"
        [options]="countries"
        [value]="draft().country"
        (valueChange)="country($event)"
      />
      @if (draft().country === 'CA') {
        <mf-select heading="Province" subheading="Sets the sales tax at checkout." [options]="provinces" [value]="draft().subdivision" (valueChange)="set('subdivision', $event ?? '')" />
      }
      <mf-field label="City" [error]="err('city')">
        <input [value]="draft().city" (input)="set('city', $any($event.target).value)" placeholder="Toronto" maxlength="120" />
      </mf-field>

      <p class="group-label">About it</p>
      <mf-select heading="Category" [options]="categoryChoices()" [value]="draft().category" (valueChange)="set('category', $event ?? '')" />
      <mf-field label="Description" optional [hint]="formatted() ? 'Written with formatting on the web. Changing it here keeps the words and drops the formatting.' : 'A blank line starts a new paragraph.'" [limit]="20000" [count]="draft().description.length" [error]="err('description')">
        <textarea class="tall" [value]="draft().description" (input)="set('description', $any($event.target).value)" placeholder="Who is playing, what to wear, how to get there."></textarea>
      </mf-field>
      <mf-field label="Minimum age" optional suffix="and over" [error]="err('min_age')">
        <input inputmode="numeric" [value]="draft().minAge" (input)="set('minAge', $any($event.target).value)" placeholder="All ages" />
      </mf-field>

      @if (!creating()) {
        <mf-switch
          label="Let people give tickets back"
          hint="The ticket stops working at once, the place goes back on sale at your price, and they are paid when it sells. Returns close 24 hours before the doors."
          [checked]="draft().resaleEnabled"
          (changed)="set('resaleEnabled', $event)"
        />
      }
    </div>
  `,
  styles: `
    .form {
      display: grid;
      gap: var(--space-4);
    }

    .group-label {
      margin-top: var(--space-3);
      font-family: var(--font-family-display);
      font-size: var(--font-size-lg);
      font-weight: var(--font-weight-semibold);
    }

    .zone {
      font-family: var(--font-family-sans);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-regular);
      color: var(--text-subtle);
    }

    .preview {
      margin-top: calc(var(--space-2) * -1);
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: var(--primary-soft);
      color: var(--primary-soft-text);
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-medium);
    }

    .tall {
      min-height: 9rem;
    }
  `,
})
export class MfEventForm {
  readonly draft = model.required<EventDraft>();
  readonly errors = input<Record<string, string>>({});
  readonly categories = input<string[]>([]);
  readonly creating = input(false, { transform: booleanAttribute });
  /** The description as the server has it, to tell whether formatting would be lost. */
  readonly original = input<string | null>(null);

  protected readonly countries = COUNTRY_OPTIONS;
  protected readonly provinces = PROVINCE_OPTIONS;

  protected readonly kinds: MfChoice[] = [
    { value: 'ticketed', label: 'Anyone', hint: 'Sold publicly and listed in What’s on.' },
    { value: 'invitation', label: 'Only people you invite', hint: 'Never listed or searchable.' },
  ];

  protected readonly currency = computed(() => COUNTRIES.find((c) => c.code === this.draft().country)?.currency ?? 'CAD');
  protected readonly formatted = computed(() => isFormatted(this.original()));

  protected readonly categoryChoices = computed<MfOption[]>(() => categoryOptions(this.categories(), this.draft().category || null));

  protected readonly zoneOptions = computed<MfOption[]>(() => {
    const zones: string[] = [...COMMON_ZONES];
    for (const extra of [localZone(), this.draft().timezone]) if (!zones.includes(extra)) zones.unshift(extra);
    return zones.map((zone) => ({ value: zone, label: describeZone(zone), hint: zone }));
  });

  protected readonly zoneName = computed(() => describeZone(this.draft().timezone));

  /** The instant the organizer typed, said back in full with its zone — the one thing they cannot otherwise check. */
  protected readonly preview = computed(() => {
    const d = this.draft();
    const iso = d.startsAt ? zonedWallClockToIso(d.startsAt, d.timezone) : null;
    if (!iso) return null;

    return new Intl.DateTimeFormat(undefined, {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      hour: 'numeric',
      minute: '2-digit',
      timeZoneName: 'short',
      timeZone: d.timezone,
    }).format(new Date(iso));
  });

  protected err(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  protected set<K extends keyof EventDraft>(key: K, value: EventDraft[K]): void {
    this.draft.update((d) => ({ ...d, [key]: value }));
  }

  protected country(code: string | null): void {
    if (!code) return;

    const country = COUNTRIES.find((c) => c.code === code);

    // A default, not a lock: an Ontario company can run a Vancouver night.
    this.draft.update((d) => ({
      ...d,
      country: code,
      timezone: country && this.creating() ? country.zone : d.timezone,
      subdivision: code === 'CA' ? d.subdivision || 'ON' : '',
    }));
  }
}
