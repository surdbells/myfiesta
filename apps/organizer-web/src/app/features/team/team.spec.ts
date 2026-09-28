import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { API_BASE_URL } from '../../core/api';
import type { TeamPage } from '../../core/api.types';
import { allowDialogs, answer, asked, forgetDialogs, settle } from '../../core/confirm-testing';
import { Team } from './team';

const TEAM = 'http://api.test/api/organizer/team';

function team(): TeamPage {
  return {
    members: [
      { id: 'u-1', name: 'Ada Okafor', email: 'ada@example.test', role: 'owner', joined_at: null, is_you: true },
      { id: 'u-2', name: 'Tolu Bello', email: 'tolu@example.test', role: 'door', joined_at: null, is_you: false },
    ],
    invitations: [
      { id: 'inv-1', email: 'kemi@example.test', role: 'finance', invited_by: 'Ada Okafor', expires_at: '2026-10-05T12:00:00Z', expired: false },
    ],
    roles: [
      { value: 'owner', label: 'Owner', description: 'do everything, including where payouts go' },
      { value: 'manager', label: 'Manager', description: 'run events, but not see payouts' },
      { value: 'finance', label: 'Finance', description: 'see orders and payouts' },
      { value: 'door', label: 'Door', description: 'scan tickets at the door' },
    ],
  };
}

/**
 * Who works on the organization, changed only once it is confirmed.
 *
 * An invitation is an email to somebody outside and a role is what they can
 * see the moment they accept — so each is said back, and saying no sends
 * nothing.
 */
describe('Team', () => {
  let backend: HttpTestingController;

  beforeAll(allowDialogs);

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting(), { provide: API_BASE_URL, useValue: 'http://api.test' }],
    });

    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    backend.verify();
    forgetDialogs();
  });

  function render() {
    const fixture = TestBed.createComponent(Team);
    backend.expectOne(TEAM).flush(team());
    fixture.detectChanges();

    return fixture.componentInstance;
  }

  it('names the address and the role before inviting, and sends nothing when the answer is no', async () => {
    const page = render();
    page.inviteEmail.set('kunle@example.test');
    page.inviteRole.set('finance');

    void page.invite();
    await settle();

    expect(asked()?.title).toBe('Invite kunle@example.test as Finance?');
    expect(asked()?.text).toContain('they can see orders and payouts');
    expect(asked()?.buttons).toEqual(['Cancel', 'Send the invitation']);

    await answer('Cancel');

    backend.expectNone(`${TEAM}/invitations`);
    expect(page.inviting()).toBe(false);
  });

  it('sends the invitation once it is confirmed', async () => {
    const page = render();
    page.inviteEmail.set('kunle@example.test');
    page.inviteRole.set('finance');

    void page.invite();
    await settle();
    await answer('Send the invitation');

    const request = backend.expectOne(`${TEAM}/invitations`);
    expect(request.request.body).toEqual({ email: 'kunle@example.test', role: 'finance' });
    request.flush({ message: 'Invitation sent.' });
    backend.expectOne(TEAM).flush(team());
  });

  it('asks before changing a role, and puts the dropdown back when the answer is no', async () => {
    const page = render();
    const tolu = team().members[1];

    void page.changeRole(tolu, 'finance');
    await settle();

    expect(asked()?.title).toBe('Make Tolu Bello Finance?');
    expect(asked()?.text).toContain('goes from Door to Finance straight away');
    expect(page.displayRole(tolu)).toBe('finance');

    await answer('Cancel');

    backend.expectNone(`${TEAM}/members/u-2`);
    expect(page.displayRole(tolu)).toBe('door');
  });

  it('asks before withdrawing an invitation, and withdraws nothing when the answer is no', async () => {
    const page = render();

    void page.revoke('inv-1');
    await settle();

    expect(asked()?.title).toBe('Withdraw the invitation to kemi@example.test?');
    expect(asked()?.buttons).toEqual(['Cancel', 'Withdraw the invitation']);

    await answer('Cancel');

    backend.expectNone(`${TEAM}/invitations/inv-1`);
  });
});
