import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { EventSurvey, SurveyResults } from '../../core/api.types';
import { EventFeedback } from './event-feedback';

const URL = 'http://api.test/api/organizer/events/ev-1/survey';

function survey(changes: Partial<EventSurvey> = {}): EventSurvey {
  return {
    enabled: true,
    organization_enabled: true,
    template_id: null,
    template: { id: 'tpl-mf', name: 'myFiesta survey', platform: true },
    send_delay_hours: 18,
    timezone: 'America/Toronto',
    ends_at: '2026-10-04T07:00:00Z',
    final_count_at: '2026-10-04T22:00:00Z',
    sends_at: '2026-10-05T01:00:00Z',
    sent_at: null,
    stopped_because: null,
    due_now: false,
    can_send_now: true,
    questions: [
      { id: 'recommend', type: 'nps', label: 'How likely are you to recommend this event to a friend?', options: [], required: true },
      { id: 'change', type: 'text', label: 'What should we change?', options: [], required: false },
    ],
    templates: [
      { id: 'tpl-mf', name: 'myFiesta survey', platform: true },
      { id: 'tpl-own', name: 'After a concert', platform: false },
    ],
    ...changes,
  };
}

function results(changes: Partial<SurveyResults> = {}): SurveyResults {
  return {
    sent_at: '2026-10-05T01:00:00Z',
    invited: 40,
    responded: 12,
    response_rate: 30,
    minimum: 5,
    enough: true,
    nps: { score: 25, promoters: 6, passives: 3, detractors: 3, answered: 12 },
    questions: [
      {
        id: 'door',
        type: 'rating5',
        label: 'How was getting in at the door?',
        answered: 12,
        shown: true,
        average: 2.4,
        distribution: [
          { label: '1', count: 4 },
          { label: '2', count: 3 },
          { label: '3', count: 2 },
          { label: '4', count: 2 },
          { label: '5', count: 1 },
        ],
        texts: null,
      },
      { id: 'venue', type: 'rating5', label: 'How was the venue?', answered: 3, shown: false, average: null, distribution: null, texts: null },
      { id: 'change', type: 'text', label: 'What should we change?', answered: 6, shown: true, average: null, distribution: null, texts: ['The queue was long', 'More water'] },
    ],
    insights: [
      {
        kind: 'lowest',
        tone: 'warning',
        title: 'Lowest rated: “How was getting in at the door?”, 2.4 out of 5.',
        action: 'Open the doors earlier or put a second phone on the door.',
        tab: 'door',
        word: null,
      },
    ],
    ...changes,
  };
}

/**
 * One event's survey on its Feedback tab: chosen and timed until it goes,
 * sent early only after asking, and read once it has — never with a name,
 * and never a figure from fewer answers than the API allows.
 */
