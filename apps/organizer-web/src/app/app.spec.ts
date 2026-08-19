import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { App } from './app';
import { SessionStore } from './core/session';
import { Membership, Session } from './core/api.types';

function sessionWith(role: Membership['role']): Session {
  return {
    token: 'test-token',
    user: { name: 'Ada Okafor', email: 'ada@example.test' },
    abilities: ['attendee', 'organizer'],
    organizations: [{ id: 'org-1', name: 'Lagos Nights', slug: 'lagos-nights', role }],
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

    // The sign-in screen should not be wrapped in an organization header for
    // an organization nobody has proved they belong to.
    expect(fixture.nativeElement.querySelector('.chrome')).toBeNull();
  });

  it('names the organization and the role once signed in', () => {
    TestBed.inject(SessionStore).start(sessionWith('finance'));

    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const chrome = fixture.nativeElement.querySelector('.chrome') as HTMLElement;

    expect(chrome).not.toBeNull();
    expect(chrome.textContent).toContain('Lagos Nights');
    // Shown deliberately: a permission error makes far more sense when the
    // role is already on screen.
    expect(chrome.textContent).toContain('finance');
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
