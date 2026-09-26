import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Navigation } from './navigation';

@Component({ template: '' })
class Blank {}

/**
 * Back that goes back.
 *
 * Every screen used to navigate forward to a fixed parent when its arrow was
 * pressed — an event opened from Saved went "back" to Home, and history only
 * grew. These pin the two halves of the replacement: a screen with something
 * underneath goes back to it, and one opened straight from a link (nothing
 * underneath) goes to its parent instead of closing the app.
 */
describe('Navigation', () => {
  let router: Router;
  let nav: Navigation;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', component: Blank },
          { path: 'saved', component: Blank },
          { path: 'e/:slug', component: Blank },
          { path: 'tickets', component: Blank },
          { path: 'settings', component: Blank },
        ]),
      ],
    });

    router = TestBed.inject(Router);
    nav = TestBed.inject(Navigation);
  });

  it('has nothing underneath the screen the app opened on', async () => {
    await router.navigateByUrl('/e/afro-fest');

    expect(nav.canPop()).toBe(false);
  });

  it('opened from a link, goes to the parent and replaces, rather than leaving the app', async () => {
    await router.navigateByUrl('/e/afro-fest');
    const navigate = vi.spyOn(router, 'navigate');
    const back = vi.spyOn(history, 'back');

    nav.back('/');

    expect(back).not.toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(['/'], { replaceUrl: true });
  });

  it('with a screen underneath, goes back to it', async () => {
    await router.navigateByUrl('/saved');
    await router.navigateByUrl('/e/afro-fest');
    const back = vi.spyOn(history, 'back').mockImplementation(() => undefined);

    nav.back('/');

    expect(nav.canPop()).toBe(true);
    expect(back).toHaveBeenCalled();
  });

  it('knows a push from a switch between tabs', async () => {
    await router.navigateByUrl('/');

    await router.navigateByUrl('/e/afro-fest');
    expect(nav.direction()).toBe('forward');

    await router.navigateByUrl('/tickets');
    await router.navigateByUrl('/settings');
    expect(nav.direction()).toBe('switch');
  });

  it('runs the top screen’s back for the phone’s own back, and only that screen’s', () => {
    const lower = {};
    const upper = {};
    const ran: string[] = [];

    nav.claimBack(lower, () => ran.push('lower'));
    nav.claimBack(upper, () => ran.push('upper'));

    // The screen underneath leaving must not take the top one's back with it.
    nav.releaseBack(lower);
    expect(nav.goBack()).toBe(true);
    expect(ran).toEqual(['upper']);

    nav.releaseBack(upper);
    expect(nav.goBack()).toBe(false);
  });

  it('gives a list its scroll position back only when arriving by going back', async () => {
    nav.rememberScroll('/saved', 640);

    await router.navigateByUrl('/saved');
    expect(nav.savedScroll('/saved')).toBeNull();

    nav.direction.set('back');
    expect(nav.savedScroll('/saved')).toBe(640);
  });
});
