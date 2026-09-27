import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { Organizer } from './organizer';

/**
 * An organizer link that leads nowhere.
 *
 * The page already said "not found" and carried noindex; the server still
 * answered 200. Rendered here as the server renders it, with a response to set.
 */
describe('Organizer, when there is no organizer', () => {
  let response: ResponseInit;
  let http: HttpTestingController;

  async function open(slug: string): Promise<RouterTestingHarness> {
    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl(`/o/${slug}`, Organizer);

    return harness;
  }

  beforeEach(() => {
    response = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'o/:slug', component: Organizer }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('answers 404 and keeps the page out of search', async () => {
    const harness = await open('nobody');

    http
      .expectOne('https://api.myfiesta.test/api/organizers/nobody')
      .flush({ message: 'Not found.' }, { status: 404, statusText: 'Not Found' });
    harness.detectChanges();

    expect(response.status).toBe(404);
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
    expect(harness.routeNativeElement?.textContent).toContain('Organizer not found');
  });

  it('answers 503 when the API did not answer, rather than forgetting them', async () => {
    const harness = await open('lagos-nights');

    http
      .expectOne('https://api.myfiesta.test/api/organizers/lagos-nights')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    harness.detectChanges();

    expect(response.status).toBe(503);
    expect(harness.routeNativeElement?.textContent).not.toContain('Organizer not found');
  });
});
