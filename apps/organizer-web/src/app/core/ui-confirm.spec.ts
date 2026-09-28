import { ApplicationRef, Component, PLATFORM_ID, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { afterEach, describe, expect, it } from 'vitest';
import { ConfirmDialog, UiConfirm, type ConfirmReason, type ConfirmRequest } from '@myfiesta/ui';

/**
 * The confirmation every action goes through.
 *
 * All of it is the half nobody sees working: which button a hurried Enter
 * lands on, whether Escape halfway through a refund throws the answer away,
 * whether a failed request leaves the person staring at a closed dialog and a
 * toast they missed. Each of those looks the same on screen as the version
 * that works, which is why they are pinned here.
 */

const refund: ConfirmRequest = {
  title: 'Refund this order?',
  body: 'Ada gets $40.00 back on the card she paid with.',
  consequences: ['Her two tickets stop working at the door.', 'The booking fee is not returned.'],
  confirmLabel: 'Refund $40.00',
  tone: 'danger',
};

const submit: ConfirmRequest = {
  title: 'Submit for review?',
  body: 'Our team reads it within a day. You can still edit it until then.',
  confirmLabel: 'Submit for review',
  tone: 'default',
};

async function settle(): Promise<void> {
  TestBed.tick();
  await TestBed.inject(ApplicationRef).whenStable();
}

const dialog = () => document.querySelector<HTMLDialogElement>('ui-confirm dialog');

function button(label: string): HTMLButtonElement {
  const found = [...(dialog()?.querySelectorAll<HTMLButtonElement>('button') ?? [])].find(
    (candidate) => candidate.textContent?.trim() === label,
  );

  if (!found) throw new Error(`No "${label}" button in the dialog`);

  return found;
}

function press(key: string, shiftKey = false): void {
  (document.activeElement ?? document.body).dispatchEvent(
    new KeyboardEvent('keydown', { key, shiftKey, bubbles: true, cancelable: true }),
  );
}

function type(element: HTMLInputElement | HTMLTextAreaElement, text: string): void {
  element.value = text;
  element.dispatchEvent(new Event('input', { bubbles: true }));
}

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

const idle = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
  document.querySelectorAll('ui-confirm').forEach((element) => element.remove());
  document.querySelectorAll('[data-spec-opener]').forEach((element) => element.remove());
});

