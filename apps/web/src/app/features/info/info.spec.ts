import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { RESPONSE_INIT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Meta } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { API_BASE_URL } from '../../core/api-base';
import { ContactDetails } from '../../core/api.types';
import { Info } from './info';
import { NotFound } from './not-found';

const API = 'https://api.myfiesta.test';

function details(overrides: Partial<ContactDetails> = {}): ContactDetails {
  return {
    company_name: 'Example Events Inc.',
    company_number: null,
    support_email: 'support@example.test',
    privacy_email: 'support@example.test',
    phone: null,
    addresses: [{ country: 'CA', address: '1 Example Street, Toronto, ON' }],
    complete: true,
    ...overrides,
  };
}

/**
 * The legal pages, and who they say runs the platform.
 *
 * The operator's details come from the API's configuration, never from this
 * template — so what matters is that they are shown where the law expects
 * them, and that a launch with them missing is loud on the page.
 */
describe('Info pages', () => {
  let http: HttpTestingController;
  let harness: RouterTestingHarness | undefined;

  /** One harness per test; later pages are navigated to inside it. */
  async function open(page: string): Promise<HTMLElement> {
    harness ??= await RouterTestingHarness.create();
    await harness.navigateByUrl(`/${page}`);

    return harness.routeNativeElement as HTMLElement;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter(
          ['help', 'terms', 'privacy', 'refunds', 'contact'].map((page) => ({
            path: page,
            data: { page },
            component: Info,
          })),
        ),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: API },
      ],
    });

    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
    harness = undefined;
  });

  it('shows the configured operator on the contact page', async () => {
    const el = await open('contact');

    http.expectOne(`${API}/api/contact`).flush({ data: details({ phone: '+1 416 555 0100' }) });
    harness!.detectChanges();

    expect(el.textContent).toContain('Example Events Inc.');
    expect(el.querySelector('a[href="mailto:support@example.test"]')).not.toBeNull();
    expect(el.querySelector('a[href="tel:+1 416 555 0100"]')).not.toBeNull();
    expect(el.textContent).toContain('1 Example Street, Toronto, ON');
    expect(el.querySelector('.note')).toBeNull();
  });

  it('says loudly when the operator has not filled their details in', async () => {
    const el = await open('contact');

    http.expectOne(`${API}/api/contact`).flush({
      data: details({ company_name: null, support_email: null, privacy_email: null, addresses: [], complete: false }),
    });
    harness!.detectChanges();

    expect(el.querySelector('.note')?.textContent).toContain('still to be filled in');
    // Nothing invented in the meantime.
    expect(el.querySelector('a[href^="mailto:"]')).toBeNull();
  });

  it('names each market when there is an address in both', async () => {
    const el = await open('contact');

    http.expectOne(`${API}/api/contact`).flush({
      data: details({
        addresses: [
          { country: 'CA', address: '1 Example Street, Toronto, ON' },
          { country: 'NG', address: '2 Example Road, Lagos' },
        ],
      }),
    });
    harness!.detectChanges();

    expect(el.textContent).toContain('Post — Canada');
    expect(el.textContent).toContain('Post — Nigeria');
  });

  it('gives privacy requests their own inbox when there is one', async () => {
    const el = await open('privacy');

    http.expectOne(`${API}/api/contact`).flush({ data: details({ privacy_email: 'privacy@example.test' }) });
    harness!.detectChanges();

    expect(el.querySelector('a[href="mailto:privacy@example.test"]')).not.toBeNull();
  });

  it('keeps the lawyer notice on every legal page', async () => {
    for (const page of ['terms', 'privacy', 'refunds']) {
      const el = await open(page);
      http.expectOne(`${API}/api/contact`).flush({ data: details() });
      harness!.detectChanges();

      expect(el.textContent, page).toContain('Not yet reviewed by a lawyer');
    }
  });

  it('describes refunds as the code makes them', async () => {
    const el = await open('refunds');
    http.expectOne(`${API}/api/contact`).flush({ data: details() });
    harness!.detectChanges();

    const text = el.textContent ?? '';

    // RefundService: by ticket, the service charge shared out with the rest.
    expect(text).toContain('ticket by ticket');
    expect(text).toContain('service fee is not kept back');
    // Fulfiller: a payment that became no tickets goes back whole, on its own.
    expect(text).toContain('sent back automatically');
    // Resale: the seller is paid when the place is taken, not before.
    expect(text).toContain('When somebody else buys that place');
  });

  it('links the terms to the refund page', async () => {
    const el = await open('terms');
    http.expectOne(`${API}/api/contact`).flush({ data: details() });
    harness!.detectChanges();

    expect(el.querySelector('a[href="/refunds"]')).not.toBeNull();
    // What the code does, not what the old copy said.
    expect(el.textContent).not.toContain('do not refund our own fees');
  });

  it('does not ask for contact details on a page that does not name the operator', async () => {
    await open('help');

    http.expectNone(`${API}/api/contact`);
  });
});

describe('NotFound', () => {
  it('answers 404 from the server and stays out of search', async () => {
    const response: ResponseInit = { status: 200 };

    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '**', component: NotFound }]),
        { provide: RESPONSE_INIT, useValue: response },
      ],
    });

    const harness = await RouterTestingHarness.create();
    await harness.navigateByUrl('/an/old/path');

    expect(response.status).toBe(404);
    expect(TestBed.inject(Meta).getTag('name="robots"')?.content).toBe('noindex');
    expect(harness.routeNativeElement?.textContent).toContain('Page not found');
  });
});
