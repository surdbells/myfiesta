import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { OrganizerEventDetail, PayLaterSetting } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../../core/confirm-testing';
import { SessionStore } from '../../../core/session';
import { PayLaterPart } from './pay-later-part';

const BASE = 'http://api.test/api/organizer/events/ev-1';

function setting(overrides: Partial<PayLaterSetting> = {}): PayLaterSetting {
  return {
    enabled: false,
    available: true,
    offered_now: false,
    max_days_before_event: 110,
    currency: 'CAD',
    fees: {
      card: { bps: 290, flat: 30 },
      klarna: { bps: 599, flat: 30 },
      affirm: { bps: 600, flat: 30 },
    },
    ...overrides,
  };
}

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return { id: 'ev-1', slug: 'afro-fest', title: 'Afro Fest', status: 'published', pay_later_enabled: false, ...overrides } as OrganizerEventDetail;
}

/**
 * Pay over time, on the event's Settings: the organizer turns Klarna and
 * Affirm on only after being told, in figures, what the lenders charge over a
 * card and that they pay the difference. Somebody who cannot edit the event
 * sees where it stands and nothing to press.
 */
describe('PayLaterPart (console)', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => {
    // jsdom's <dialog> has no showModal(); the question only needs it not to throw.
    const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    dialog['showModal'] ??= function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    dialog['close'] ??= function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    };
  });

  beforeEach(() => {
    localStorage.clear();

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
  });

  function signIn(permissions: string[]): void {
    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [{ id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'manager', permissions }],
    } as Parameters<SessionStore['start']>[0]);
  }

  async function render(shown: OrganizerEventDetail, current: PayLaterSetting) {
    const fixture = TestBed.createComponent(PayLaterPart);
    const emitted: OrganizerEventDetail[] = [];
    fixture.componentInstance.changed.subscribe((fresh) => emitted.push(fresh));
    fixture.componentRef.setInput('event', shown);
    fixture.detectChanges();
    await settle();

    backend.expectOne(`${BASE}/pay-later`).flush(current);
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

  it('turns it on only once the organizer has been told what the lenders charge over a card', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture, emitted } = await render(event(), setting());

    button(fixture, 'Offer pay over time').click();
    fixture.detectChanges();
    await settle();

    const question = asked()!;
    expect(question.title).toBe('Let buyers pay over time?');
    expect(question.text).toContain('Klarna charges 5.99% + $0.30 and Affirm 6% + $0.30');
    expect(question.text).toContain('where a card costs 2.9% + $0.30');
    expect(question.text).toContain('You pay the difference, about 3.1% of each such order');
    expect(question.text).toContain('from 110 days before the night');
    backend.expectNone(`${BASE}/pay-later`);

    await answer('Offer pay over time');

    const put = backend.expectOne(`${BASE}/pay-later`);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ enabled: true });
    put.flush(setting({ enabled: true, offered_now: true }));
    await settle();

    const fresh = event({ pay_later_enabled: true });
    backend.expectOne(BASE).flush(fresh);
    await settle();
    fixture.detectChanges();

    expect(emitted).toEqual([fresh]);
    expect(text(fixture)).toContain('Buyers can now choose Klarna or Affirm on the payment page.');
  });

  it('sends nothing when the organizer says no', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture, emitted } = await render(event(), setting());

    button(fixture, 'Offer pay over time').click();
    fixture.detectChanges();
    await settle();
    await answer('Cancel');

    backend.expectNone(`${BASE}/pay-later`);
    expect(emitted).toEqual([]);
  });

  it('asks before it stops offering it, too', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture, emitted } = await render(event({ pay_later_enabled: true }), setting({ enabled: true, offered_now: true }));

    expect(text(fixture)).toContain('Buyers can choose Klarna or Affirm on the payment page.');

    button(fixture, 'Stop offering it').click();
    fixture.detectChanges();
    await settle();

    expect(asked()!.title).toBe('Stop offering pay over time?');
    await answer('Stop offering it');

    const put = backend.expectOne(`${BASE}/pay-later`);
    expect(put.request.body).toEqual({ enabled: false });
    put.flush(setting());
    await settle();
    backend.expectOne(BASE).flush(event());
    await settle();

    expect(emitted.length).toBe(1);
  });

  it('says when buyers will start seeing it for a night still far off', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture } = await render(event({ pay_later_enabled: true }), setting({ enabled: true, offered_now: false }));

    expect(text(fixture)).toContain('on the payment page from 110 days before the night');
  });

  it('shows somebody who cannot edit the event where it stands, and nothing to press', async () => {
    signIn(['events.view']);
    const { fixture } = await render(event({ pay_later_enabled: true }), setting({ enabled: true, offered_now: true }));

    expect(text(fixture)).toContain('Pay over time');
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(0);
  });

  it('draws nothing where myFiesta does not offer it and the night never had it', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture } = await render(event({ currency: 'NGN' } as Partial<OrganizerEventDetail>), setting({ available: false, currency: 'NGN' }));

    expect(text(fixture).trim()).toBe('');
  });

  it('still lets a night opted in before it was switched off be turned off', async () => {
    signIn(['events.view', 'events.edit']);
    const { fixture } = await render(event({ pay_later_enabled: true }), setting({ enabled: true, available: false }));

    expect(text(fixture)).toContain('switched off on myFiesta at the moment');
    expect(button(fixture, 'Stop offering it')).toBeTruthy();
  });
});
