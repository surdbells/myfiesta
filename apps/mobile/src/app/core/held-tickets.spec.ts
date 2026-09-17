import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const fake = vi.hoisted(() => ({ store: new Map<string, string>() }));

vi.mock('@capacitor/preferences', () => ({
  Preferences: {
    get: async ({ key }: { key: string }) => ({ value: fake.store.get(key) ?? null }),
    set: async ({ key, value }: { key: string; value: string }) => {
      fake.store.set(key, value);
    },
    remove: async ({ key }: { key: string }) => {
      fake.store.delete(key);
    },
  },
}));

import { Api, ApiError, Ticket } from './api';
import { HeldTicketStore } from './held-tickets';

const ticket = (id: string): Ticket =>
  ({
    id,
    code: 'ABC-' + id,
    status: 'valid',
    type: 'General',
    holder_name: 'Ada',
    event: {
      slug: 'afro-fest',
      title: 'Afro Fest',
      starts_at: new Date(Date.now() + 86_400_000).toISOString(),
      timezone: 'America/Toronto',
      city: 'Toronto',
    },
  }) as Ticket;

/**
 * The app tells people their tickets work with no signal.
 *
 * These are the rules that make that true without making it a lie in the other
 * direction: a reachable server is always the truth, and a refusal is not a
 * network failure.
 */
describe('HeldTicketStore', () => {
  let held: HeldTicketStore;
  let answer: () => Promise<Ticket[]>;
  let calls: number;

  beforeEach(() => {
    fake.store.clear();
    calls = 0;
    answer = async () => [ticket('one')];

    TestBed.configureTestingModule({
      providers: [
        {
          provide: Api,
          useValue: {
            tickets: async () => {
              calls++;

              return answer();
            },
          },
        },
      ],
    });

    held = TestBed.inject(HeldTicketStore);
  });

  const offline = () => {
    answer = async () => {
      throw new ApiError('No connection. Check signal and try again.', 0);
    };
  };

  it('answers from the server and writes the answer down', async () => {
    const first = await held.list();

    expect(first.tickets).toHaveLength(1);
    expect(first.stale).toBe(false);
    expect(first.checkedAt).toBeInstanceOf(Date);
  });

  it('falls back to the phone when the server cannot be reached', async () => {
    await held.list();
    offline();

    const again = await held.list();

    // The whole promise: a guest in a basement venue still has their QR.
    expect(again.tickets.map((t) => t.id)).toEqual(['one']);
    expect(again.stale).toBe(true);
    expect(again.checkedAt).toBeInstanceOf(Date);
  });

  it('prefers the server even when there is something saved', async () => {
    await held.list();
    answer = async () => [ticket('two')];

    const again = await held.list();

    // A ticket transferred away has to stop working on the phone that sent it.
    expect(again.tickets.map((t) => t.id)).toEqual(['two']);
    expect(again.stale).toBe(false);
    expect(calls).toBe(2);
  });

  it('does not fall back when the server refuses', async () => {
    await held.list();

    for (const status of [401, 403, 500]) {
      answer = async () => {
        throw new ApiError('No.', status);
      };

      // A 401 means this phone is signed out and the saved list is somebody
      // else's; a 500 means the server is there and unhappy. Neither is a
      // reason to show a stale ticket as though it were current.
      await expect(held.list()).rejects.toMatchObject({ status });
    }
  });

  it('has nothing to offer on a first run with no signal', async () => {
    offline();

    // An empty list would read as "you have no tickets", which is worse than
    // saying the connection failed.
    await expect(held.list()).rejects.toMatchObject({ status: 0 });
  });

  it('forgets everything when somebody signs out', async () => {
    await held.list();
    await held.forget();
    offline();

    await expect(held.list()).rejects.toMatchObject({ status: 0 });
  });
});