describe('ConfirmDialog', () => {
  const confirmDialog = () => TestBed.inject(ConfirmDialog);

  it('asks as an alertdialog named by its question and described by what happens', async () => {
    void confirmDialog().confirm(refund);
    await settle();

    const element = dialog()!;
    expect(element.getAttribute('role')).toBe('alertdialog');
    expect(element.hasAttribute('open')).toBe(true);

    // Once, on the dialog. A second, unnamed alertdialog wrapped round it is
    // announced as an interruption with nothing to say.
    expect(document.querySelectorAll('[role="alertdialog"]')).toHaveLength(1);

    const title = document.getElementById(element.getAttribute('aria-labelledby')!);
    expect(title?.textContent?.trim()).toBe('Refund this order?');

    const described = element
      .getAttribute('aria-describedby')!
      .split(' ')
      .map((id) => document.getElementById(id)?.textContent ?? '')
      .join(' ');

    expect(described).toContain('Ada gets $40.00 back');
    expect(described).toContain('Her two tickets stop working at the door.');
    expect(described).toContain('The booking fee is not returned.');
  });

  it('puts the action on the button, not "OK"', async () => {
    void confirmDialog().confirm(refund);
    await settle();

    const labels = [...dialog()!.querySelectorAll('button')].map((b) => b.textContent?.trim());

    expect(labels).toEqual(['Cancel', 'Refund $40.00']);
  });

  it('starts on Cancel when the action destroys something', async () => {
    void confirmDialog().confirm(refund);
    await settle();

    expect(document.activeElement).toBe(button('Cancel'));
  });

  it('starts on the action when nothing is destroyed', async () => {
    void confirmDialog().confirm(submit);
    await settle();

    expect(document.activeElement).toBe(button('Submit for review'));
  });

  it('answers yes from the button that names the action, and goes', async () => {
    const answer = confirmDialog().confirm(submit);
    await settle();

    button('Submit for review').click();

    expect(await answer).toBe(true);
    expect(dialog()).toBeNull();
  });

  it('answers no to Cancel, to Escape and to the backdrop', async () => {
    const cancelled = confirmDialog().confirm(refund);
    await settle();
    button('Cancel').click();
    expect(await cancelled).toBe(false);

    const escaped = confirmDialog().confirm(refund);
    await settle();
    press('Escape');
    expect(await escaped).toBe(false);

    const clickedAway = confirmDialog().confirm(refund);
    await settle();
    // The backdrop is the dialog's own box: a click there lands on the element itself.
    dialog()!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(await clickedAway).toBe(false);

    expect(dialog()).toBeNull();
  });

  it('keeps Tab inside the dialog, both ways round', async () => {
    void confirmDialog().confirm(refund);
    await settle();

    button('Refund $40.00').focus();
    press('Tab');
    expect(document.activeElement).toBe(button('Cancel'));

    press('Tab', true);
    expect(document.activeElement).toBe(button('Refund $40.00'));
  });

  it('gives focus back to whatever asked', async () => {
    const opener = document.createElement('button');
    opener.dataset['specOpener'] = '';
    document.body.appendChild(opener);
    opener.focus();

    const answer = confirmDialog().confirm(refund);
    await settle();
    expect(document.activeElement).not.toBe(opener);

    press('Escape');
    await answer;

    expect(document.activeElement).toBe(opener);
  });

  it('will not act until the word is typed, and then takes Enter', async () => {
    const answer = watch(confirmDialog().confirm({ ...refund, requireText: 'DELETE' }));
    await settle();

    const action = button('Refund $40.00');
    const word = dialog()!.querySelector<HTMLInputElement>('input[name="confirmWord"]')!;

    expect(action.disabled).toBe(true);
    action.click();
    await idle();
    expect(answer.settled()).toBe(false);

    type(word, 'DELET');
    await settle();
    expect(action.disabled).toBe(true);

    // Case is not the test; reading it is.
    type(word, ' delete ');
    await settle();
    expect(action.disabled).toBe(false);

    word.focus();
    press('Enter');
    await idle();

    expect(answer.settled()).toBe(true);
    expect(answer.value()).toBe(true);
  });

  it('wants the reason it asks for, and hands it back trimmed', async () => {
    const why: ConfirmReason = { label: 'Why', required: true, minLength: 10, maxLength: 200 };
    const answer = confirmDialog().decide({ ...submit, reason: why });
    await settle();

    const box = dialog()!.querySelector<HTMLTextAreaElement>('textarea')!;
    const action = button('Submit for review');

    // A reason to write: focus starts in it, and it is labelled and limited.
    expect(document.activeElement).toBe(box);
    expect(document.querySelector(`label[for="${box.id}"]`)?.textContent).toContain('Why');
    expect(box.getAttribute('maxlength')).toBe('200');
    expect(dialog()!.textContent).toContain('At least 10 characters.');

    expect(action.disabled).toBe(true);

    type(box, '  too short');
    await settle();
    expect(action.disabled).toBe(true);

    type(box, '  The venue flooded on Friday.  ');
    await settle();
    expect(action.disabled).toBe(false);

    action.click();

    expect(await answer).toEqual({ confirmed: true, reason: 'The venue flooded on Friday.' });
  });

  it('lets an optional reason be left out, and says nothing of one', async () => {
    const answer = confirmDialog().decide({ ...submit, reason: { label: 'Anything to add' } });
    await settle();

    button('Submit for review').click();

    expect(await answer).toEqual({ confirmed: true });
  });

  it('holds everything still while the action runs', async () => {
    const request = new Subject<void>();
    const answer = watch(confirmDialog().confirm({ ...refund, busyLabel: 'Refunding…', run: () => request }));
    await settle();

    button('Refund $40.00').click();
    await settle();

    const action = button('Refunding…');
    expect(action.getAttribute('aria-busy')).toBe('true');
    // Busy, not disabled: a disabled button would drop the focus on it.
    expect(action.disabled).toBe(false);
    expect(button('Cancel').disabled).toBe(true);

    // Nothing closes it mid-request: not Escape, not the backdrop, not a second press.
    press('Escape');
    dialog()!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    action.click();
    await settle();

    expect(dialog()).not.toBeNull();
    expect(answer.settled()).toBe(false);

    request.next();
    request.complete();
    await idle();

    expect(answer.value()).toBe(true);
    expect(dialog()).toBeNull();
  });

  it('keeps a failure in the dialog, and lets them try again', async () => {
    let attempts = 0;
    const reasons: Array<string | undefined> = [];
    const answer = watch(
      confirmDialog().confirm({
        ...refund,
        reason: { label: 'Why', required: true },
        run: (reason) => {
          reasons.push(reason);
          attempts += 1;

          return attempts === 1 ? Promise.reject(new Error('declined')) : Promise.resolve();
        },
        failure: () => 'The card issuer declined the refund.',
      }),
    );
    await settle();

    type(dialog()!.querySelector('textarea')!, 'Asked for it');
    await settle();
    button('Refund $40.00').click();
    await idle();
    await settle();

    const alert = dialog()?.querySelector('[role="alert"]');
    expect(alert?.textContent).toContain('The card issuer declined the refund.');
    expect(answer.settled()).toBe(false);
    expect(button('Cancel').disabled).toBe(false);

    button('Refund $40.00').click();
    await idle();

    expect(answer.value()).toBe(true);
    expect(reasons).toEqual(['Asked for it', 'Asked for it']);
  });

  it('says something plain when a failure has no words of its own', async () => {
    const answer = watch(
      confirmDialog().confirm({ ...submit, run: () => Promise.reject(new Error('SQLSTATE[08006] connection refused')) }),
    );
    await settle();

    button('Submit for review').click();
    await idle();
    await settle();

    const said = dialog()?.querySelector('[role="alert"]')?.textContent ?? '';
    expect(said).toContain('That did not work. Try again.');
    expect(said).not.toContain('SQLSTATE');

    button('Cancel').click();
    await idle();
    expect(answer.value()).toBe(false);
  });

  it('answers an unanswered question no when another is asked', async () => {
    const first = confirmDialog().confirm(refund);
    const second = confirmDialog().confirm(submit);

    expect(await first).toBe(false);
    await settle();

    expect(document.querySelectorAll('ui-confirm')).toHaveLength(1);
    button('Submit for review').click();
    expect(await second).toBe(true);
  });

  it('asks nobody on the server, and so does nothing', async () => {
    TestBed.configureTestingModule({ providers: [{ provide: PLATFORM_ID, useValue: 'server' }] });

    expect(await confirmDialog().decide(refund)).toEqual({ confirmed: false });
    expect(dialog()).toBeNull();
  });
});

