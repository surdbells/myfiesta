import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import { answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { SessionStore, type StaffSession } from '../../core/session';
import { StaffBanner } from './staff-banner';

const END = 'http://api.test/api/impersonation';
const OWN_KEY = 'myfiesta.organizer.session';

const START = new Date('2026-09-26T12:00:00Z').getTime();

function staffSession(): StaffSession {
  return {
    token: 'staff-token',
    user: { name: 'Sade Support', email: 'sade@myfiesta.ca' },
    abilities: ['organizer', 'impersonation'],
    organizations: [
      {
        id: 'org-1',
        name: 'Lagos Nights',
        slug: 'lagos-nights',
        role: 'owner',
        permissions: ['events.view'],
      },
    ],
    impersonation: {
      id: 'session-1',
      organization: { id: 'org-1', name: 'Lagos Nights' },
      staff: { name: 'Sade Support' },
      reason: 'Ticket #4411',
      started_at: new Date(START).toISOString(),
      expires_at: new Date(START + 60 * 60_000).toISOString(),
      withheld: ['payouts.destination'],
    },
  };
}

/**
 * The strip across every screen while staff are viewing an organization.
 *
 * It must say whose console this is and who is looking, count down the hour
 * the server allows, and — when End is pressed — revoke the token on the
 * server before this tab forgets it. Nobody's own sign-in on this browser is
 * touched by any of it.
 */
describe('StaffBanner', () => {
  let backend: HttpTestingController;
  let navigated: { commands: readonly unknown[]; extras: unknown }[];

  function render() {
    const fixture = TestBed.createComponent(StaffBanner);
    fixture.detectChanges();

    return fixture;
  }

  const text = (fixture: { nativeElement: HTMLElement }) =>
    (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    vi.setSystemTime(START);
    localStorage.clear();
    sessionStorage.clear();
    navigated = [];

    // Somebody's own sign-in on this browser, which must outlast all of this.
    localStorage.setItem(
      OWN_KEY,
      JSON.stringify({
        token: 'own-token',
        user: { name: 'Ada', email: 'a@example.com' },
        abilities: [],
        organizations: [],
      }),
    );

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    vi.spyOn(TestBed.inject(Router), 'navigate').mockImplementation(
      async (commands: readonly unknown[], extras?: unknown) => {
        navigated.push({ commands, extras });
        return true;
      },
    );
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
    vi.useRealTimers();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('draws nothing for somebody signed in as themselves', () => {
    const banner = render();

    expect((banner.nativeElement as HTMLElement).querySelector('.staff-banner')).toBeNull();
  });

  it('names the organization and the member of staff, and counts down the hour', () => {
    TestBed.inject(SessionStore).startImpersonation(staffSession());
    const banner = render();

    expect(text(banner)).toContain('You are viewing Lagos Nights as myFiesta staff (Sade Support)');
    expect(text(banner)).toContain('60:00');
    expect(text(banner)).toContain('End');

    vi.advanceTimersByTime(90_000);
    banner.detectChanges();

    expect(text(banner)).toContain('58:30');
    expect(text(banner)).toContain('59 minutes left');
  });

  it('asks before ending, and sends nothing when the answer is no', async () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());
    const banner = render();

    (banner.nativeElement as HTMLElement)
      .querySelector<HTMLButtonElement>('.staff-banner__end')!
      .click();
    await settle();

    expect(asked()?.title).toBe('End the staff session?');
    expect(asked()?.text).toContain('stops acting as Lagos Nights');
    expect(asked()?.buttons).toEqual(['Cancel', 'End staff session']);

    await answer('Cancel');

    backend.expectNone(END);
    expect(session.impersonation()).not.toBeNull();
    expect(navigated).toEqual([]);
  });

  it('revokes the session on the server when End is pressed and confirmed, then leaves', async () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());
    const banner = render();

    (banner.nativeElement as HTMLElement)
      .querySelector<HTMLButtonElement>('.staff-banner__end')!
      .click();
    await settle();
    await answer('End staff session');
    banner.detectChanges();

    const request = backend.expectOne(END);
    expect(request.request.method).toBe('DELETE');
    expect(request.request.headers.get('Authorization')).toBe('Bearer staff-token');
    expect(text(banner)).toContain('Ending');

    // Still the staff session until the server has answered.
    expect(session.impersonation()).not.toBeNull();
    request.flush({ message: 'Staff session ended.' });

    expect(session.impersonation()).toBeNull();
    expect(session.signedIn()).toBe(false);
    expect(navigated).toEqual([
      { commands: ['/impersonate/ended'], extras: { replaceUrl: true, queryParams: undefined } },
    ]);
    expect(JSON.parse(localStorage.getItem(OWN_KEY)!).token).toBe('own-token');
  });

  it('forgets the session here even when the revoke does not arrive, and says so', async () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());
    const banner = render();

    void banner.componentInstance.end();
    await settle();
    await answer('End staff session');
    backend.expectOne(END).error(new ProgressEvent('offline'), { status: 0 });

    expect(session.impersonation()).toBeNull();
    expect(navigated[0]).toEqual({
      commands: ['/impersonate/ended'],
      extras: { replaceUrl: true, queryParams: { why: 'unconfirmed' } },
    });
  });

  it('treats a token the server stopped taking as the end of the session, not a sign-in to redo', () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());

    TestBed.inject(HttpClient)
      .get('http://api.test/api/organizer/overview')
      .subscribe({ error: () => undefined });
    backend
      .expectOne('http://api.test/api/organizer/overview')
      .flush({}, { status: 401, statusText: 'Unauthorized' });

    expect(session.impersonation()).toBeNull();
    expect(navigated[0].commands).toEqual(['/impersonate/ended']);
    expect(JSON.parse(localStorage.getItem(OWN_KEY)!).token).toBe('own-token');
  });

  it('leaves when the hour is up, without asking the server', () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());
    render();

    vi.advanceTimersByTime(60 * 60_000 + 1_000);

    backend.expectNone(END);
    expect(session.impersonation()).toBeNull();
    expect(navigated[0]).toEqual({
      commands: ['/impersonate/ended'],
      extras: { replaceUrl: true, queryParams: { why: 'expired' } },
    });
  });
});
