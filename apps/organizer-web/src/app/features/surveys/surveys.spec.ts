import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { isObservable, lastValueFrom } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { SurveyOverview, SurveyTemplate, SurveyTemplateList } from '../../core/api.types';
import { SurveyEditor, type EditorStart } from './survey-editor';
import { Surveys } from './surveys';

const API = 'http://api.test/api/organizer';

const MINE: SurveyTemplate = {
  id: 'tpl-mf',
  name: 'myFiesta survey',
  platform: true,
  questions: [
    { id: 'recommend', type: 'nps', label: 'How likely are you to recommend this event to a friend?', options: [], required: true },
    { id: 'sound', type: 'rating5', label: 'How was the sound?', options: [], required: false },
  ],
  nights_waiting: 0,
  updated_at: null,
};

const OWN: SurveyTemplate = {
  id: 'tpl-own',
  name: 'After a concert',
  platform: false,
  questions: [{ id: 'qabc1234', type: 'single', label: 'Which set was best?', options: ['Early', 'Late'], required: false }],
  nights_waiting: 2,
  updated_at: '2026-09-30T12:00:00Z',
};

const overview: SurveyOverview = {
  surveys_enabled: true,
  nights_due_now: 0,
  minimum: 5,
  recent: [
    {
      event_id: 'ev-1',
      title: 'Afro Fest',
      starts_at: '2026-09-27T02:00:00Z',
      timezone: 'America/Toronto',
      sent_at: '2026-09-27T20:00:00Z',
      invited: 40,
      responded: 12,
      response_rate: 30,
      nps: 25,
    },
  ],
};

const list: SurveyTemplateList = { data: [MINE, OWN], max_questions: 12, types: ['nps', 'rating5', 'single', 'multi', 'text'] };

/**
 * The Surveys screen: the organization's switch, how recent events
 * answered, and the surveys an event can send — myFiesta's to copy, the
 * organization's own to change or remove, removing asked first.
 */
