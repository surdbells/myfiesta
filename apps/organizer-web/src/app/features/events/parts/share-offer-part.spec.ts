import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { OrganizerEventDetail, OrganizerShareOffer } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../../core/confirm-testing';
import { SessionStore } from '../../../core/session';
import { ShareOfferPart } from './share-offer-part';

const BASE = 'http://api.test/api/organizer/events/ev-1';

function offer(overrides: Partial<OrganizerShareOffer> = {}): OrganizerShareOffer {
  return { discount_bps: null, max_rewards: 5, max_bps: 2000, links: 0, friend_orders: 0, rewards: 0, ...overrides };
}

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    status: 'published',
    share_offer: offer(),
    ...overrides,
  } as OrganizerEventDetail;
}

/**
 * Friend buys, both save, on the Overview: the organizer starts, changes and
 * ends the offer, and is asked first, in so many words, to fund both
 * discounts. Somebody without the codes permission sees how it is doing and
 * nothing to press.
 */
describe('ShareOfferPart', () => {
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

  async function render(shown: OrganizerEventDetail) {
    const fixture = TestBed.createComponent(ShareOfferPart);
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

  async function type(fixture: { nativeElement: HTMLElement; detectChanges(): void }, id: string, value: string) {
    const input = fixture.nativeElement.querySelector<HTMLInputElement>(`#${id}`)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await settle();
  }

  async function submit(fixture: { nativeElement: HTMLElement; detectChanges(): void }) {
    fixture.nativeElement.querySelector<HTMLFormElement>('form.share-form')!.dispatchEvent(new Event('submit'));
    fixture.detectChanges();
    await settle();
  }

  it('starts an offer only once the organizer agrees to fund both discounts', async () => {
    signIn(['events.view', 'codes.manage']);
    const { fixture, emitted } = await render(event());

    button(fixture, 'Offer a friend discount').click();
    fixture.detectChanges();
    await settle();

    await type(fixture, 'sharePercent', '15');
    await type(fixture, 'shareRewards', '3');
    await submit(fixture);

    const question = asked()!;
    expect(question.title).toBe('Offer friends 15% off?');
    expect(question.text).toContain('You fund both discounts; they come off your proceeds.');
    expect(question.text).toContain('up to 3 per link');
    backend.expectNone(`${BASE}/share-offer`);

    await answer('Offer 15% off');

    const put = backend.expectOne(`${BASE}/share-offer`);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ discount_bps: 1500, max_rewards: 3 });
    put.flush({ share_offer: offer({ discount_bps: 1500, max_rewards: 3 }) });
    await settle();

    const fresh = event({ share_offer: offer({ discount_bps: 1500, max_rewards: 3 }) });
    backend.expectOne(BASE).flush(fresh);
    await settle();

    expect(emitted).toEqual([fresh]);
  });

  it('sends nothing when the organizer says no', async () => {
    signIn(['events.view', 'codes.manage']);
    const { fixture, emitted } = await render(event());

    button(fixture, 'Offer a friend discount').click();
    fixture.detectChanges();
    await settle();
    await type(fixture, 'sharePercent', '10');
    await submit(fixture);

    await answer('Cancel');

    backend.expectNone(`${BASE}/share-offer`);
    expect(emitted).toEqual([]);
  });

  it('says the cap before asking, rather than confirming a number the server refuses', async () => {
    signIn(['events.view', 'codes.manage']);
    const { fixture } = await render(event());

    button(fixture, 'Offer a friend discount').click();
    fixture.detectChanges();
    await settle();
    await type(fixture, 'sharePercent', '25');
    await submit(fixture);

    expect(asked()).toBeNull();
    expect(text(fixture)).toContain('A friend discount is from 1% to 20%.');
  });

  it('ends a running offer, saying what already went out stands', async () => {
    signIn(['events.view', 'codes.manage']);
    const running = event({ share_offer: offer({ discount_bps: 1500, links: 12, friend_orders: 4, rewards: 3 }) });
    const { fixture, emitted } = await render(running);

    expect(text(fixture)).toContain('Friends save 15% through a buyer');
    const counts = [...fixture.nativeElement.querySelectorAll('.share-counts div')].map((row) =>
      [...(row as HTMLElement).children].map((cell) => cell.textContent?.trim()).join(': '),
    );
    expect(counts).toEqual(['Links handed out: 12', 'Friends who bought: 4', 'Rewards sent: 3']);

    button(fixture, 'End the offer').click();
    await settle();

    expect(asked()!.text).toContain('Reward codes already sent keep working until they expire.');
    await answer('End the offer');

    const put = backend.expectOne(`${BASE}/share-offer`);
    expect(put.request.body).toEqual({ discount_bps: null, max_rewards: 5 });
    put.flush({ share_offer: offer({ links: 12, friend_orders: 4, rewards: 3 }) });
    await settle();

    backend.expectOne(BASE).flush(event({ share_offer: offer({ links: 12 }) }));
    await settle();

    expect(emitted.length).toBe(1);
  });

  it('shows how it is doing, and nothing to press, without the codes permission', async () => {
    signIn(['events.view']);
    const { fixture } = await render(event({ share_offer: offer({ discount_bps: 1000, links: 2 }) }));

    expect(text(fixture)).toContain('Friends save 10%');
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(0);
  });

  it('says when myFiesta has friend discounts switched off', async () => {
    signIn(['events.view', 'codes.manage']);
    const { fixture } = await render(event({ share_offer: offer({ max_bps: 0 }) }));

    expect(text(fixture)).toContain('Friend discounts are switched off on myFiesta at the moment.');
    expect(fixture.nativeElement.querySelectorAll('button').length).toBe(0);
  });

  it('says when the platform holds a running offer to a lower figure', async () => {
    signIn(['events.view']);
    const { fixture } = await render(event({ share_offer: offer({ discount_bps: 2000, max_bps: 1500 }) }));

    expect(text(fixture)).toContain("myFiesta's largest friend discount is now 15%, so friends save 15% until you change the offer.");
  });

  it('draws nothing for an event read before the API said', async () => {
    signIn(['events.view', 'codes.manage']);
    const { fixture } = await render(event({ share_offer: undefined }));

    expect(text(fixture).trim()).toBe('');
  });
});
