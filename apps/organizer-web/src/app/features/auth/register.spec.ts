import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { SITE_URL } from '../../core/site-url';
import { Register } from './register';

/**
 * Signing up agrees to the terms, the privacy policy and the refund policy —
 * and only when the person ticks the box.
 *
 * The server refuses a sign-up without it; this screen says so first, with
 * the three pages a click away on the public site, and sends what was
 * actually ticked rather than a yes on the person's behalf.
 */
describe('Register, agreeing to the terms', () => {
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'register', component: Register }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        { provide: SITE_URL, useValue: 'https://myfiesta.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  async function open() {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl('/register', Register);
    const root = () => harness.routeNativeElement as HTMLElement;

    page.form.set({
      ...page.form(),
      name: 'Ada Okafor',
      email: 'ada@example.com',
      organization: 'Lagos Nights',
      password: 'correct horse 7',
      confirm: 'correct horse 7',
    });
    harness.detectChanges();

    return {
      harness,
      page,
      text: () => root().textContent ?? '',
      box: () => root().querySelector<HTMLInputElement>('input[name="agreed"]')!,
      button: () => root().querySelector<HTMLButtonElement>('button[type="submit"]')!,
      link: (text: string) =>
        Array.from(root().querySelectorAll('a')).find((a) => a.textContent?.trim() === text),
    };
  }

  it('starts unticked, and sends nothing until it is ticked', async () => {
    const { harness, page, box, button } = await open();
    await harness.fixture.whenStable();

    expect(box().checked).toBe(false);
    expect(button().disabled).toBe(true);

    page.submit();
    backend.expectNone('http://api.test/api/auth/register');
  });

  it('links to the three pages on the public site, in a tab of their own', async () => {
    const { link } = await open();

    for (const [text, path] of [
      ['terms', '/terms'],
      ['privacy policy', '/privacy'],
      ['refund policy', '/refunds'],
    ]) {
      const anchor = link(text);

      expect(anchor, `a link reading "${text}"`).toBeDefined();
      expect(anchor!.getAttribute('href')).toBe(`https://myfiesta.test${path}`);
      expect(anchor!.getAttribute('target')).toBe('_blank');
    }
  });

  it('sends the agreement with the sign-up, and then says to check the inbox', async () => {
    const { harness, page, box, button, text } = await open();

    box().click();
    harness.detectChanges();
    await harness.fixture.whenStable();

    expect(page.form().agreed).toBe(true);
    expect(button().disabled).toBe(false);

    page.submit();

    const request = backend.expectOne('http://api.test/api/auth/register');
    expect(request.request.body).toMatchObject({
      email: 'ada@example.com',
      organization: 'Lagos Nights',
      accept_terms: true,
    });

    // Every sign-up without an invitation is answered the same way, new
    // address or known: the account is made from the emailed link.
    request.flush(
      { message: 'Check your email to finish setting up your account.', pending: true },
      { status: 202, statusText: 'Accepted' },
    );
    harness.detectChanges();

    expect(text()).toContain('Check your email');
  });

  it('shows the server refusing a sign-up without it, in its own words', async () => {
    const { harness, page, text } = await open();

    // As if the box had been got round: the server's rule is the one that counts.
    page.form.set({ ...page.form(), agreed: true });
    page.submit();

    const message = 'Tick the box to accept the terms, the privacy policy and the refund policy.';
    backend
      .expectOne('http://api.test/api/auth/register')
      .flush({ message, errors: { accept_terms: [message] } }, { status: 422, statusText: 'Unprocessable Content' });
    harness.detectChanges();

    expect(text()).toContain(message);
  });
});
