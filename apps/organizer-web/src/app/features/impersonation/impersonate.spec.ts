import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Location } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { API_BASE_URL, authInterceptor } from '../../core/api';
import { SessionStore, type StaffSession } from '../../core/session';
import { Impersonate } from './impersonate';

const EXCHANGE = 'http://api.test/api/impersonation/exchange';
const OWN_KEY = 'myfiesta.organizer.session';
const STAFF_KEY = 'myfiesta.organizer.staff-session';

/** Somebody's own sign-in, already on this browser. */
const own = {
  token: 'own-token',
  user: { name: 'Ada Organizer', email: 'ada@example.com' },
  abilities: ['attendee', 'organizer'],
  organizations: [
    {
      id: 'org-own',
      name: 'Ada’s Nights',
      slug: 'adas-nights',
      role: 'owner',
      permissions: ['events.view'],
    },
  ],
};

function staffSession(expiresInMs = 3_600_000): StaffSession {
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
        permissions: ['events.view', 'events.edit'],
        verified: true,
      },
    ],
    impersonation: {
      id: 'session-1',
      organization: { id: 'org-1', name: 'Lagos Nights' },
      staff: { name: 'Sade Support' },
      reason: 'Ticket #4411',
      started_at: new Date().toISOString(),
      expires_at: new Date(Date.now() + expiresInMs).toISOString(),
      withheld: ['payouts.destination', 'team.manage'],
    },
  };
}

/**
 * The page the admin panel's "Open as organization" link lands on.
 *
 * What matters: the code comes off the address bar before anything else, is
 * traded once with nobody's own token riding along, and the staff session it
 * buys lives in this tab's sessionStorage — never where somebody's own
 * sign-in on this browser is kept.
 */
describe('Impersonate', () => {
  let backend: HttpTestingController;
  let replaced: string[];
  let navigated: (readonly unknown[])[];

  /** The route the page reads, set by each test before the page is made. */
  const route = { snapshot: {} as Record<string, unknown> };

  function open(options: { fragment?: string | null; ended?: boolean; why?: string } = {}) {
    route.snapshot = {
      fragment: options.fragment ?? null,
      data: options.ended ? { ended: true } : {},
      queryParamMap: convertToParamMap(options.why ? { why: options.why } : {}),
    };

    const fixture = TestBed.createComponent(Impersonate);
    fixture.detectChanges();

    return fixture;
  }

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    replaced = [];
    navigated = [];

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'http://api.test' },
        { provide: ActivatedRoute, useValue: route },
      ],
    });

    backend = TestBed.inject(HttpTestingController);
    vi.spyOn(TestBed.inject(Location), 'replaceState').mockImplementation((path: string) => {
      replaced.push(path);
    });
    vi.spyOn(TestBed.inject(Router), 'navigate').mockImplementation(
      async (commands: readonly unknown[]) => {
        navigated.push(commands);
        return true;
      },
    );
  });

  afterEach(() => {
    backend.verify();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('takes the code off the address bar and trades it once for the staff session', () => {
    open({ fragment: 'code=abc123' });

    expect(replaced).toEqual(['/impersonate']);

    const request = backend.expectOne(EXCHANGE);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ code: 'abc123' });
    request.flush(staffSession());

    const session = TestBed.inject(SessionStore);
    expect(session.impersonation()?.organization.name).toBe('Lagos Nights');
    expect(session.token).toBe('staff-token');
    expect(session.current()?.id).toBe('org-1');
    expect(navigated).toEqual([['/']]);

    // This tab only.
    expect(JSON.parse(sessionStorage.getItem(STAFF_KEY)!).token).toBe('staff-token');
    expect(localStorage.getItem(OWN_KEY)).toBeNull();
  });

  it('sends nobody’s own token with the code, and leaves their sign-in exactly as it was', () => {
    localStorage.setItem(OWN_KEY, JSON.stringify(own));
    localStorage.setItem(`${OWN_KEY}.org`, 'org-own');

    open({ fragment: 'code=abc123' });

    const request = backend.expectOne(EXCHANGE);
    expect(request.request.headers.has('Authorization')).toBe(false);
    expect(request.request.headers.has('X-Organization')).toBe(false);
    request.flush(staffSession());

    const session = TestBed.inject(SessionStore);
    expect(session.token).toBe('staff-token');
    expect(session.current()?.id).toBe('org-1');

    // Ending the staff session forgets only the staff session.
    session.clear();
    expect(sessionStorage.getItem(STAFF_KEY)).toBeNull();
    expect(JSON.parse(localStorage.getItem(OWN_KEY)!).token).toBe('own-token');
    expect(localStorage.getItem(`${OWN_KEY}.org`)).toBe('org-own');
  });

  it('says why when the server refuses the code', () => {
    const page = open({ fragment: 'code=used' });

    backend.expectOne(EXCHANGE).flush(
      {
        message: 'This staff link has already been used. Start a new session from the admin panel.',
      },
      { status: 410, statusText: 'Gone' },
    );
    page.detectChanges();

    const text = (page.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('This staff link did not open');
    expect(text).toContain('already been used');
    expect(TestBed.inject(SessionStore).impersonation()).toBeNull();
    expect(navigated).toEqual([]);
  });

  it('asks for nothing when the link has no code', () => {
    const page = open({ fragment: null });
    page.detectChanges();

    backend.expectNone(EXCHANGE);
    expect(replaced).toEqual(['/impersonate']);
    expect((page.nativeElement as HTMLElement).textContent).toContain(
      'This staff link is incomplete',
    );
  });

  it('says the session ended, and how, without asking the server anything', () => {
    const page = open({ ended: true, why: 'expired' });
    page.detectChanges();

    backend.expectNone(EXCHANGE);
    expect(replaced).toEqual([]);

    const text = (page.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('Staff session ended');
    expect(text).toContain('The hour is up');
  });
});

describe('SessionStore with a staff session', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  afterEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  it('picks the staff session back up on reload, over this browser’s own sign-in', () => {
    localStorage.setItem(OWN_KEY, JSON.stringify(own));
    sessionStorage.setItem(STAFF_KEY, JSON.stringify(staffSession()));

    const session = TestBed.inject(SessionStore);

    expect(session.token).toBe('staff-token');
    expect(session.impersonation()?.staff.name).toBe('Sade Support');
    expect(session.can('events.edit')).toBe(true);
    expect(session.can('team.manage')).toBe(false);
  });

  it('drops a staff session whose hour is up rather than draw the console for a moment', () => {
    localStorage.setItem(OWN_KEY, JSON.stringify(own));
    sessionStorage.setItem(STAFF_KEY, JSON.stringify(staffSession(-1_000)));

    const session = TestBed.inject(SessionStore);

    expect(session.impersonation()).toBeNull();
    expect(session.token).toBe('own-token');
    expect(sessionStorage.getItem(STAFF_KEY)).toBeNull();
  });

  it('keeps a switch of organization out of the own sign-in’s memory', () => {
    const session = TestBed.inject(SessionStore);
    session.startImpersonation(staffSession());

    session.select('org-1');
    expect(localStorage.getItem(`${OWN_KEY}.org`)).toBeNull();

    // The staff member's name is not theirs to edit from here.
    session.updateUser({ name: 'Renamed', email: 'x@example.com' });
    expect(session.user()?.name).toBe('Sade Support');
    expect(localStorage.getItem(OWN_KEY)).toBeNull();
  });
});
