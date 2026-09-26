import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { ConfirmEmail } from './confirm-email';

/**
 * The page the link to a new email address opens.
 *
 * What matters most is what it does not do: confirm on load. Mail scanners
 * open links before anybody reads them, and a page that acted on arrival would
 * move the account for the scanner.
 */
describe('ConfirmEmail', () => {
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'confirm-email', component: ConfirmEmail }]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  async function open(url: string) {
    const harness = await RouterTestingHarness.create();
    const page = await harness.navigateByUrl(url, ConfirmEmail);

    return { harness, page, text: () => (harness.routeNativeElement as HTMLElement).textContent ?? '' };
  }

  it('says a link without its token is incomplete, and sends nothing', async () => {
    const { text } = await open('/confirm-email');

    expect(text()).toContain('That link is incomplete');
    backend.expectNone('http://api.test/api/auth/email/confirm');
  });

  it('waits to be asked before moving the account', async () => {
    const { harness, page, text } = await open('/confirm-email?token=abc123');

    expect(text()).toContain('Use this email address?');
    backend.expectNone('http://api.test/api/auth/email/confirm');

    page.confirm();

    const request = backend.expectOne('http://api.test/api/auth/email/confirm');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ token: 'abc123' });

    request.flush({ message: 'Your account now uses new@example.test. Other devices have been signed out.', email: 'new@example.test' });
    harness.detectChanges();

    expect(text()).toContain('Email address changed');
    expect(text()).toContain('Your account now uses new@example.test.');
  });

  it('says why a link that no longer works was refused', async () => {
    const { harness, page, text } = await open('/confirm-email?token=stale');

    page.confirm();

    backend.expectOne('http://api.test/api/auth/email/confirm').flush(
      {
        message: 'That link has expired or has already been used. Ask for a new one from your account.',
        errors: { token: ['That link has expired or has already been used. Ask for a new one from your account.'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    harness.detectChanges();

    expect(text()).toContain('That link has expired or has already been used.');
    expect(text()).not.toContain('Email address changed');
  });
});
