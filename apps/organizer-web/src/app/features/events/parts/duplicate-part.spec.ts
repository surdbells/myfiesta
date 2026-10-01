import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { OrganizerEventDetail, TicketType } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../../core/api';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../../core/confirm-testing';
import { SessionStore } from '../../../core/session';
import { DuplicatePart } from './duplicate-part';

const BASE = 'http://api.test/api/organizer';

function event(overrides: Partial<OrganizerEventDetail> = {}): OrganizerEventDetail {
  return {
    id: 'ev-1',
    slug: 'afro-fest',
    title: 'Afro Fest',
    status: 'published',
    starts_at: '2026-11-14T02:00:00Z',
    ends_at: '2026-11-14T08:00:00Z',
    timezone: 'America/Toronto',
    description: '<p>Afrobeats until late.</p>',
    ...overrides,
  } as OrganizerEventDetail;
}

function tier(id: string, name: string, amount: number, quantity: number | null): TicketType {
  return {
    id,
    name,
    description: null,
    price: { amount, currency: 'CAD' },
    admits: 1,
    max_per_order: null,
    quantity_available: quantity,
    status: 'on_sale',
    sold: 0,
    remaining: quantity,
    sales_start_at: null,
    sales_end_at: null,
    sold_out: false,
    opens_after: null,
    waiting: false,
  };
}

/**
 * Copying with changes, and keeping the event as a template, on its Overview.
 *
 * The copy is said back before it is made, changes and all, and saying no
 * sends nothing. Somebody who may not create events is offered neither.
 */
describe('DuplicatePart', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => allowDialogs());

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
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

  async function render(shown: OrganizerEventDetail = event()) {
    const fixture = TestBed.createComponent(DuplicatePart);
    fixture.componentRef.setInput('event', shown);
    fixture.detectChanges();
    await settle();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function button(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
    );

    if (!found) throw new Error(`No "${label}" button in the part`);

    return found;
  }

  async function openCopy(fixture: Awaited<ReturnType<typeof render>>) {
    button(fixture, 'Copy with changes').click();
    await settle();

    backend
      .expectOne(`${BASE}/events/ev-1/ticket-types`)
      .flush({ data: [tier('ga', 'General', 2500, 100), tier('vip', 'VIP', 6000, null)] });
    fixture.detectChanges();
    await settle();
  }

  it('offers nothing to somebody who may not create events', async () => {
    signIn(['events.view', 'events.edit']);

    const fixture = await render();

    expect(text(fixture)).toBe('');
  });

  it('copies with the changes made, saying them back first', async () => {
    signIn(['events.view', 'events.create']);

    const fixture = await render();
    await openCopy(fixture);

    const dialog = fixture.componentInstance;
    expect(dialog.copyOpen()).toBe(true);
    expect(dialog.tiers().map((t) => t.name)).toEqual(['General', 'VIP']);

    const form = fixture.debugElement.query((node) => node.name === 'app-copy-dialog').componentInstance;
    form.starts.set('2026-12-05T21:00');
    form.draftTitle.set('Afro Fest: Winter');
    form.updateRow('vip', { include: false });
    form.updateRow('ga', { price: '30' });
    form.submit();
    await settle();

    const question = asked();
    expect(question?.title).toContain('Make Afro Fest: Winter on');
    expect(question?.text).toContain('Left out: VIP.');
    expect(question?.text).toContain('General: $25.00 → $30.00.');
    expect(question?.text).toContain('Sales, codes and the gallery stay with this one.');

    await answer('Make the copy');

    const request = backend.expectOne(`${BASE}/events/ev-1/duplicate`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      starts_at: '2026-12-06T02:00:00.000Z',
      title: 'Afro Fest: Winter',
      include: { add_ons: true, questions: true, reminders: true },
      ticket_types: [
        { id: 'ga', price_amount: 3000 },
        { id: 'vip', include: false },
      ],
    });
    request.flush({ id: 'ev-2', slug: 'afro-fest-winter', title: 'Afro Fest: Winter', starts_at: '2026-12-06T02:00:00Z', status: 'draft' });
    await settle();
    fixture.detectChanges();

    expect(dialog.copyOpen()).toBe(false);
    expect(text(fixture)).toContain('Afro Fest: Winter is in your events list as a draft.');
    expect(fixture.nativeElement.querySelector('a[href="/events/ev-2"]')).not.toBeNull();
  });

  it('sends nothing when the copy is not confirmed', async () => {
    signIn(['events.view', 'events.create']);

    const fixture = await render();
    await openCopy(fixture);

    const form = fixture.debugElement.query((node) => node.name === 'app-copy-dialog').componentInstance;
    form.starts.set('2026-12-05T21:00');
    form.submit();
    await settle();

    expect(asked()?.text).toContain('Nothing else changes: it is this event on a new date.');

    await answer('Cancel');

    backend.expectNone(`${BASE}/events/ev-1/duplicate`);
    expect(fixture.componentInstance.copyOpen()).toBe(true);
  });

  it('keeps the event as a template under the name given', async () => {
    signIn(['events.view', 'events.create']);

    const fixture = await render();

    button(fixture, 'Keep as a template').click();
    fixture.detectChanges();
    expect(fixture.componentInstance.templateName()).toBe('Afro Fest');

    fixture.componentInstance.templateName.set('  Friday night  ');
    void fixture.componentInstance.keep();
    await settle();

    const request = backend.expectOne(`${BASE}/templates`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ from_event_id: 'ev-1', name: 'Friday night' });
    request.flush({ data: { id: 'tpl-1', name: 'Friday night' } });
    await settle();
    fixture.detectChanges();

    expect(text(fixture)).toContain('Kept as “Friday night”.');
    expect(fixture.nativeElement.querySelector('a[href="/templates"]')).not.toBeNull();
  });

  it('says why a template could not be kept, in the API’s words', async () => {
    signIn(['events.view', 'events.create']);

    const fixture = await render();

    button(fixture, 'Keep as a template').click();
    fixture.detectChanges();
    void fixture.componentInstance.keep();
    await settle();

    backend.expectOne(`${BASE}/templates`).flush(
      // As Laravel refuses a field: its first message leads.
      { message: 'You already have a template called “Afro Fest”.', errors: { name: ['You already have a template called “Afro Fest”.'] } },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await settle();
    fixture.detectChanges();

    expect(fixture.componentInstance.naming()).toBe(true);
    expect(text(fixture)).toContain('You already have a template called “Afro Fest”.');
  });
});