describe('EventFeedback', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;

  beforeEach(() => {
    asked = [];
    yes = true;
    // The fixtures' times are fixed, so the clock is too: an hour after the door's final count.
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-04T23:00:00Z'));

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ id: 'ev-1' }) }, parent: null } },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    vi.useRealTimers();
  });

  function open(body: EventSurvey = survey()) {
    const fixture = TestBed.createComponent(EventFeedback);
    http.expectOne(URL).flush(body);
    fixture.detectChanges();
    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  it('says when it goes, in the event’s own time, and what it asks', () => {
    const fixture = open();

    // 01:00 UTC on the 5th is 9 p.m. on the 4th in Toronto.
    expect(text(fixture)).toContain('Goes');
    expect(text(fixture)).toMatch(/October 4/);
    expect(text(fixture)).toContain('18 hours after the event ends');
    expect(text(fixture)).toContain('The 2 questions people are asked');
    expect(text(fixture)).toContain('Would recommend, 0 to 10');
  });

  it('switching it off saves at once and says why it will not go', () => {
    const fixture = open();

    fixture.componentInstance.setEnabled(false);
    const request = http.expectOne(URL);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ enabled: false });
    request.flush(survey({ enabled: false, can_send_now: false, stopped_because: 'The survey is switched off for this event.' }));
    fixture.detectChanges();

    expect(text(fixture)).toContain('The survey is switched off for this event.');
    expect(text(fixture)).toContain('Nobody is asked about this event.');
  });

  it('chooses the organization’s own questions, or null for myFiesta’s', () => {
    const fixture = open();

    fixture.componentInstance.setTemplate('tpl-own');
    expect(http.expectOne(URL).request.body).toEqual({ template_id: 'tpl-own' });
  });

  it('sends early only after asking, and saying no sends nothing', async () => {
    yes = false;
    const fixture = open();

    await fixture.componentInstance.sendNow();
    expect(asked).toHaveLength(1);
    expect(asked[0].confirmLabel).toBe('Send the survey');
    expect(asked[0].consequences?.join(' ')).toContain('cannot be sent again');
    http.expectNone(`${URL}/send`);

    yes = true;
    await fixture.componentInstance.sendNow();
    const send = http.expectOne(`${URL}/send`);
    expect(send.request.method).toBe('POST');
    send.flush({ message: 'Sent to 40 people.', invited: 40, survey: survey({ sent_at: '2026-10-04T12:00:00Z', can_send_now: false }) });
    http.expectOne(`${URL}/results`).flush(results());
    fixture.detectChanges();

    expect(text(fixture)).toContain('Sent to 40 people.');
    expect(text(fixture)).toContain('The questions are fixed now.');
  });

  it('once sent, reads the answers, the score and what to do, holding back a figure from too few', () => {
    const fixture = open(survey({ sent_at: '2026-10-05T01:00:00Z', can_send_now: false }));
    http.expectOne(`${URL}/results`).flush(results());
    fixture.detectChanges();

    const page = text(fixture);
    expect(page).toContain('+25');
    expect(page).toContain('30%');
    expect(page).toContain('Lowest rated');
    expect(page).toContain('Open the door settings');
    expect(page).toContain('Shown once 5 people have answered this one.');
    expect(page).toContain('The queue was long');
    expect((fixture.nativeElement as HTMLElement).querySelectorAll('.spread li')).toHaveLength(5);
  });

  it('under the minimum, says why there are no figures yet', () => {
    const fixture = open(survey({ sent_at: '2026-10-05T01:00:00Z', can_send_now: false }));
    http.expectOne(`${URL}/results`).flush(results({ responded: 3, enough: false, nps: null, insights: [], questions: [] }));
    fixture.detectChanges();

    expect(text(fixture)).toContain('3 people have answered. The figures appear once 5 have');
  });

  it('switching a finished event back on asks first, since it goes within the hour, and a no puts the box back', async () => {
    yes = false;
    const off = survey({ enabled: false, due_now: true, can_send_now: false, stopped_because: 'The survey is switched off for this event.' });
    const fixture = open(off);
    const box = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[name="enabled"]')!;

    box.checked = true;
    await fixture.componentInstance.setEnabled(true, box);
    expect(asked).toHaveLength(1);
    expect(asked[0].title).toBe('Switch the survey on and send it?');
    expect(asked[0].body).toContain('within the hour');
    expect(box.checked).toBe(false);
    http.expectNone(URL);

    yes = true;
    box.checked = true;
    await fixture.componentInstance.setEnabled(true, box);
    const request = http.expectOne(URL);
    expect(request.request.body).toEqual({ enabled: true });
    request.flush(survey({ sends_at: '2026-10-04T23:00:00Z', due_now: true }));
    fixture.detectChanges();

    expect(text(fixture)).toContain('Switched on. The survey goes within the hour.');
    expect(text(fixture)).toContain('Goes within the hour, to the people let in.');
  });

  it('switching on an event still to come saves without asking, and says when it goes', async () => {
    const fixture = open(survey({ enabled: false, can_send_now: false, stopped_because: 'The survey is switched off for this event.' }));

    await fixture.componentInstance.setEnabled(true);
    expect(asked).toHaveLength(0);
    http.expectOne(URL).flush(survey());
    fixture.detectChanges();

    expect(text(fixture)).toMatch(/Switched on\. The survey goes .*October 4/);
  });

  it('before the door’s final count, says when it can be sent early instead of offering it', () => {
    vi.setSystemTime(new Date('2026-10-04T12:00:00Z'));
    const fixture = open(survey({ can_send_now: false }));

    expect(text(fixture)).not.toContain('Send it now');
    expect(text(fixture)).toContain('You can send it early from');
    expect(text(fixture)).toContain('once the door\'s last scans are in');
  });

  it('when what people said cannot be loaded, says so and tries again', () => {
    const fixture = open(survey({ sent_at: '2026-10-05T01:00:00Z', can_send_now: false }));
    http.expectOne(`${URL}/results`).flush({ message: 'Server Error' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(text(fixture)).toContain('Could not load what people said');
    expect((fixture.nativeElement as HTMLElement).querySelector('ui-skeleton')).toBeNull();

    fixture.componentInstance.loadResults();
    http.expectOne(`${URL}/results`).flush(results());
    fixture.detectChanges();

    expect(text(fixture)).not.toContain('Could not load what people said');
    expect(text(fixture)).toContain('+25');
  });
});
