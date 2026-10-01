import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { API_BASE_URL } from '../../core/api-base';
import type { PublicSurvey } from '../../core/api.types';
import { Feedback } from './feedback';

const URL = 'https://api.myfiesta.test/api/surveys/tok-1';

function survey(answered = false): PublicSurvey {
  return {
    event: { title: 'Afro Fest', organizer: 'Lagos Nights', starts_at: '2026-10-03T02:00:00Z', timezone: 'America/Toronto' },
    questions: [
      { id: 'recommend', type: 'nps', label: 'How likely are you to recommend this event to a friend?', options: [], required: true },
      { id: 'sound', type: 'rating5', label: 'How was the sound?', options: [], required: false },
      { id: 'liked', type: 'multi', label: 'What did you like?', options: ['DJ', 'Crowd', 'Bar'], required: false },
      { id: 'change', type: 'text', label: 'What should we change?', options: [], required: false },
    ],
    answered,
  };
}

/**
 * The survey from the email's link: answered once, asked first, and a link
 * already used says so instead of showing a form that cannot be sent.
 */
describe('Feedback', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;

  beforeEach(() => {
    asked = [];
    yes = true;

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ActivatedRoute, useValue: { snapshot: { paramMap: convertToParamMap({ token: 'tok-1' }) } } },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function open(body: PublicSurvey = survey()) {
    const fixture = TestBed.createComponent(Feedback);
    http.expectOne(URL).flush(body);
    fixture.detectChanges();
    return fixture;
  }

  it('shows the night and every question, the night in its own zone', () => {
    const fixture = open();
    const page = fixture.nativeElement as HTMLElement;

    expect(page.querySelector('h1')?.textContent).toContain('How was Afro Fest?');
    // 02:00 UTC on the 3rd is the evening of the 2nd in Toronto.
    expect(page.textContent).toContain('October 2, 2026');
    expect(page.querySelectorAll('fieldset.question').length).toBe(4);
    expect(page.querySelectorAll('input[type=radio][name="q-recommend"]').length).toBe(11);
    expect(page.querySelectorAll('input[type=radio][name="q-sound"]').length).toBe(5);
    expect(page.textContent).toContain('without your name or email address');
  });

  it('says a required question is unanswered without sending anything', async () => {
    const fixture = open();
    fixture.componentInstance.answer('change', 'More water');

    await fixture.componentInstance.send();
    fixture.detectChanges();

    expect(asked.length).toBe(0);
    expect(fixture.componentInstance.errors()['recommend']).toBe('Please answer this one.');
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Please answer this one.');
  });

  it('asks first, then sends the answers once and thanks them', async () => {
    const fixture = open();
    const page = fixture.componentInstance;

    page.answer('recommend', 9);
    page.toggle(survey().questions[2], 'Bar', true);
    page.toggle(survey().questions[2], 'DJ', true);

    await page.send();

    expect(asked.length).toBe(1);
    expect(asked[0].confirmLabel).toBe('Send my answers');
    expect(asked[0].consequences?.[0]).toContain('cannot change them');

    const request = http.expectOne(URL);
    expect(request.request.method).toBe('POST');
    // Choices in the order the question offers them.
    expect(request.request.body).toEqual({ answers: { recommend: 9, liked: ['DJ', 'Bar'] } });
    request.flush({ message: 'Thank you. Lagos Nights will read every answer.', survey: survey(true) }, { status: 201, statusText: 'Created' });
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Your answers are in');
    expect(text).toContain('Lagos Nights will read every answer.');
    expect((fixture.nativeElement as HTMLElement).querySelector('form')).toBeNull();
  });

  it('sends nothing when they think better of it', async () => {
    yes = false;
    const fixture = open();
    fixture.componentInstance.answer('recommend', 3);

    await fixture.componentInstance.send();

    expect(asked.length).toBe(1);
    http.expectNone(URL, 'no answer sent');
  });

  it('puts the server\'s word beside the question it is about', async () => {
    const fixture = open();
    fixture.componentInstance.answer('recommend', 7);

    await fixture.componentInstance.send();
    http
      .expectOne(URL)
      .flush({ message: 'Choose from 1 to 5.', errors: { 'answers.sound': ['Choose from 1 to 5.'] } }, { status: 422, statusText: 'Unprocessable' });
    fixture.detectChanges();

    expect(fixture.componentInstance.errors()['sound']).toBe('Choose from 1 to 5.');
    expect(fixture.componentInstance.failed()).toBe('Some answers need another look.');
  });

  it('a link already answered, here or elsewhere, says so instead of a form', async () => {
    const fixture = open(survey(true));
    const page = fixture.nativeElement as HTMLElement;

    expect(page.textContent).toContain('You have already answered this survey');
    expect(page.querySelector('form')).toBeNull();
  });

  it('a second try from another tab lands on the same answer', async () => {
    const fixture = open();
    fixture.componentInstance.answer('recommend', 10);

    await fixture.componentInstance.send();
    http.expectOne(URL).flush({ message: 'You have already answered this survey.' }, { status: 409, statusText: 'Conflict' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('You have already answered this survey');
  });

  it('a link that finds nothing says how to open it again', () => {
    const fixture = TestBed.createComponent(Feedback);
    http.expectOne(URL).flush({ message: 'Not found' }, { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('We cannot find that survey');
  });

  for (const [status, statusText] of [
    [500, 'Server Error'],
    [429, 'Too Many Requests'],
    [0, 'Unknown Error'],
  ] as const) {
    it(`a ${status} does not call a good link broken, and tries again (${statusText})`, () => {
      const fixture = TestBed.createComponent(Feedback);
      http.expectOne(URL).flush({ message: statusText }, { status, statusText });
      fixture.detectChanges();

      const page = fixture.nativeElement as HTMLElement;
      expect(page.textContent).toContain('We could not open the survey just now');
      expect(page.textContent).toContain('the link is fine');
      expect(page.textContent).not.toContain('We cannot find that survey');

      page.querySelector<HTMLButtonElement>('section button')!.click();
      http.expectOne(URL).flush(survey());
      fixture.detectChanges();

      expect(page.querySelector('h1')?.textContent).toContain('How was Afro Fest?');
    });
  }
});