describe('Surveys', () => {
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
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        {
          provide: ConfirmDialog,
          useValue: {
            // As the real dialog does: run the action when the answer is yes.
            confirm: async (request: ConfirmRequest) => {
              asked.push(request);
              if (yes && request.run) {
                const running = request.run(undefined);
                await (isObservable(running) ? lastValueFrom(running) : running);
              }
              return yes;
            },
          },
        },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function open() {
    const fixture = TestBed.createComponent(Surveys);
    fixture.detectChanges();
    http.expectOne(`${API}/surveys`).flush(overview);
    http.expectOne(`${API}/survey-templates`).flush(list);
    fixture.detectChanges();
    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  it('lists recent events with their answers and score, and every survey', () => {
    const fixture = open();
    const page = text(fixture);

    expect(page).toContain('Afro Fest');
    expect(page).toContain('12 of 40 answered');
    expect(page).toContain('+25');
    expect(page).toContain('myFiesta survey');
    expect(page).toContain('After a concert');
    expect(page).toContain('chosen for 2 events still to be sent');
    expect((fixture.nativeElement as HTMLElement).querySelector('a[href="/events/ev-1/feedback"]')).not.toBeNull();
  });

  it('switches surveys off for the whole organization and says what that means', () => {
    const fixture = open();

    fixture.componentInstance.setEnabled(false);
    const request = http.expectOne(`${API}/surveys/settings`);
    expect(request.request.body).toEqual({ surveys_enabled: false });
    request.flush({ surveys_enabled: false, nights_due_now: 0, message: 'Surveys are off. No event is asked about until you switch them back on.' });
    fixture.detectChanges();

    expect(fixture.componentInstance.enabled()).toBe(false);
    expect(text(fixture)).toContain('No event is asked about while this is off');
  });

  it('switching back on with finished events due asks first, and a no leaves the box off', async () => {
    yes = false;
    const fixture = TestBed.createComponent(Surveys);
    fixture.detectChanges();
    http.expectOne(`${API}/surveys`).flush({ ...overview, surveys_enabled: false, nights_due_now: 2 });
    http.expectOne(`${API}/survey-templates`).flush(list);
    fixture.detectChanges();

    const box = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('input[name="surveys_enabled"]')!;
    box.checked = true;
    await fixture.componentInstance.setEnabled(true, box);

    expect(asked).toHaveLength(1);
    expect(asked[0].body).toContain('2 events that have finished are asked about within the hour');
    expect(asked[0].confirmLabel).toBe('Switch on and send');
    expect(box.checked).toBe(false);
    http.expectNone(`${API}/surveys/settings`);

    yes = true;
    box.checked = true;
    await fixture.componentInstance.setEnabled(true, box);
    const request = http.expectOne(`${API}/surveys/settings`);
    expect(request.request.body).toEqual({ surveys_enabled: true });
    request.flush({ surveys_enabled: true, nights_due_now: 2, message: 'Surveys are on. 2 events that have finished are asked about within the hour.' });
    fixture.detectChanges();

    expect(fixture.componentInstance.enabled()).toBe(true);
    expect(text(fixture)).toContain('2 events that have finished are asked about within the hour.');
  });

  it('switching on with nothing due saves without asking', async () => {
    const fixture = open();

    await fixture.componentInstance.setEnabled(true);
    expect(asked).toHaveLength(0);
    http.expectOne(`${API}/surveys/settings`).flush({ surveys_enabled: true, nights_due_now: 0, message: 'Surveys are on.' });
  });

  it('says when the surveys could not be loaded, and tries them again', () => {
    const fixture = TestBed.createComponent(Surveys);
    fixture.detectChanges();
    http.expectOne(`${API}/surveys`).flush(overview);
    http.expectOne(`${API}/survey-templates`).flush({ message: 'Server Error' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(text(fixture)).toContain('Could not load your surveys');
    expect(text(fixture)).not.toContain('You have no surveys of your own');
    expect(text(fixture)).toContain('Afro Fest');

    fixture.componentInstance.retryTemplates();
    fixture.detectChanges();
    http.expectOne(`${API}/survey-templates`).flush(list);
    fixture.detectChanges();

    expect(text(fixture)).not.toContain('Could not load your surveys');
    expect(text(fixture)).toContain('After a concert');
  });

  it('copies myFiesta’s questions into a new survey, keeping their ids so its advice still finds them', () => {
    const fixture = open();

    fixture.componentInstance.copy(MINE);
    fixture.detectChanges();

    const start = fixture.componentInstance.editing();
    expect(start?.id).toBeNull();
    expect(start?.name).toBe('myFiesta survey (copy)');
    expect(start?.questions.map((q) => q.id)).toEqual(['recommend', 'sound']);
    expect((fixture.nativeElement as HTMLElement).querySelector('app-survey-editor')).not.toBeNull();
  });

  it('removes one of its own only after asking, saying which events it touches', async () => {
    yes = false;
    const fixture = open();

    await fixture.componentInstance.remove(OWN);
    expect(asked[0].tone).toBe('danger');
    expect(asked[0].consequences?.[0]).toContain('The 2 events still to be sent it will send myFiesta’s survey instead');
    http.expectNone(`${API}/survey-templates/tpl-own`);

    yes = true;
    const removing = fixture.componentInstance.remove(OWN);
    const request = http.expectOne(`${API}/survey-templates/tpl-own`);
    expect(request.request.method).toBe('DELETE');
    request.flush({ message: 'Removed.' });
    await removing;
    fixture.detectChanges();

    const names = [...(fixture.nativeElement as HTMLElement).querySelectorAll('.templates h3')].map((h) => h.textContent?.trim());
    expect(names).toEqual(['myFiesta survey']);
    expect(text(fixture)).toContain('Removed “After a concert”.');
  });
});

describe('SurveyEditor', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function edit(start: EditorStart = { id: OWN.id, name: OWN.name, questions: OWN.questions }) {
    const fixture = TestBed.createComponent(SurveyEditor);
    fixture.componentRef.setInput('start', start);
    fixture.componentRef.setInput('maxQuestions', 2);
    fixture.detectChanges();
    const saved: SurveyTemplate[] = [];
    fixture.componentInstance.saved.subscribe((template) => saved.push(template));
    return { fixture, editor: fixture.componentInstance, saved };
  }

  it('keeps each question’s id, and sends choices only for the kinds that have them', () => {
    const { editor, saved } = edit();

    editor.change(0, { choices: 'Early\n  Late \n\nEncore' });
    editor.add();
    editor.change(1, { type: 'text', label: 'Anything else?', choices: 'ignored' });
    expect(editor.full()).toBe(true);

    editor.save();
    const request = http.expectOne(`${API}/survey-templates/tpl-own`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      name: 'After a concert',
      questions: [
        { id: 'qabc1234', type: 'single', label: 'Which set was best?', options: ['Early', 'Late', 'Encore'], required: false },
        { id: null, type: 'text', label: 'Anything else?', options: [], required: false },
      ],
    });
    request.flush({ data: OWN });
    expect(saved).toEqual([OWN]);
  });

  it('a new survey starts with the recommend question, and moves questions about', () => {
    const { editor } = edit({ id: null, name: '', questions: [] });

    expect(editor.questions().map((q) => q.type)).toEqual(['nps']);
    editor.add();
    editor.move(1, -1);
    expect(editor.questions().map((q) => q.type)).toEqual(['rating5', 'nps']);
  });

  it('puts the server’s word beside the question it is about', () => {
    const { fixture, editor } = edit();

    editor.save();
    http
      .expectOne(`${API}/survey-templates/tpl-own`)
      .flush(
        { message: 'Invalid', errors: { 'questions.0.options': ['Give people at least two different choices.'] } },
        { status: 422, statusText: 'Unprocessable' },
      );
    fixture.detectChanges();

    expect(editor.errorsFor(0)).toEqual(['Give people at least two different choices.']);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Give people at least two different choices.');
    expect(editor.failed()).toBe('Some of the survey needs another look.');
  });
});
