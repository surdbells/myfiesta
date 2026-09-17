import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * vi.mock factories are hoisted above everything else in the file, so the
 * state they close over has to be hoisted with them — a plain const here is
 * still undefined when the first one runs.
 */
const fake = vi.hoisted(() => ({
  scheduled: [] as { notifications: { id: number; title: string; body: string; schedule: { at: Date } }[] }[],
  cancelled: [] as unknown[],
  pending: [] as { id: number }[],
  permission: 'granted',
  store: new Map<string, string>(),
}));

vi.mock('@capacitor/local-notifications', () => ({
  LocalNotifications: {
    schedule: async (options: (typeof fake.scheduled)[number]) => {
      fake.scheduled.push(options);
    },
    getPending: async () => ({ notifications: fake.pending }),
    cancel: async (options: unknown) => {
      fake.cancelled.push(options);
    },
    checkPermissions: async () => ({ display: fake.permission }),
    requestPermissions: async () => ({ display: fake.permission }),
  },
}));

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

vi.mock('@capacitor/core', () => ({
  Capacitor: { isNativePlatform: () => true, getPlatform: () => 'ios' },
}));

import { Reminders } from './reminders';
import { Ticket } from './api';

const ticket = (over: Partial<Ticket> & { starts_at?: string } = {}): Ticket =>
  ({
    id: over.id ?? 't-' + Math.random(),
    code: 'ABC',
    status: over.status ?? 'valid',
    type: 'General',
    holder_name: 'Ada',
    event: {
      slug: 'afro-fest',
      title: 'Afro Fest',
      starts_at: over.starts_at ?? new Date(Date.now() + 86_400_000).toISOString(),
      timezone: 'America/Toronto',
      city: 'Toronto',
      ...(over.event ?? {}),
    },
  }) as Ticket;

/**
 * Reminders are scheduled on the phone, so the thing worth testing is what
 * ends up on the schedule — a reminder that outlives its ticket is a
 * notification about somebody else's Saturday.
 */
describe('Reminders', () => {
  let reminders: Reminders;

  beforeEach(async () => {
    fake.scheduled.length = 0;
    fake.cancelled.length = 0;
    fake.pending = [];
    fake.permission = 'granted';
    fake.store.clear();

    TestBed.configureTestingModule({});
    reminders = TestBed.inject(Reminders);
  });

  async function turnOn(tickets: Ticket[] = []) {
    await reminders.restore();

    return reminders.set(true, tickets);
  }

  it('is off until somebody asks for it', async () => {
    await reminders.restore();

    expect(reminders.on()).toBe(false);

    await reminders.reconcile([ticket()]);
    expect(fake.scheduled).toEqual([]);
  });

  it('schedules one reminder before doors', async () => {
    const starts = new Date(Date.now() + 86_400_000);
    await turnOn([ticket({ starts_at: starts.toISOString() })]);

    expect(fake.scheduled).toHaveLength(1);
    const [first] = fake.scheduled[0].notifications;
    expect(first.title).toBe('Afro Fest');
    // Three hours before, not at the door.
    expect(starts.getTime() - first.schedule.at.getTime()).toBe(3 * 3_600_000);
  });

  it('tells somebody about a night once, however many tickets they hold', async () => {
    await turnOn([ticket(), ticket(), ticket()]);

    expect(fake.scheduled[0].notifications).toHaveLength(1);
  });

  it('leaves out tickets that are spent or nights that have gone', async () => {
    await turnOn([
      ticket({ status: 'checked_in' }),
      ticket({ starts_at: new Date(Date.now() - 86_400_000).toISOString() }),
    ]);

    // Nothing to say: scheduling a past time fires immediately on some
    // Androids, which is a notification about last Saturday.
    expect(fake.scheduled).toEqual([]);
  });

  it('clears what it scheduled before scheduling again', async () => {
    fake.pending = [{ id: 1 }];
    await turnOn([ticket()]);

    expect(fake.cancelled).toEqual([{ notifications: [{ id: 1 }] }]);
  });

  it('will not stay on when the phone says no', async () => {
    fake.permission = 'denied';

    const on = await turnOn([ticket()]);

    expect(on).toBe(false);
    expect(reminders.on()).toBe(false);
    expect(reminders.refused()).toBe(true);
    expect(fake.scheduled).toEqual([]);
  });

  it('remembers being switched on', async () => {
    await turnOn([ticket()]);

    const second = TestBed.inject(Reminders);
    await second.restore();

    expect(second.on()).toBe(true);
  });

  it('takes the schedule with it when switched off', async () => {
    await turnOn([ticket()]);
    fake.pending = [{ id: 1 }];
    fake.cancelled.length = 0;

    await reminders.set(false, []);

    expect(reminders.on()).toBe(false);
    expect(fake.cancelled).toEqual([{ notifications: [{ id: 1 }] }]);
  });
});