@Component({
  imports: [UiConfirm],
  template: `
    <ui-confirm
      heading="Delete the Early Bird tier?"
      consequence="Nobody has bought it, so nothing else changes."
      confirmLabel="Delete"
      confirmWord="Early Bird"
      destructive
      [open]="open()"
      [busy]="busy()"
      (confirmed)="confirmed.set(true)"
      (cancelled)="cancelled.set(cancelled() + 1)"
    />
  `,
})
class Placed {
  readonly open = signal(true);
  readonly busy = signal(false);
  readonly confirmed = signal(false);
  readonly cancelled = signal(0);
}

describe('UiConfirm placed in a template', () => {
  async function mount() {
    const fixture = TestBed.createComponent(Placed);
    fixture.autoDetectChanges();
    await fixture.whenStable();

    return { fixture, host: fixture.componentInstance, settle: () => fixture.whenStable() };
  }

  it('opens empty every time, not with the last word still typed', async () => {
    const ui = await mount();
    const word = () => dialog()!.querySelector<HTMLInputElement>('input[name="confirmWord"]')!;

    type(word(), 'early bird');
    await ui.settle();
    expect(button('Delete').disabled).toBe(false);

    ui.host.open.set(false);
    await ui.settle();
    ui.host.open.set(true);
    await ui.settle();

    expect(word().value).toBe('');
    expect(button('Delete').disabled).toBe(true);
  });

  it('cannot be dismissed while the action it started is running', async () => {
    const ui = await mount();

    // Pressed on the dialog itself: a control disabled under the focus can
    // leave it on the page, and the dialog still has to refuse.
    const escape = () =>
      dialog()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

    ui.host.busy.set(true);
    await ui.settle();

    escape();
    await ui.settle();

    expect(ui.host.cancelled()).toBe(0);
    expect(dialog()!.hasAttribute('open')).toBe(true);

    ui.host.busy.set(false);
    await ui.settle();

    escape();
    await ui.settle();

    expect(ui.host.cancelled()).toBe(1);
  });

  it('goes back up when the browser closes it mid-action on its own', async () => {
    const ui = await mount();

    ui.host.busy.set(true);
    await ui.settle();

    // Chrome lets a second back gesture through unasked: the element closes
    // and says so, and nothing here was consulted.
    const element = dialog()!;
    element.removeAttribute('open');
    element.dispatchEvent(new Event('close'));
    await ui.settle();

    expect(dialog()!.hasAttribute('open')).toBe(true);
    expect(ui.host.cancelled()).toBe(0);
  });
});
