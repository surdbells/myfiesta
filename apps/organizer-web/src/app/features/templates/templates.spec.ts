import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { EventTemplate } from '@myfiesta/api-types';
import { API_BASE_URL } from '../../core/api';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { SessionStore } from '../../core/session';
import { Templates } from './templates';

const BASE = 'http://api.test/api/organizer/templates';

function template(overrides: Partial<EventTemplate> = {}): EventTemplate {
  return {
    id: 'tpl-1',
    name: 'Friday night',
    title: 'Afro Fest',
    description: '<p>Afrobeats until late.</p>',
    currency: 'CAD',
    timezone: 'America/Toronto',
    city: 'Toronto',
    original_starts_at: '2026-09-12T01:00:00Z',
    length_minutes: 360,
    poster_url: null,
    ticket_types: [
      { id: 'ga', name: 'General', price: { amount: 2500, currency: 'CAD' }, quantity_available: 100 },
      { id: 'vip', name: 'VIP', price: { amount: 6000, currency: 'CAD' }, quantity_available: null },
    ],
    add_ons: 2,
    questions: 1,
    reminders: 0,
    created_by: 'Ada Okafor',
    created_at: '2026-09-20T15:00:00Z',
    ...overrides,
  };
}

/**
 * The Templates screen: what each template keeps, a new event made from one
 * with its own date (said back first), and a delete that asks first and
 * says events made from it keep everything.
 */
describe('Templates', () => {
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

    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [
        { id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'manager', permissions: ['events.view', 'events.create'] },
      ],
    } as Parameters<SessionStore['start']>[0]);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
    session.clear();
  });

  async function render(answerWith: EventTemplate[] | 'refused') {
    const fixture = TestBed.createComponent(Templates);
    fixture.detectChanges();
    await settle();

    const request = backend.expectOne(BASE);
    if (answerWith === 'refused') request.flush({ message: 'No.' }, { status: 403, statusText: 'Forbidden' });
    else request.flush({ data: answerWith });

    await settle();
    fixture.detectChanges();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  function button(fixture: { nativeElement: HTMLElement }, label: string): HTMLButtonElement {
    const found = [...fixture.nativeElement.querySelectorAll<HTMLButtonElement>('button')].find(
      (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
    );

    if (!found) throw new Error(`No "${label}" button on the screen`);

    return found;
  }

  it('lists what each template keeps', async () => {
    const fixture = await render([template()]);

    expect(text(fixture)).toContain('Friday night');
    expect(text(fixture)).toContain('Afro Fest');
    expect(text(fixture)).toContain('General $25.00 · VIP $60.00');
    expect(text(fixture)).toContain('2 extras, 1 question');
    expect(text(fixture)).not.toContain('reminder');
    expect(text(fixture)).toContain('Kept by Ada Okafor on');
  });

  it('says how to keep one when there are none', async () => {
    const fixture = await render([]);

    expect(text(fixture)).toContain('No templates yet');
    expect(text(fixture)).toContain('Keep as a template');
  });

  it('tells a role that cannot create events why there is nothing here', async () => {
    const fixture = await render('refused');

    expect(text(fixture)).toContain('Your role does not create events');
  });

  it('makes a draft from a template at the date chosen, saying it back first', async () => {
    const fixture = await render([template()]);

    button(fixture, 'Make an event').click();
    fixture.detectChanges();
    await settle();

    const form = fixture.debugElement.query((node) => node.name === 'app-copy-dialog').componentInstance;
    expect(form.draftTitle()).toBe('Afro Fest');

    form.starts.set('2026-12-05T21:00');
    form.questions.set(false);
    form.submit();
    await settle();

    const question = asked();
    expect(question?.title).toContain('Make Afro Fest on');
    expect(question?.text).toContain('the template “Friday night”');
    expect(question?.text).toContain('No questions on the new one.');

    await answer('Make the event');

    const request = backend.expectOne(`${BASE}/tpl-1/events`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      starts_at: '2026-12-06T02:00:00.000Z',
      title: 'Afro Fest',
      include: { add_ons: true, questions: false, reminders: true },
    });
    request.flush({ id: 'ev-9', slug: 'afro-fest-2', title: 'Afro Fest', starts_at: '2026-12-06T02:00:00Z', status: 'draft' });
    await settle();
    fixture.detectChanges();

    expect(fixture.componentInstance.using()).toBeNull();
    expect(text(fixture)).toContain('Afro Fest is in your events list as a draft.');
    expect(fixture.nativeElement.querySelector('a[href="/events/ev-9"]')).not.toBeNull();
  });

  it('asks before deleting one, and reads the list again after', async () => {
    const fixture = await render([template()]);

    button(fixture, 'Delete').click();
    await settle();

    const question = asked();
    expect(question?.title).toBe('Delete the template “Friday night”?');
    expect(question?.text).toContain('Events already made from it keep everything they have.');

    await answer('Delete template');

    const request = backend.expectOne(`${BASE}/tpl-1`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
    await settle();

    backend.expectOne(BASE).flush({ data: [] });
    await settle();
    fixture.detectChanges();

    expect(text(fixture)).toContain('Deleted “Friday night”.');
    expect(text(fixture)).toContain('No templates yet');
  });

  it('deletes nothing when the answer is no', async () => {
    const fixture = await render([template()]);

    button(fixture, 'Delete').click();
    await settle();
    await answer('Cancel');

    backend.expectNone(`${BASE}/tpl-1`);
    expect(text(fixture)).toContain('Friday night');
  });
});
