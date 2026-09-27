import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from './api';
import { EmailVerification } from './email-verification';
import { isEmailUnverified } from './errors';
import { SessionStore } from './session';
import { Dialogs, ToastStore } from '../ui';

/**
 * "Confirm your email address", on the phone.
 *
 * Putting a night on sale, asking to be paid and changing where payouts go
 * wait for a proved address. The phone used to learn that only from the
 * refusal, in a toast gone in three seconds with nothing to do about it. Now
 * the account says so up front, for the banner at the top of Manage, and the
 * refusal — wherever it happens — opens one sheet that can send the link again.
 */
describe('EmailVerification', () => {
  let asked: { title: string; message?: string; confirm: string }[];
  let say: boolean;
  let me: ReturnType<typeof vi.fn>;
  let resend: ReturnType<typeof vi.fn>;
  let toasts: string[];
  let scope: ReturnType<typeof signal<'attendee' | 'organizer'>>;

  beforeEach(() => {
    asked = [];
    say = true;
    toasts = [];
    scope = signal<'attendee' | 'organizer'>('organizer');
    me = vi.fn(async () => ({ name: 'Ada', email: 'ada@example.test', email_verified: false, phone: null, timezone: null, organizations: [] }));
    resend = vi.fn(async () => ({ message: 'We sent a link to ada@example.test. Open it to confirm the address.', verified: false }));

    TestBed.configureTestingModule({
      providers: [
        { provide: Api, useValue: { unverified: signal<{ message: string } | null>(null), me, resendVerification: resend } },
        {
          provide: SessionStore,
          useValue: {
            session: signal({ email: 'ada@example.test' }),
            canSeeSales: () => scope() === 'organizer',
          },
        },
        {
          provide: Dialogs,
          useValue: {
            confirm: async (options: { title: string; message?: string; confirm: string }) => {
              asked.push(options);

              return say;
            },
          },
        },
        { provide: ToastStore, useValue: { show: (text: string) => toasts.push(text) } },
      ],
    });
  });

  afterEach(() => TestBed.resetTestingModule());

  const settle = () => new Promise((resolve) => setTimeout(resolve));

  it('learns from the account whether the address is proved', async () => {
    const verification = TestBed.inject(EmailVerification);

    await verification.check();

    expect(verification.verified()).toBe(false);
  });

  it('does not ask for somebody who only buys tickets', async () => {
    scope.set('attendee');
    const verification = TestBed.inject(EmailVerification);

    await verification.check();

    expect(me).not.toHaveBeenCalled();
    expect(verification.verified()).toBeNull();
  });

  it('turns the refusal into a prompt that sends the link again', async () => {
    const verification = TestBed.inject(EmailVerification);
    const api = TestBed.inject(Api) as unknown as { unverified: ReturnType<typeof signal<{ message: string } | null>> };

    api.unverified.set({ message: 'Confirm your email address first. We have sent a link to ada@example.test — open it, then try again.' });
    TestBed.tick();
    await settle();

    expect(verification.verified()).toBe(false);
    expect(asked).toHaveLength(1);
    expect(asked[0].message).toContain('We have sent a link to ada@example.test');
    expect(asked[0].confirm).toBe('Send the link again');

    // Pressed: the link goes, and where it went is said.
    expect(resend).toHaveBeenCalledOnce();
    expect(toasts.at(-1)).toContain('We sent a link to ada@example.test');
  });

  it('sends nothing when the prompt is put aside', async () => {
    say = false;
    TestBed.inject(EmailVerification);
    const api = TestBed.inject(Api) as unknown as { unverified: ReturnType<typeof signal<{ message: string } | null>> };

    api.unverified.set({ message: 'Confirm your email address first.' });
    TestBed.tick();
    await settle();

    expect(asked).toHaveLength(1);
    expect(resend).not.toHaveBeenCalled();
  });

  it('passes on how long to wait when asked too often', async () => {
    resend.mockRejectedValueOnce(new ApiError('A link went to ada@example.test a moment ago. Ask again in 40 minutes.', 429));
    const verification = TestBed.inject(EmailVerification);

    await verification.resend();

    expect(verification.answer()).toContain('Ask again in 40 minutes');
  });

  it('knows the refusal from any other 403', () => {
    expect(isEmailUnverified(new ApiError('Confirm your email address first.', 403, undefined, 'email_unverified'))).toBe(true);
    expect(isEmailUnverified(new ApiError('Only owners can do that.', 403))).toBe(false);
    expect(isEmailUnverified(new Error('offline'))).toBe(false);
  });
});

/** The client, as a publish refused for an unproved address reaches it. */
describe('Api, meeting an unproved address', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('names the refusal and says it once for the shell to answer', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        new Response(JSON.stringify({ message: 'Confirm your email address first.', code: 'email_unverified' }), { status: 403 }),
      ),
    );

    TestBed.resetTestingModule();
    const api = TestBed.inject(Api);
    api.token = 'tok';

    const refused = await api.request('POST', '/api/organizer/events/e1/publish', { status: 'published' }).catch((e) => e);

    expect(isEmailUnverified(refused)).toBe(true);
    expect(api.unverified()?.message).toBe('Confirm your email address first.');
  });
});
