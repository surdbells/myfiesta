import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { SessionStore } from '../../core/session';
import { Account } from './account';

/**
 * The account page's email section.
 *
 * Asking for a new address needs the current password, and the answer is the
 * server's sentence as it comes — it is the same whether or not the address
 * already has an account, and the page must not improve on it.
 */
describe('Account', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);
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

  function render() {
    const fixture = TestBed.createComponent(Account);
    fixture.detectChanges();

    return fixture;
  }

  function me(overrides: Record<string, unknown> = {}) {
    backend.expectOne('http://api.test/api/auth/me').flush({
      name: 'Ada Okafor',
      email: 'ada@example.test',
      phone: null,
      timezone: null,
      organizations: [],
      ...overrides,
    });
  }

  it('shows the address the server has now, not the one signing in said', () => {
    const fixture = render();

    // Moved from a link opened on another device since this session began.
    me({ email: 'ada@new.example.test' });
    fixture.detectChanges();

    expect(session.user()?.email).toBe('ada@new.example.test');
    expect(fixture.nativeElement.textContent).toContain('ada@new.example.test');
  });

  it('asks with the current password and shows what the server said', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ada@new.example.test');
    page.emailPassword.set('the right one 12');
    expect(page.canRequestEmail()).toBe(true);

    page.requestEmail();

    const request = backend.expectOne('http://api.test/api/auth/email');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ email: 'ada@new.example.test', current_password: 'the right one 12' });

    request.flush(
      { message: 'Check ada@new.example.test for a link to confirm it. Nothing changes until it is opened, and it works for an hour.' },
      { status: 202, statusText: 'Accepted' },
    );
    fixture.detectChanges();

    expect(page.emailSent()).toContain('Check ada@new.example.test');
    // The password does not outlive the request.
    expect(page.emailPassword()).toBe('');
    // Nothing has changed yet, so the session still says the old address.
    expect(session.user()?.email).toBe('ada@example.test');
  });

  it('puts a wrong password under the password box', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ada@new.example.test');
    page.emailPassword.set('a guess');
    page.requestEmail();

    backend.expectOne('http://api.test/api/auth/email').flush(
      { message: 'That is not your current password.', errors: { current_password: ['That is not your current password.'] } },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    expect(page.emailPasswordError()).toBe('That is not your current password.');
    expect(page.emailError()).toBeNull();
    expect(page.emailSent()).toBeNull();
  });

  it('does not offer to send a link to the address already in use', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ADA@example.test');
    page.emailPassword.set('the right one 12');

    expect(page.sameEmail()).toBe(true);
    expect(page.canRequestEmail()).toBe(false);

    page.requestEmail();
    backend.expectNone('http://api.test/api/auth/email');
  });
});
