import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OrganizerEventDetail, Series } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../../core/api';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../../core/confirm-testing';
import { SessionStore } from '../../../core/session';
import { SeriesPart } from './series-part';

const BASE = 'http://api.test/api/organizer/events/ev-1';

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fridays',
    title: 'Afro Fridays',
    status: 'published',
    starts_at: '2027-01-09T02:00:00Z',
    timezone: 'America/Toronto',
    ...overrides,
  } as OrganizerEventDetail;
}

function series(overrides: Partial<Series> = {}): Series {
  return {
    id: 'se-1',
    rrule: 'FREQ=WEEKLY;BYDAY=FR;COUNT=8',
    status: 'active',
    timezone: 'America/Toronto',
    generated_through: null,
    source_event_id: 'ev-1',
    frequency: 'weekly',
    count: 8,
    until: null,
    auto_publish: false,
    on_sale_days_before: null,
    occurrences: [],
    past_count: 0,
    skipped: [],
    ...overrides,
  };
}

/**
 * A repeating night's settings on the Overview: how it ends, and whether each
 * date goes on sale by itself. Shortening it says first that unsold dates
 * past the new end are deleted; how often it repeats is never offered.
 */
describe('SeriesPart', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => allowDialogs());

  beforeEach(() => {
    localStorage.clear();

    // The dates below are still to come only against a clock that stays put.
    // Date alone: the dialogs still settle on real timers.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-01T16:00:00Z'));

    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
    session.clear();
    vi.useRealTimers();
  });

  function signIn(permissions: string[]): void {
    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [{ id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'manager', permissions }],
    } as Parameters<SessionStore['start']>[0]);
  }

  async function render(shown: Series | null, onEvent: OrganizerEventDetail = event()) {
    const fixture = TestBed.createComponent(SeriesPart);
    const emitted: OrganizerEventDetail[] = [];
    fixture.componentInstance.changed.subscribe((fresh) => emitted.push(fresh));
    fixture.componentRef.setInput('event', onEvent);
    fixture.componentRef.setInput('series', shown);
    fixture.detectChanges();
    await settle();

    return { fixture, emitted };
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  async function press(fixture: { nativeElement: HTMLElement; detectChanges(): void }, label: string) {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
    );

    if (!found) throw new Error(`No "${label}" button. The part says: ${text(fixture)}`);

    found.click();
    fixture.detectChanges();
    await settle();
  }

  async function type(fixture: { nativeElement: HTMLElement; detectChanges(): void }, id: string, value: string) {
    const input = fixture.nativeElement.querySelector<HTMLInputElement>(`#${id}`)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await settle();
  }

  async function tick(fixture: { nativeElement: HTMLElement; detectChanges(): void }, selector: string) {
    fixture.nativeElement.querySelector<HTMLInputElement>(selector)!.click();
    fixture.detectChanges();
    await settle();
  }

  async function submit(fixture: { nativeElement: HTMLElement; detectChanges(): void }) {
    fixture.nativeElement.querySelector<HTMLFormElement>('form.series-form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await settle();
  }

  it('draws nothing for a night that does not repeat, or one that has stopped', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);

    expect((await render(null)).fixture.nativeElement.children.length).toBe(0);
    expect((await render(series({ status: 'ended' }))).fixture.nativeElement.children.length).toBe(0);
  });

  it('says how it repeats and when its dates go on sale', async () => {
    signIn(['events.view']);
    const { fixture } = await render(
      series({
        auto_publish: true,
        on_sale_days_before: 14,
        occurrences: [
          {
            id: 'ev-2',
            slug: 'afro-fridays-2',
            title: 'Afro Fridays',
            starts_at: '2027-01-16T02:00:00Z',
            series_occurs_at: '2027-01-16T02:00:00Z',
            status: 'draft',
            moved: false,
            is_source: false,
            publish_at: '2027-01-01T17:00:00+00:00',
          },
        ],
      }),
    );

    expect(text(fixture)).toContain('Repeats every week, for 8 dates in all.');
    expect(text(fixture)).toContain('Each date goes on sale by itself 14 days before its night.');
    expect(text(fixture)).toContain('goes on sale Fri, Jan 1');
    // Nothing to press without the right to edit it.
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(0);
  });

  it('shortens the run only once told that unsold dates past the end are deleted', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture, emitted } = await render(series());

    await press(fixture, 'Change how it repeats');
    // Somebody who cannot put events on sale is not offered it.
    expect(text(fixture)).not.toContain('Each date goes on sale by itself');
    expect(text(fixture)).toContain('stop this series and start a new one');

    await type(fixture, 'seriesCount', '4');
    await submit(fixture);

    const question = asked()!;
    expect(question.title).toBe('Change how Afro Fridays repeats?');
    expect(question.text).toContain('It runs for 4 dates in all.');
    expect(question.text).toContain('Dates after the new end that nobody has bought a ticket for are deleted.');
    backend.expectNone(`${BASE}/series`);

    await answer('Save the changes');

    const patch = backend.expectOne(`${BASE}/series`);
    expect(patch.request.method).toBe('PATCH');
    expect(patch.request.body).toEqual({ count: 4 });
    patch.flush({ series: series({ count: 4 }), removed: 3, kept: 1, created: 0, message: 'It now runs for 4 dates. 3 dates after that were removed.' });
    await settle();

    backend.expectOne(BASE).flush(event());
    await settle();

    expect(emitted.length).toBe(1);
    expect(text(fixture)).toContain('3 dates after that were removed.');
  });

  it('ends it on a day', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture } = await render(series());

    await press(fixture, 'Change how it repeats');
    await tick(fixture, 'input[name="ending"][value="until"]');
    await type(fixture, 'seriesUntil', '2027-02-26');
    await submit(fixture);

    expect(asked()!.text).toContain('Its last night is on Friday, February 26, 2027.');
    await answer('Save the changes');

    expect(backend.expectOne(`${BASE}/series`).request.body).toEqual({ until: '2027-02-26' });
  });

  it('puts each date on sale by itself, so many days before, for somebody who may', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture } = await render(series());

    await press(fixture, 'Change how it repeats');
    await tick(fixture, 'input[name="autoPublish"]');
    await type(fixture, 'seriesDaysBefore', '14');
    await submit(fixture);

    const question = asked()!;
    expect(question.text).toContain('Each date goes on sale by itself 14 days before its night.');
    expect(question.text).toContain('Each date goes on sale as you.');
    await answer('Save the changes');

    expect(backend.expectOne(`${BASE}/series`).request.body).toEqual({ auto_publish: true, on_sale_days_before: 14 });
  });

  it('sends nothing when nothing changed', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture } = await render(series());

    await press(fixture, 'Change how it repeats');
    await submit(fixture);

    expect(asked()).toBeNull();
    backend.expectNone(`${BASE}/series`);
  });
});
