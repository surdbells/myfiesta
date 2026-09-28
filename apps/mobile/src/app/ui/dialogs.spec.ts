import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { afterEach, describe, expect, it } from 'vitest';
import { ApiError } from '../core/api';
import { Dialogs, MfDialogHost, type ConfirmRequest } from './dialogs';
import { SheetStack } from './sheet-stack';

/**
 * The confirmation every action on the phone goes through, as it is drawn.
 *
 * The service's promises are pinned in kit.spec.ts. This is the sheet around
 * them: which button a stray tap lands on, whether a drag or the back gesture
 * throws away a refund that is halfway through, whether a failure is said
 * where the person is looking. None of it shows on a screenshot.
 */

@Component({
  imports: [MfDialogHost],
  template: `
    <button id="opener">Refund</button>
    <mf-dialog-host />
  `,
})
class Shell {}

const refund: ConfirmRequest = {
  title: 'Refund this order?',
  body: 'Ada gets $40.00 back on the card she paid with.',
  consequences: ['Her two tickets stop working at the door.', 'The booking fee is not returned.'],
  confirmLabel: 'Refund $40.00',
  tone: 'danger',
};

const submit: ConfirmRequest = {
  title: 'Submit for review?',
  body: 'Our team reads it within a day.',
  confirmLabel: 'Submit for review',
  tone: 'default',
};

const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

/** Whether a promise has settled yet, without waiting for it to. */
function watch<T>(promise: Promise<T>): { settled: () => boolean; value: () => T | undefined } {
  let settled = false;
  let value: T | undefined;

  void promise.then((result) => {
    settled = true;
    value = result;
  });

  return { settled: () => settled, value: () => value };
}

let roots: HTMLElement[] = [];

afterEach(() => {
  roots.forEach((root) => root.remove());
  roots = [];
  document.body.style.overflow = '';
});

async function mount() {
  const fixture = TestBed.createComponent(Shell);
  fixture.autoDetectChanges();
  await fixture.whenStable();

  const root: HTMLElement = fixture.nativeElement;
  document.body.appendChild(root);
  roots.push(root);

  const settle = async () => {
    fixture.detectChanges();
    await fixture.whenStable();
  };

  return {
    root,
    dialogs: TestBed.inject(Dialogs),
    settle,

    /** Up, and focus placed — which waits for the sheet to finish rising. */
    risen: async () => {
      await settle();
      await wait(420);
      await settle();
    },

    panel: () => root.querySelector<HTMLElement>('.panel'),

    button: (label: string) => {
      const found = [...root.querySelectorAll<HTMLButtonElement>('.panel button')].find(
        (candidate) => candidate.textContent?.trim() === label,
      );

      if (!found) throw new Error(`No "${label}" button in the sheet`);

      return found;
    },

    type: async (selector: string, text: string) => {
      const control = root.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!;
      control.value = text;
      control.dispatchEvent(new Event('input', { bubbles: true }));
      await settle();

      return control;
    },

    press: (key: string, shiftKey = false) =>
      (document.activeElement ?? document.body).dispatchEvent(
        new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }),
      ),
  };
}

