import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EventReviewState, OrganizerEventDetail } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../../core/api';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../../core/confirm-testing';
import { SessionStore } from '../../../core/session';
import { SchedulePart } from './schedule-part';

const BASE = 'http://api.test/api/organizer/events/ev-1';

function review(overrides: Partial<EventReviewState> = {}): EventReviewState {
  return {
    submitted_at: null,
    approved_at: null,
    on_submit: 'review',
    unchanged_since_approval: false,
    not_ready: [],
    rejection: null,
    history: [],
    ...overrides,
  } as EventReviewState;
}

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    status: 'draft',
    // 9pm in Toronto, Saturday 6 March 2027: months after the pinned clock.
    starts_at: '2027-03-07T02:00:00Z',
    timezone: 'America/Toronto',
    publish_at: null,
    review: review(),
    ...overrides,
  } as OrganizerEventDetail;
}

/**
 * Going on sale at a set time, on the Overview: the organizer picks the
 * venue's time, is told first what will happen then (on sale, or to review),
 * and can move it or stop waiting for it. Somebody who cannot put events on
 * sale sees that a time is set and nothing to press.
 */
describe('SchedulePart', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => allowDialogs());

  beforeEach(() => {
    localStorage.clear();

    // The part refuses a time gone by and hides once the night has started,
    // so the dates above only mean what they say against a clock that stays
    // put. Date alone: the dialogs still settle on real timers.
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

  async function render(shown: OrganizerEventDetail) {
    const fixture = TestBed.createComponent(SchedulePart);
    const emitted: OrganizerEventDetail[] = [];
    fixture.componentInstance.changed.subscribe((fresh) => emitted.push(fresh));
    fixture.componentRef.setInput('event', shown);
    fixture.detectChanges();
    await settle();

    return { fixture, emitted };
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function button(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
    );

    if (!found) throw new Error(`No "${label}" button. The part says: ${text(fixture)}`);

    return found;
  }

  async function press(fixture: { nativeElement: HTMLElement; detectChanges(): void }, label: string) {
    button(fixture, label).click();
    fixture.detectChanges();
    await settle();
  }

  async function chooseTime(fixture: { nativeElement: HTMLElement; detectChanges(): void }, value: string) {
    const input = fixture.nativeElement.querySelector<HTMLInputElement>('#publishAt')!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await settle();

    fixture.nativeElement.querySelector<HTMLFormElement>('form.schedule-form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await settle();
  }

  it('sets the venue time once the organizer has read that it goes to review then', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture, emitted } = await render(event());

    await press(fixture, 'Set a time to go on sale');
    expect(text(fixture)).toContain('Goes on sale at (Toronto');

    await chooseTime(fixture, '2027-02-01T10:00');

    const question = asked()!;
    expect(question.title).toMatch(/^Put Afro Fest on sale at Monday, February 1.*10:00.*EST\?$/);
    expect(question.text).toContain('sent to myFiesta the way Submit sends it');
    expect(question.text).toContain('It is sent as you.');
    backend.expectNone(BASE);

    await answer('Set the time');

    const patch = backend.expectOne(BASE);
    expect(patch.request.method).toBe('PATCH');
    // 10am in Toronto in February is 15:00 UTC.
    expect(new Date(patch.request.body.publish_at).toISOString()).toBe('2027-02-01T15:00:00.000Z');
    patch.flush({});
    await settle();

    const fresh = event({ publish_at: '2027-02-01T15:00:00+00:00' });
    backend.expectOne(BASE).flush(fresh);
    await settle();

    expect(emitted).toEqual([fresh]);
    fixture.componentRef.setInput('event', fresh);
    fixture.detectChanges();

    expect(text(fixture)).toContain('Set to go on sale by itself on');
    expect(text(fixture)).toContain('We email you when it does.');
  });

  it('says an approved night goes straight on sale at its time', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture } = await render(event({ publish_at: '2027-02-01T15:00:00+00:00', review: review({ on_submit: 'publish' }) }));

    expect(text(fixture)).toContain('Monday, February 1');
    expect(text(fixture)).toContain('myFiesta has approved it as it is, so it goes straight on sale then');
  });

  it('refuses a time after the night starts before asking anything', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture } = await render(event());

    await press(fixture, 'Set a time to go on sale');
    await chooseTime(fixture, '2027-03-08T10:00');

    expect(asked()).toBeNull();
    expect(text(fixture)).toContain('Choose a time before the event starts.');
  });

  it('stops waiting for the time only once asked', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const { fixture, emitted } = await render(event({ publish_at: '2027-02-01T15:00:00+00:00' }));

    await press(fixture, 'Stop waiting for this time');

    expect(asked()!.title).toMatch(/^Stop waiting for Monday, February 1/);
    await answer('Stop waiting');

    const patch = backend.expectOne(BASE);
    expect(patch.request.body).toEqual({ publish_at: null });
    patch.flush({});
    await settle();
    backend.expectOne(BASE).flush(event());
    await settle();

    expect(emitted.length).toBe(1);
    expect(text(fixture)).toContain('It no longer goes on sale by itself.');
  });

  it('shows somebody who cannot put events on sale the time, and nothing to press', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture } = await render(event({ publish_at: '2027-02-01T15:00:00+00:00' }));

    expect(text(fixture)).toContain('Set to go on sale by itself on');
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(0);
  });

  it('draws nothing for a night on sale, or for somebody who cannot set a time on a night with none', async () => {
    signIn(['events.view', 'events.edit', 'events.publish']);
    const onSale = await render(event({ status: 'published' }));
    expect(onSale.fixture.nativeElement.children.length).toBe(0);

    session.clear();
    signIn(['events.view']);
    const noRight = await render(event());
    expect(noRight.fixture.nativeElement.children.length).toBe(0);
  });
});
