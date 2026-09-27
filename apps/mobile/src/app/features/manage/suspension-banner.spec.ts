import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import type { OrganizationStanding } from '@myfiesta/api-types';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { ManageShell } from './manage-shell';
import { MfSuspensionBanner } from './suspension-banner';

const LAGOS = { id: 'org-1', name: 'Lagos Nights', role: 'owner', permissions: [] };
const TORONTO = { id: 'org-2', name: 'Toronto Sound', role: 'owner', permissions: [] };

function suspended(id = 'org-1', reason: string | null = null, support: string | null = 'help@myfiesta.test'): OrganizationStanding {
  return {
    organization: { id, name: id === 'org-1' ? 'Lagos Nights' : 'Toronto Sound' },
    suspended: true,
    suspension: { since: '2026-09-20T10:00:00+00:00', reason, support_email: support },
  };
}

function active(id = 'org-1'): OrganizationStanding {
  return { organization: { id, name: 'Lagos Nights' }, suspended: false, suspension: null };
}

/**
 * The console's suspension banner, on the phone's organizer screens.
 *
 * The same promises: before anything else, that tickets already sold still
 * get people in; the reason only when myFiesta shared it; always how to
 * reach support; and never one organization's state while another is on
 * screen.
 */
describe('the suspension strip', () => {
  let current: ReturnType<typeof signal<typeof LAGOS | null>>;
  let answers: (() => Promise<OrganizationStanding>)[];
  let standing: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    current = signal<typeof LAGOS | null>(LAGOS);
    answers = [];
    standing = vi.fn(() => (answers.shift() ?? (() => Promise.reject(new Error('not asked for'))))());

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: Organizer, useValue: { standing } },
        { provide: SessionStore, useValue: { organization: current, canSeeSales: () => true } },
      ],
    });
  });

  const text = (fixture: { nativeElement: HTMLElement }) => (fixture.nativeElement.textContent ?? '').replace(/\s+/g, ' ');

  async function settle(fixture: { detectChanges(): void; whenStable(): Promise<unknown> }) {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function render(...answer: (OrganizationStanding | Error)[]) {
    answers.push(...answer.map((a) => () => (a instanceof Error ? Promise.reject(a) : Promise.resolve(a))));

    const fixture = TestBed.createComponent(MfSuspensionBanner);
    await settle(fixture);

    return fixture;
  }

  function unfold(fixture: { nativeElement: HTMLElement; detectChanges(): void }) {
    fixture.nativeElement.querySelector<HTMLButtonElement>('button')!.click();
    fixture.detectChanges();
  }

  it('draws nothing for an organization in good standing', async () => {
    const strip = await render(active());

    expect(standing).toHaveBeenCalledTimes(1);
    expect((strip.nativeElement as HTMLElement).querySelector('.strip')).toBeNull();
  });

  it('says first that nothing new sells and that ticket holders still get in', async () => {
    const strip = await render(suspended());
    const said = text(strip);

    expect(said).toContain('Lagos Nights is suspended');
    expect(said).toContain('everybody with a ticket still gets in');
  });

  it('unfolds into the console’s whole message, with how to reach support', async () => {
    const strip = await render(suspended());
    unfold(strip);
    const said = text(strip);

    expect(said).toContain('payouts are paused');
    expect(said).toContain('your door still lets them in');
    expect(said).toContain('refunds can still be made');
    expect((strip.nativeElement as HTMLElement).querySelector('a')?.getAttribute('href')).toBe('mailto:help@myfiesta.test');

    // Not shared, so not shown.
    expect((strip.nativeElement as HTMLElement).querySelector('.reason')).toBeNull();
  });

  it('shows the reason only when myFiesta shared it', async () => {
    const strip = await render(suspended('org-1', 'Your events used artwork you do not own.'));
    unfold(strip);

    expect(text(strip)).toContain('Why: Your events used artwork you do not own.');
  });

  it('still says how to ask when the deployment has no support address', async () => {
    const strip = await render(suspended('org-1', null, null));
    unfold(strip);

    expect(text(strip)).toContain('reply to the email we sent your owners');
    expect((strip.nativeElement as HTMLElement).querySelector('a')).toBeNull();
  });

  it('asks again for the organization switched to, and never shows the last one’s', async () => {
    const strip = await render(suspended('org-1'));
    expect(text(strip)).toContain('Lagos Nights is suspended');

    answers.push(() => Promise.resolve(active('org-2')));
    current.set(TORONTO);
    await settle(strip);

    expect(standing).toHaveBeenCalledTimes(2);
    expect(text(strip)).not.toContain('is suspended');
  });

  it('claims nothing about an organization it could not check', async () => {
    const strip = await render(suspended('org-1'));

    answers.push(() => Promise.reject(new Error('No connection.')));
    current.set(TORONTO);
    await settle(strip);

    // The answer on hand is about Lagos, and Toronto could not be read.
    expect(text(strip)).not.toContain('is suspended');
  });

  it('tells the shell to make room for it while it is drawn', async () => {
    answers.push(() => Promise.resolve(suspended()));

    const shell = TestBed.createComponent(ManageShell);
    await settle(shell);

    expect((shell.nativeElement as HTMLElement).querySelector('.shell')?.classList.contains('noticed')).toBe(true);
  });
});
