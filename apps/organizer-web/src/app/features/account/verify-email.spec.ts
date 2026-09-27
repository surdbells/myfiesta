import { HttpClient, HttpErrorResponse, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import { EmailVerification } from '../../core/email-verification';
import { isEmailUnverified } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { VerifyEmail } from './verify-email';

/**
 * "Confirm your email address", and the button that sends the link again.
 *
 * Putting a night on sale, asking to be paid and changing where payouts go
 * wait for a proved address. The console used to learn that only from the
 * refusal, shown like any other error with nothing to do about it; now the
 * account says so up front, and the refusal opens the same prompt.
 */
describe('VerifyEmail', () => {
  let backend: HttpTestingController;
  let session: SessionStore;
  let store: EmailVerification;

  beforeAll(() => {
    // jsdom's <dialog> has no showModal(); the prompt only needs it not to throw.
    const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    dialog['showModal'] ??= function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    dialog['close'] ??= function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    };
  });

  beforeEach(() => {
    localStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);
    store = TestBed.inject(EmailVerification);

    session.start({
      token: 'test-token',
      user: { name: 'Ada Okafor', email: 'ada@example.test' },
      abilities: ['attendee', 'organizer'],
      organizations: [],
    });
  });

  afterEach(() => {
    backend.verify();
    session.clear();
  });

  function render(emailVerified: boolean | undefined) {
    const fixture = TestBed.createComponent(VerifyEmail);
    fixture.detectChanges();

    backend.expectOne('http://api.test/api/auth/me').flush({
      name: 'Ada Okafor',
      email: 'ada@example.test',
      ...(emailVerified === undefined ? {} : { email_verified: emailVerified }),
      phone: null,
      timezone: null,
      organizations: [],
    });
    fixture.detectChanges();

    return fixture;
  }

  function text(fixture: { nativeElement: HTMLElement }): string {
    return fixture.nativeElement.textContent ?? '';
  }

  it('asks the account, and says nothing to a proved address', () => {
    const fixture = render(true);

    expect(store.verified()).toBe(true);
    expect(fixture.nativeElement.querySelector('.verify-email')).toBeNull();
  });

  it('shows a gentle banner while the address is unproved', () => {
    const fixture = render(false);

    const banner = fixture.nativeElement.querySelector('.verify-email') as HTMLElement;
    expect(banner).not.toBeNull();
    expect(banner.textContent).toContain('Confirm your email address');
    expect(banner.textContent).toContain('ada@example.test');
    expect(banner.textContent).toContain('Send the link again');
  });

  it('shows nothing when the server does not say', () => {
    // An API from before the field existed: no banner nobody can clear.
    const fixture = render(undefined);

    expect(store.verified()).toBeNull();
    expect(fixture.nativeElement.querySelector('.verify-email')).toBeNull();
  });

  it('sends the link again and says where it went', () => {
    const fixture = render(false);

    fixture.componentInstance.resend();

    const request = backend.expectOne('http://api.test/api/auth/email/verification');
    expect(request.request.method).toBe('POST');
    request.flush(
      { message: 'We sent a link to ada@example.test. Open it to confirm the address.', verified: false },
      { status: 202, statusText: 'Accepted' },
    );
    fixture.detectChanges();

    expect(text(fixture)).toContain('We sent a link to ada@example.test.');
    expect(store.verified()).toBe(false);
  });

  it('passes on how long to wait when asked too often', () => {
    const fixture = render(false);

    fixture.componentInstance.resend();
    backend.expectOne('http://api.test/api/auth/email/verification').flush(
      { message: 'A link went to ada@example.test a moment ago. Check that inbox, including spam, or ask again in 40 minutes.', verified: false },
      { status: 429, statusText: 'Too Many Requests' },
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.answer()).toContain('ask again in 40 minutes');
  });

  it('goes away when the address turns out to be proved already', () => {
    const fixture = render(false);

    fixture.componentInstance.resend();
    backend.expectOne('http://api.test/api/auth/email/verification').flush({ message: 'Your email address is already confirmed.', verified: true });
    fixture.detectChanges();

    expect(store.verified()).toBe(true);
    expect(fixture.nativeElement.querySelector('.verify-email')).toBeNull();
  });

  it('turns the refusal into the same prompt, on top of whatever was open', () => {
    const fixture = render(true);
    const http = TestBed.inject(HttpClient);
    let refused: unknown = null;

    http.post('http://api.test/api/organizer/events/ev-1/publish', { status: 'published' }).subscribe({ error: (e) => (refused = e) });
    backend.expectOne('http://api.test/api/organizer/events/ev-1/publish').flush(
      {
        message: 'Confirm your email address first. We have sent a link to ada@example.test — open it, then try again.',
        code: 'email_unverified',
      },
      { status: 403, statusText: 'Forbidden' },
    );
    fixture.detectChanges();

    // The screen that asked is told it was this refusal, so it says nothing
    // of its own; the prompt says the server's sentence and offers the button.
    expect(isEmailUnverified(refused)).toBe(true);
    expect(store.verified()).toBe(false);
    expect(fixture.componentInstance.prompting()).toBe(true);
    expect(text(fixture)).toContain('We have sent a link to ada@example.test');

    fixture.componentInstance.close();
    fixture.detectChanges();

    // Closed, the banner is still there until the address is proved.
    expect(fixture.componentInstance.prompting()).toBe(false);
    expect(fixture.nativeElement.querySelector('.verify-email')).not.toBeNull();
  });

  it('leaves every other 403 to the screen that met it', () => {
    render(true);
    const http = TestBed.inject(HttpClient);

    http.post('http://api.test/api/organizer/payouts/requests', {}).subscribe({ error: () => undefined });
    backend
      .expectOne('http://api.test/api/organizer/payouts/requests')
      .flush({ message: 'Only owners and finance can ask to be paid.' }, { status: 403, statusText: 'Forbidden' });

    expect(store.refusal()).toBeNull();
    expect(isEmailUnverified(new HttpErrorResponse({ status: 403, error: { message: 'No.' } }))).toBe(false);
  });

  it('is not shown to myFiesta staff acting as an organization', () => {
    session.clear();
    session.startImpersonation({
      token: 'staff-token',
      user: { name: 'Sade Support', email: 'sade@myfiesta.test' },
      abilities: ['organizer'],
      organizations: [],
      impersonation: {
        id: 'imp-1',
        organization: { id: 'org-1', name: 'Lagos Nights' },
        staff: { name: 'Sade Support' },
        reason: 'Helping with a payout',
        started_at: new Date().toISOString(),
        expires_at: new Date(Date.now() + 3_600_000).toISOString(),
        withheld: [],
      },
    });

    const fixture = TestBed.createComponent(VerifyEmail);
    fixture.detectChanges();

    // Not asked, and not shown: the account behind the session is not theirs.
    backend.expectNone('http://api.test/api/auth/me');
    expect(fixture.nativeElement.querySelector('.verify-email')).toBeNull();
  });
});