describe('The confirmation sheet', () => {
  it('asks as an alertdialog, described by what happens and what follows', async () => {
    const ui = await mount();

    void ui.dialogs.confirm(refund);
    await ui.risen();

    const panel = ui.panel()!;
    expect(panel.getAttribute('role')).toBe('alertdialog');
    expect(panel.getAttribute('aria-label')).toBe('Refund this order?');

    const described = panel
      .getAttribute('aria-describedby')!
      .split(' ')
      .map((id) => document.getElementById(id)?.textContent ?? '')
      .join(' ');

    expect(described).toContain('Ada gets $40.00 back');
    expect(described).toContain('Her two tickets stop working at the door.');
    expect(described).toContain('The booking fee is not returned.');

    // The button says what it does.
    expect(ui.button('Refund $40.00')).toBeTruthy();
  });

  it('leaves a menu a plain dialog', async () => {
    const ui = await mount();

    void ui.dialogs.menu({ actions: [{ key: 'edit', label: 'Edit' }] });
    await ui.settle();

    expect(ui.panel()!.getAttribute('role')).toBe('dialog');
  });

  it('starts on Cancel when the action destroys something', async () => {
    const ui = await mount();

    void ui.dialogs.confirm(refund);
    await ui.risen();

    expect(document.activeElement).toBe(ui.button('Cancel'));
  });

  it('starts on the action when nothing is destroyed', async () => {
    const ui = await mount();

    void ui.dialogs.confirm(submit);
    await ui.risen();

    expect(document.activeElement).toBe(ui.button('Submit for review'));
  });

  it('answers no to Cancel, Escape, the scrim and back', async () => {
    const ui = await mount();
    const stack = TestBed.inject(SheetStack);

    const cancelled = ui.dialogs.confirm(refund);
    await ui.risen();
    ui.button('Cancel').click();
    expect(await cancelled).toBe(false);

    const escaped = ui.dialogs.confirm(refund);
    await ui.risen();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(await escaped).toBe(false);

    const tappedAway = ui.dialogs.confirm(refund);
    await ui.risen();
    ui.root.querySelector<HTMLElement>('.scrim')!.click();
    expect(await tappedAway).toBe(false);

    const backedOut = ui.dialogs.confirm(refund);
    await ui.risen();
    expect(stack.dismissTop()).toBe(true);
    expect(await backedOut).toBe(false);
  });

  it('gives focus back to whatever asked', async () => {
    const ui = await mount();
    const opener = ui.root.querySelector<HTMLButtonElement>('#opener')!;
    opener.focus();

    const answer = ui.dialogs.confirm(refund);
    await ui.risen();
    expect(document.activeElement).not.toBe(opener);

    ui.button('Cancel').click();
    await answer;
    await ui.settle();

    expect(document.activeElement).toBe(opener);
  });

  it('keeps Tab inside the sheet', async () => {
    const ui = await mount();

    void ui.dialogs.confirm(refund);
    await ui.risen();

    ui.button('Refund $40.00').focus();
    ui.press('Tab');
    expect(document.activeElement).toBe(ui.button('Cancel'));

    ui.press('Tab', true);
    expect(document.activeElement).toBe(ui.button('Refund $40.00'));
  });

  it('will not act until the word is typed, and then takes Enter', async () => {
    const ui = await mount();
    const answer = watch(ui.dialogs.confirm({ ...refund, requireText: 'DELETE' }));
    await ui.risen();

    expect(ui.button('Refund $40.00').disabled).toBe(true);

    await ui.type('input[name="confirmWord"]', 'DELET');
    expect(ui.button('Refund $40.00').disabled).toBe(true);

    const word = await ui.type('input[name="confirmWord"]', ' delete ');
    expect(ui.button('Refund $40.00').disabled).toBe(false);

    word.focus();
    ui.press('Enter');
    await wait(0);

    expect(answer.value()).toBe(true);
  });

  it('wants the reason it asks for, and hands it back trimmed', async () => {
    const ui = await mount();
    const answer = ui.dialogs.decide({ ...submit, reason: { label: 'Why', required: true, minLength: 10, maxLength: 200 } });
    await ui.risen();

    // A reason to write: that is where focus starts.
    const box = ui.root.querySelector<HTMLTextAreaElement>('textarea[name="reason"]')!;
    expect(document.activeElement).toBe(box);
    expect(box.getAttribute('maxlength')).toBe('200');
    expect(ui.panel()!.textContent).toContain('At least 10 characters.');

    expect(ui.button('Submit for review').disabled).toBe(true);

    await ui.type('textarea[name="reason"]', 'too short');
    expect(ui.button('Submit for review').disabled).toBe(true);

    await ui.type('textarea[name="reason"]', '  The venue flooded on Friday.  ');
    ui.button('Submit for review').click();

    expect(await answer).toEqual({ confirmed: true, reason: 'The venue flooded on Friday.' });
  });

  it('holds still while the action runs — no drag, scrim, Escape or back', async () => {
    const ui = await mount();
    const stack = TestBed.inject(SheetStack);
    const request = new Subject<void>();
    const answer = watch(ui.dialogs.confirm({ ...refund, busyLabel: 'Refunding…', run: () => request }));
    await ui.risen();

    ui.button('Refund $40.00').click();
    await ui.settle();

    expect(ui.dialogs.busy()).toBe(true);
    expect(ui.root.querySelector('.panel button[aria-busy="true"]')?.textContent).toContain('Refunding…');
    expect(ui.button('Cancel').disabled).toBe(true);

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    ui.root.querySelector<HTMLElement>('.scrim')!.click();
    // Back swallows the press and the sheet stays where back will find it next time.
    expect(stack.dismissTop()).toBe(true);
    expect(stack.depth().length).toBe(1);
    ui.dialogs.settle(null);
    await ui.settle();

    expect(ui.panel()).not.toBeNull();
    expect(answer.settled()).toBe(false);

    request.next();
    request.complete();
    await wait(0);
    await ui.settle();

    expect(answer.value()).toBe(true);
    expect(ui.dialogs.pending()).toBeNull();
  });

  it('keeps a failure in the sheet in the API’s words, and lets them try again', async () => {
    const ui = await mount();
    let attempts = 0;
    const answer = watch(
      ui.dialogs.confirm({
        ...refund,
        run: () => {
          attempts += 1;

          return attempts === 1 ? Promise.reject(new ApiError('The card issuer declined the refund.', 422)) : Promise.resolve();
        },
      }),
    );
    await ui.risen();

    ui.button('Refund $40.00').click();
    await wait(0);
    await ui.settle();

    expect(ui.root.querySelector('[role="alert"]')?.textContent).toContain('The card issuer declined the refund.');
    expect(answer.settled()).toBe(false);
    expect(ui.button('Cancel').disabled).toBe(false);
    // Back on the button that failed, so trying again is one press.
    expect(document.activeElement).toBe(ui.button('Refund $40.00'));

    ui.button('Refund $40.00').click();
    await wait(0);

    expect(answer.value()).toBe(true);
  });

  it('says something plain when the failure is not the API speaking', async () => {
    const ui = await mount();
    const answer = watch(ui.dialogs.confirm({ ...submit, run: () => Promise.reject(new Error('SQLSTATE[08006]')) }));
    await ui.risen();

    ui.button('Submit for review').click();
    await wait(0);
    await ui.settle();

    const said = ui.root.querySelector('[role="alert"]')?.textContent ?? '';
    expect(said).toContain('That did not work. Try again.');
    expect(said).not.toContain('SQLSTATE');

    ui.button('Cancel').click();
    await wait(0);
    expect(answer.value()).toBe(false);
  });

  it('lets a question asked mid-action wait its turn rather than cancel it', async () => {
    const ui = await mount();
    const request = new Subject<void>();
    const first = watch(ui.dialogs.confirm({ ...refund, run: () => request }));
    await ui.risen();

    ui.button('Refund $40.00').click();
    await ui.settle();

    const second = watch(ui.dialogs.confirm(submit));
    await wait(0);
    expect(first.settled()).toBe(false);
    expect(second.settled()).toBe(false);

    request.next();
    request.complete();
    await wait(0);
    await ui.settle();

    expect(first.value()).toBe(true);
    expect(ui.panel()!.getAttribute('aria-label')).toBe('Submit for review?');
  });

  it('still reads the first spelling, danger and all', async () => {
    const ui = await mount();
    const answer = ui.dialogs.confirm({ title: 'Remove the logo?', message: 'The page shows the name instead.', confirm: 'Remove', danger: true });
    await ui.risen();

    expect(ui.panel()!.getAttribute('role')).toBe('alertdialog');
    expect(ui.panel()!.textContent).toContain('The page shows the name instead.');
    expect(ui.button('Remove').classList.contains('danger')).toBe(true);
    expect(document.activeElement).toBe(ui.button('Cancel'));

    ui.button('Remove').click();
    expect(await answer).toBe(true);
  });
});
