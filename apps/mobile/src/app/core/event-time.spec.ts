import { describe, expect, it } from 'vitest';
import { longEventTime, shortEventTime } from './event-time';
import { formatMoney } from './money';

describe('event times', () => {
  it('reads in the event’s own zone, not the reader’s', () => {
    // 02:00 UTC is 10pm the evening before in Toronto, and 3am in Lagos.
    const iso = '2026-10-04T02:00:00Z';

    expect(shortEventTime(iso, 'America/Toronto')).toContain('10:00');
    expect(shortEventTime(iso, 'America/Toronto')).toContain('Oct 3');
    expect(shortEventTime(iso, 'Africa/Lagos')).toContain('3:00');
  });

  it('falls back to UTC rather than blanking the line on an unknown zone', () => {
    expect(shortEventTime('2026-10-04T02:00:00Z', 'Mars/Olympus')).toContain('2:00');
  });

  it('says nothing for a date it cannot read', () => {
    expect(shortEventTime('not a date', 'UTC')).toBe('');
    expect(longEventTime('', 'UTC')).toBe('');
  });
});

describe('money', () => {
  it('keeps the currency beside the number, whatever the reader’s locale', () => {
    const cad = formatMoney({ amount: 151_780, currency: 'CAD' });
    const ngn = formatMoney({ amount: 4_500_000, currency: 'NGN' });

    expect(cad).toContain('1,517.80');
    expect(ngn).toContain('45,000.00');

    // Two currencies must never format alike: a Lagos price beside a Toronto
    // one is exactly where a bare number becomes a wrong number.
    expect(cad.replace(/[\d.,]/g, '')).not.toBe(ngn.replace(/[\d.,]/g, ''));
  });

  it('is a dash when there is no amount, never a confident zero', () => {
    expect(formatMoney(null)).toBe('—');
  });
});
