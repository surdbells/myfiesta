import type { Route, Routes } from '@angular/router';
import { routes } from '../app.routes';
import { AUDIENCE_NAV, AUDIENCE_TAB } from '../features/audience/enabled';
import { DEMAND_NAV } from '../features/demand/enabled';
import { PASSES_NAV } from '../features/passes/enabled';
import { PERKS_TAB } from '../features/perks/enabled';
import { FEEDBACK_TAB, SURVEYS_NAV } from '../features/surveys/enabled';
import { TEMPLATES_NAV } from '../features/templates/enabled';
import { placeAfter } from './feature-flags';

const place = (items: string[], entries: { after: string; name: string }[]) =>
  placeAfter(
    items,
    entries,
    (item) => item,
    (entry) => entry.name,
  );

describe('placeAfter', () => {
  it('puts an entry straight after the one it names', () => {
    expect(place(['a', 'b', 'c'], [{ after: 'a', name: 'x' }])).toEqual(['a', 'x', 'b', 'c']);
  });

  it('keeps the given order when two name the same entry', () => {
    const placed = place(
      ['a', 'b'],
      [
        { after: 'a', name: 'x' },
        { after: 'a', name: 'y' },
      ],
    );

    expect(placed).toEqual(['a', 'x', 'y', 'b']);
  });

  it('puts an entry last when the one it names is not there', () => {
    // Hidden from this person, say: the entry still shows, at the end.
    expect(place(['a', 'b'], [{ after: 'gone', name: 'x' }])).toEqual(['a', 'b', 'x']);
  });

  it('lets an entry follow one placed before it', () => {
    const placed = place(
      ['a', 'b'],
      [
        { after: 'a', name: 'x' },
        { after: 'x', name: 'y' },
      ],
    );

    expect(placed).toEqual(['a', 'x', 'y', 'b']);
  });

  it('leaves the list it was given alone', () => {
    const items = ['a'];
    place(items, [{ after: 'a', name: 'x' }]);

    expect(items).toEqual(['a']);
  });
});

/*
 * Whether each feature is on is its own business, and not asserted here: a
 * test that pinned them all off would be one more file every feature had to
 * edit to switch itself on. What is pinned is that switching one on can only
 * ever lead somewhere — the router's wildcard sends an unknown address to the
 * events list, which reads as the console ignoring the click.
 */
describe('Screens that arrive switched off', () => {
  const lazyChildren = async (route: Route | undefined): Promise<Routes> => {
    const loaded = await route?.loadChildren?.();

    return Array.isArray(loaded) ? loaded : [];
  };

  it('has a route behind every sidebar entry', async () => {
    for (const entry of [TEMPLATES_NAV, SURVEYS_NAV, DEMAND_NAV, PASSES_NAV, AUDIENCE_NAV]) {
      const route = routes.find((r) => '/' + r.path === entry.link);

      expect(route, entry.link).toBeDefined();
      expect(route?.canActivate?.length, entry.link).toBeGreaterThan(0);
      expect((await lazyChildren(route)).length, entry.link).toBeGreaterThan(0);
    }
  });

  it('has a route behind every event tab', async () => {
    const workspace = routes.find((r) => r.path === 'events/:id');

    for (const tab of [FEEDBACK_TAB, PERKS_TAB, AUDIENCE_TAB]) {
      const route = workspace?.children?.find((r) => r.path === tab.path);

      expect(route, tab.path).toBeDefined();
      expect((await lazyChildren(route)).length, tab.path).toBeGreaterThan(0);
    }
  });
});
