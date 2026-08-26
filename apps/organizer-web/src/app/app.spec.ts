import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { SessionStore } from './core/session';
import { Membership, Permission, Session } from './core/api.types';

/**
 * The permissions the API resolves for each role.
 *
 * Mirrored here only so a fixture looks like a real session. The console never
 * derives these — it reads what the server sent — and PermissionAuthorityTest
 * on the API side is what pins the actual table.
 */
const PERMISSIONS: Record<Membership['role'], Permission[]> = {
  owner: [
    'events.view', 'events.create', 'events.edit', 'events.publish',
    'events.cancel', 'events.delete', 'tickets.manage', 'codes.manage',
    'attendees.view', 'door.scan', 'money.view', 'refunds.process', 'messages.send',
  ],
  manager: [
    'events.view', 'events.create', 'events.edit', 'events.publish',
    'tickets.manage', 'codes.manage', 'attendees.view', 'door.scan',
    'money.view', 'refunds.process', 'messages.send',
  ],
  finance: ['events.view', 'money.view', 'refunds.process'],
  marketing: ['events.view', 'attendees.view', 'codes.manage', 'messages.send'],
  door: ['events.view', 'door.scan'],
};

function sessionWith(role: Membership['role']): Session {
  return {
    token: 'test-token',
    user: { name: 'Ada Okafor', email: 'ada@example.test' },
    abilities: ['attendee', 'organizer'],
    organizations: [
      {
        id: 'org-1',
        name: 'Lagos Nights',
        slug: 'lagos-nights',
        role,
        permissions: PERMISSIONS[role],
      },
    ],
  };
}

describe('App shell', () => {
  beforeEach(async () => {
    localStorage.clear();

    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter([]), provideHttpClient()],
    }).compileComponents();
  });

  it('shows no chrome to somebody signed out', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    // The sign-in screen is not wrapped in the console shell: there is no
    // organization to put in a sidebar for somebody who has not proved they
    // belong to one.
    expect(fixture.nativeElement.querySelector('.side')).toBeNull();
  });

  it('names the organization and the role once signed in', () => {
    TestBed.inject(SessionStore).start(sessionWith('finance'));

    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const side = fixture.nativeElement.querySelector('.side') as HTMLElement;

    expect(side).not.toBeNull();
    expect(side.textContent).toContain('Lagos Nights');
    // Shown deliberately: a permission error makes far more sense when the
    // role is already on screen.
    expect(side.textContent).toContain('finance');
  });

  it('only offers sections that have a route behind them', () => {
    TestBed.inject(SessionStore).start(sessionWith('owner'));

    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const links = Array.from<HTMLAnchorElement>(
      fixture.nativeElement.querySelectorAll('.nav__link'),
    ).map((a) => a.getAttribute('href'));

    // Every wildcard in this router redirects to the events list, so a nav
    // item pointing at a route nobody wrote does not 404 — it silently
    // lands somewhere else, which reads as the console ignoring the click.
    const known = ['/', '/events', '/payouts'];

    for (const href of links) {
      expect(known).toContain(href);
    }
  });
});

describe('SessionStore permissions', () => {
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient()] });
  });

  it('lets an owner edit events and see the money', () => {
    const store = TestBed.inject(SessionStore);
    store.start(sessionWith('owner'));

    expect(store.canEditEvents()).toBe(true);
    expect(store.canSeeMoney()).toBe(true);
  });

  it('lets finance see the money without editing anything', () => {
    const store = TestBed.inject(SessionStore);
    store.start(sessionWith('finance'));

    expect(store.canSeeMoney()).toBe(true);
    expect(store.canEditEvents()).toBe(false);
  });

  it('keeps marketing away from the takings', () => {
    const store = TestBed.inject(SessionStore);
    store.start(sessionWith('marketing'));

    // Sending to the guest list is not the same as seeing what came in.
    expect(store.canSeeMoney()).toBe(false);
    expect(store.canEditEvents()).toBe(false);
  });

  it('grants door staff nothing in the console', () => {
    const store = TestBed.inject(SessionStore);
    store.start(sessionWith('door'));

    expect(store.canEditEvents()).toBe(false);
    expect(store.canSeeMoney()).toBe(false);
  });

  it('forgets everything on sign-out', () => {
    const store = TestBed.inject(SessionStore);
    store.start(sessionWith('owner'));
    store.clear();

    // A token the server has stopped accepting is not worth keeping; holding
    // it produces a console that looks signed in and fails every action.
    expect(store.signedIn()).toBe(false);
    expect(store.token).toBeNull();
    expect(localStorage.getItem('myfiesta.organizer.session')).toBeNull();
  });

  it('survives a reload', () => {
    TestBed.inject(SessionStore).start(sessionWith('owner'));

    // A fresh injector, as a page refresh would produce.
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideHttpClient()] });

    expect(TestBed.inject(SessionStore).signedIn()).toBe(true);
  });
});
