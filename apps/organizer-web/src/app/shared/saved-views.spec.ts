import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { createListState, type ViewSave } from '@myfiesta/ui';
import { API_BASE_URL } from '../core/api';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../core/confirm-testing';
import { SavedViews } from './saved-views';

const VIEWS = 'http://api.test/api/organizer/saved-views';

@Component({
  imports: [SavedViews],
  template: `<app-saved-views list="events" [state]="list" />`,
})
class EventsList {
  readonly list = createListState({ list: 'events', filters: { q: { kind: 'text' } }, url: false });
}

/**
 * Keeping a view of a list writes on the server, so it asks first, as
 * deleting one already did — and says so when the name is taken and the
 * view kept under it is about to be replaced.
 */
describe('SavedViews', () => {
  let backend: HttpTestingController;

  beforeAll(allowDialogs);

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
  });

  function render(kept: { id: string; name: string }[] = []) {
    const fixture = TestBed.createComponent(EventsList);
    fixture.detectChanges();
    backend.expectOne((r) => r.method === 'GET' && r.url === VIEWS).flush({ data: kept.map((view) => ({ ...view, list: 'events', state: {} })) });
    fixture.detectChanges();

    const views = fixture.debugElement.query(By.directive(SavedViews)).componentInstance as unknown as { save(request: ViewSave): Promise<void> };

    return { fixture, views };
  }

  /** A name handed over from the box, and what it was told about it. */
  function handOver(name: string): ViewSave & { settled: boolean[] } {
    const settled: boolean[] = [];

    return { name, settled, settle: (kept) => settled.push(kept) };
  }

  it('asks before keeping a view, and keeps nothing when the answer is no', async () => {
    const { views } = render();
    const handed = handOver('Lagos, December');

    const done = views.save(handed);
    await settle();

    expect(asked()?.title).toBe('Keep this view as “Lagos, December”?');
    expect(asked()?.text).toContain('its filters, sort and columns');
    expect(asked()?.buttons).toEqual(['Cancel', 'Keep the view']);

    await answer('Cancel');
    await done;

    backend.expectNone((r) => r.method === 'POST' && r.url === VIEWS);
    expect(handed.settled).toEqual([false]);
  });

  it('says it replaces a view of the same name, and keeps it once confirmed', async () => {
    const { views } = render([{ id: 'v-1', name: 'Lagos, December' }]);
    const handed = handOver('lagos, december');

    const done = views.save(handed);
    await settle();

    expect(asked()?.title).toBe('Replace the view “Lagos, December”?');
    expect(asked()?.buttons).toEqual(['Cancel', 'Replace the view']);

    await answer('Replace the view');

    const request = backend.expectOne((r) => r.method === 'POST' && r.url === VIEWS);
    expect(request.request.body).toMatchObject({ list: 'events', name: 'lagos, december' });
    request.flush({ data: { id: 'v-1', name: 'lagos, december', list: 'events', state: {} } });
    await settle();
    await done;

    expect(handed.settled).toEqual([true]);
  });

  /*
   * Saying no used to cost the name. The box was emptied as the question
   * opened, and the question — a modal dialog — closed the panel the box is
   * in, so "Cancel" left nothing to fix and nowhere to fix it.
   */
  it('keeps the name, and the panel, until the view is kept', async () => {
    const { fixture } = render();
    const page = fixture.nativeElement as HTMLElement;
    const panel = page.querySelector<HTMLElement>('.menu__panel')!;
    const box = panel.querySelector<HTMLInputElement>('input')!;

    page.querySelector<HTMLButtonElement>('.menu__button')!.click();
    box.value = 'Lagos, December';
    box.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    panel.querySelector<HTMLButtonElement>('.views__keep')!.click();
    await settle();
    expect(asked()?.title).toBe('Keep this view as “Lagos, December”?');

    // What the browser does to an open menu when a modal dialog opens.
    panel.dispatchEvent(Object.assign(new Event('toggle'), { newState: 'closed' }));
    fixture.detectChanges();
    expect(panel.classList).not.toContain('is-open');

    await answer('Cancel');
    fixture.detectChanges();

    expect(panel.classList).toContain('is-open');
    expect(box.value).toBe('Lagos, December');
    expect(document.activeElement).toBe(box);
    backend.expectNone((r) => r.method === 'POST' && r.url === VIEWS);

    panel.querySelector<HTMLButtonElement>('.views__keep')!.click();
    await settle();
    await answer('Keep the view');
    backend
      .expectOne((r) => r.method === 'POST' && r.url === VIEWS)
      .flush({ data: { id: 'v-2', name: 'Lagos, December', list: 'events', state: {} } });
    await settle();
    fixture.detectChanges();

    expect(box.value).toBe('');
  });

  it('suggests a name that fits the list it is on', () => {
    const { fixture } = render();

    const box = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>('.views__save input')!;

    expect(box.placeholder).toBe('Toronto, next month');
  });
});
