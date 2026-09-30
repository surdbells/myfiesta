import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { INTRO_PATH } from '../../core/intro';
import { SessionStore } from '../../core/session';
import { SignIn } from './sign-in';

@Component({ template: '' })
class Introduction {}

/**
 * Signing in, for somebody without an account.
 *
 * Most people here buy without one, and the You tab brings them to this
 * screen rather than to Settings, which needs an account. So this is where
 * they find the introduction again once they have skipped it — the only
 * place they can.
 */
describe('Signing in, signed out', () => {
  it('opens the introduction again', async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'sign-in', component: SignIn },
          { path: INTRO_PATH.slice(1), component: Introduction },
        ]),
        { provide: Api, useValue: {} },
        { provide: SessionStore, useValue: { signedIn: () => false } },
      ],
    });

    const harness = await RouterTestingHarness.create('/sign-in');
    const link = [...(harness.routeNativeElement as HTMLElement).querySelectorAll('button')].find(
      (button) => button.textContent?.trim() === 'What is myFiesta?',
    );

    expect(link).toBeDefined();
    link!.click();

    await vi.waitFor(() => expect(TestBed.inject(Router).url).toBe(INTRO_PATH));
  });
});
