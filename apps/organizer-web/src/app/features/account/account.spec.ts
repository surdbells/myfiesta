import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { EmailVerification } from '../../core/email-verification';
import { SessionStore } from '../../core/session';
import { Account } from './account';

/** Somewhere for the page to send people: signed out, or to a team. */
@Component({ template: '' })
class Elsewhere {}

/**
 * The account page's email section, and deleting the account.
 *
 * Asking for a new address needs the current password, and the answer is the
 * server's sentence as it comes — it is the same whether or not the address
 * already has an account, and the page must not improve on it.
 *
 * Deleting says what goes and what stays before the password is typed, sends
 * the only owner of an organization to hand it over instead, and forgets the
 * session once the account behind it is gone.
 */
describe('Account', () => {
  let backend: HttpTestingController;
  let session: SessionStore;

  beforeAll(() => {
    // jsdom's <dialog> has no showModal(); the dialog only needs it not to throw.
    const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;
    dialog['showModal'] ??= function (this: HTMLDialogElement) {
      this.setAttribute('open', '');
    };
    dialog['close'] ??= function (this: HTMLDialogElement) {
      this.removeAttribute('open');
    };
  });

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'sign-in', component: Elsewhere },
          { path: 'team', component: Elsewhere },
        ]),
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
    forgetDialogs();
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
      email_verified: true,
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

  it('asks before sending the link, and sends nothing when the answer is no', async () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ada@new.example.test');
    page.emailPassword.set('the right one 12');

    void page.requestEmail();
    await settle();

    expect(asked()?.title).toBe('Move your account to ada@new.example.test?');
    expect(asked()?.text).toContain('Until then you keep signing in with ada@example.test.');
    expect(asked()?.buttons).toEqual(['Cancel', 'Send the link']);

    await answer('Cancel');

    backend.expectNone('http://api.test/api/auth/email');
    expect(page.requesting()).toBe(false);
  });

  it('asks before saving a new name, and saves nothing when the answer is no', async () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.name.set('Ada O.');

    void page.saveName();
    await settle();

    expect(asked()?.title).toBe('Change your name to Ada O.?');

    await answer('Cancel');

    backend.expectNone('http://api.test/api/auth/profile');
    expect(page.savingName()).toBe(false);
  });

  it('asks with the current password and shows what the server said', async () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ada@new.example.test');
    page.emailPassword.set('the right one 12');
    expect(page.canRequestEmail()).toBe(true);

    void page.requestEmail();
    await settle();
    await answer('Send the link');

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

  it('puts a wrong password under the password box', async () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.newEmail.set('ada@new.example.test');
    page.emailPassword.set('a guess');
    void page.requestEmail();
    await settle();
    await answer('Send the link');

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

    void page.requestEmail();
    backend.expectNone('http://api.test/api/auth/email');
  });

  it('tells the banner the address is proved once it is', () => {
    render();
    me({ email_verified: true });

    expect(TestBed.inject(EmailVerification).verified()).toBe(true);
  });

  // --- deleting the account ---------------------------------------------------------

  function preview(overrides: Record<string, unknown> = {}) {
    backend.expectOne((r) => r.method === 'GET' && r.url === 'http://api.test/api/auth/erasure').flush({
      email: 'ada@example.test',
      email_verified: true,
      refused: null,
      organizations: [],
      kept_for_years: 7,
      history_kept: false,
      ...overrides,
    });
  }

  it('says what goes and what stays before asking for the password', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview({ organizations: [{ id: 'org-2', name: 'Toronto Afters', slug: 'toronto-afters', role: 'manager', only_owner: false }] });
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent ?? '';
    expect(text).toContain('What goes');
    expect(text).toContain('What stays');
    expect(text).toContain('for 7 years');
    // A member who is not the only owner leaves, and the organization keeps its things.
    expect(text).toContain('Your place on the team at Toronto Afters');
    // Nothing in the audit trail names them, so nothing is said to.
    expect(text).not.toContain('under your name');
    expect(page.canDelete()).toBe(false);

    page.deletePassword.set('the right one 12');
    expect(page.canDelete()).toBe(true);
  });

  it('says that what they did on a team stays under their name', () => {
    const fixture = render();
    me();

    fixture.componentInstance.openDelete();
    preview({
      organizations: [{ id: 'org-2', name: 'Toronto Afters', slug: 'toronto-afters', role: 'manager', only_owner: false }],
      history_kept: true,
    });
    fixture.detectChanges();

    // The audit trail cannot be edited, so it is not promised a clean slate.
    const text = fixture.nativeElement.textContent ?? '';
    expect(text).toContain('What you did on an organizer’s team');
    expect(text).toContain('under your name');
  });

  it('refuses a staff account without offering to hand an organization over', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview({
      refused: 'This account has myFiesta staff access. Ask another administrator to remove your staff access first.',
      organizations: [],
    });
    fixture.detectChanges();

    const text = fixture.nativeElement.textContent ?? '';
    expect(text).toContain('Ask another administrator');
    // Neither the hand-over nor closing an organization has anything to do with it.
    expect(text).not.toContain('Make somebody else an owner');
    expect(text).not.toContain('write to us');

    page.deletePassword.set('the right one 12');
    expect(page.canDelete()).toBe(false);
    page.deleteAccount();
    backend.expectNone((r) => r.method === 'POST' && r.url === 'http://api.test/api/auth/erasure');
  });

  it('sends the only owner to hand the organization over, and offers no delete', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview({
      refused: 'You are the only owner of Lagos Nights. Make somebody else an owner, or close the organization, and then ask again.',
      organizations: [{ id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role: 'owner', only_owner: true }],
    });
    fixture.detectChanges();

    expect(page.stranded().map((o) => o.name)).toEqual(['Lagos Nights']);
    expect(fixture.nativeElement.textContent).toContain('Make somebody else an owner');
    expect(fixture.nativeElement.textContent).toContain('write to us');

    page.deletePassword.set('the right one 12');
    expect(page.canDelete()).toBe(false);
    page.deleteAccount();
    backend.expectNone((r) => r.method === 'POST' && r.url === 'http://api.test/api/auth/erasure');
  });

  it('deletes with the password, then forgets the session that no longer exists', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview();
    page.deletePassword.set('the right one 12');
    page.deleteAccount();

    const request = backend.expectOne((r) => r.method === 'POST' && r.url === 'http://api.test/api/auth/erasure');
    expect(request.request.body).toEqual({ current_password: 'the right one 12' });
    request.flush({ status: 'completed', message: 'Your account is deleted, and you are signed out everywhere.' });

    // No sign-out request: every token the account had went with it.
    backend.expectNone('http://api.test/api/auth/logout');
    expect(session.signedIn()).toBe(false);
    expect(page.deleteOpen()).toBe(false);
  });

  it('puts a wrong password under the password box and keeps the account', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview();
    page.deletePassword.set('a guess');
    page.deleteAccount();

    backend.expectOne((r) => r.method === 'POST' && r.url === 'http://api.test/api/auth/erasure').flush(
      { message: 'That is not your current password.', errors: { current_password: ['That is not your current password.'] } },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    expect(page.deletePasswordError()).toBe('That is not your current password.');
    expect(session.signedIn()).toBe(true);
  });

  it('waits for the link when the address was never proved', () => {
    const fixture = render();
    me();

    const page = fixture.componentInstance;
    page.openDelete();
    preview({ email_verified: false });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('first we email a link to ada@example.test');

    page.deletePassword.set('the right one 12');
    page.deleteAccount();
    backend.expectOne((r) => r.method === 'POST' && r.url === 'http://api.test/api/auth/erasure').flush(
      { status: 'pending', message: 'We sent a link to ada@example.test. Your account is deleted when you open it and confirm.' },
      { status: 202, statusText: 'Accepted' },
    );

    // Nothing has gone yet, so nothing is forgotten here.
    expect(page.deletePending()).toContain('We sent a link');
    expect(page.deletePassword()).toBe('');
    expect(session.signedIn()).toBe(true);
  });
});
