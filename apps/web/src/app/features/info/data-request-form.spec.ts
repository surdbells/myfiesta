import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ConfirmDialog, type ConfirmRequest } from '@myfiesta/ui';
import { API_BASE_URL } from '../../core/api-base';
import { DataRequestForm } from './data-request-form';

const REQUESTS = 'https://api.myfiesta.test/api/privacy/requests';

/**
 * Asking for a copy of your data, or to be erased, asks first.
 *
 * The two choices sit in one dropdown and read alike at a glance, so which of
 * them is being asked for is said back — erasure in red — and saying no sends
 * nothing.
 */
describe('DataRequestForm', () => {
  let http: HttpTestingController;
  let asked: ConfirmRequest[];
  let yes: boolean;

  beforeEach(() => {
    asked = [];
    yes = false;

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.myfiesta.test' },
        { provide: ConfirmDialog, useValue: { confirm: async (request: ConfirmRequest) => (asked.push(request), yes) } },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('names an erasure for what it is, and sends nothing when the answer is no', async () => {
    const form = TestBed.createComponent(DataRequestForm).componentInstance;
    form.email.set(' ada@example.test ');
    form.kind.set('erasure');

    await form.submit();

    expect(asked[0].title).toBe('Ask to be erased?');
    expect(asked[0].body).toContain('A link goes to ada@example.test.');
    expect(asked[0].tone).toBe('danger');
    expect(asked[0].confirmLabel).toBe('Send the erasure link');
    http.expectNone(REQUESTS);
    expect(form.sent()).toBe(false);
  });

  it('sends the request once it is confirmed', async () => {
    const form = TestBed.createComponent(DataRequestForm).componentInstance;
    form.email.set('ada@example.test');
    yes = true;

    await form.submit();

    expect(asked[0].title).toBe('Ask for a copy of your data?');

    const request = http.expectOne(REQUESTS);
    expect(request.request.body).toEqual({ kind: 'export', email: 'ada@example.test' });
    request.flush({ message: 'Check your email.' });
    expect(form.sent()).toBe(true);
  });
});
