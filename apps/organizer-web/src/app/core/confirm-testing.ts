import { TestBed } from '@angular/core/testing';

/*
 * For specs: the confirmation dialog, read and answered the way a person
 * would — by what it says and the button they press.
 *
 * Every action in the console asks first (ConfirmDialog in @myfiesta/ui), so
 * a screen's spec has to get past the question to reach the request, and the
 * question itself is worth pinning: that it names the action, and that
 * saying no sends nothing.
 */

/**
 * Draw what has been asked, and let the answer reach the screen that asked.
 *
 * The dialog lives on the page, outside any fixture. Not whenStable(): a
 * request the spec has yet to answer keeps the application unstable, and
 * the spec would wait on itself.
 */
export async function settle(): Promise<void> {
  for (let round = 0; round < 6; round++) {
    await Promise.resolve();
    TestBed.tick();
  }
}

/** The dialog on screen, if one is. */
export function dialog(): HTMLDialogElement | null {
  return document.querySelector<HTMLDialogElement>('ui-confirm dialog');
}

/** What the open dialog says — its question, what happens, and its buttons — or null. */
export function asked(): { title: string; text: string; buttons: string[] } | null {
  const element = dialog();

  if (!element) return null;

  const title = document.getElementById(element.getAttribute('aria-labelledby') ?? '')?.textContent?.trim() ?? '';

  return {
    title,
    text: (element.textContent ?? '').replace(/\s+/g, ' ').trim(),
    buttons: [...element.querySelectorAll('button')]
      .map((button) => button.textContent?.replace(/\s+/g, ' ').trim() ?? '')
      .filter((label) => label !== ''),
  };
}

/** Press the button that says this, and let what follows happen. */
export async function answer(label: string): Promise<void> {
  const element = dialog();
  const button = [...(element?.querySelectorAll<HTMLButtonElement>('button') ?? [])].find(
    (candidate) => candidate.textContent?.replace(/\s+/g, ' ').trim() === label,
  );

  if (!button) throw new Error(`No "${label}" button in the dialog. It says: ${asked()?.buttons.join(', ') ?? 'nothing is open'}`);

  button.click();
  await settle();
}

/** Write in the dialog's box: a reason, or the word it asks to be typed. */
export async function write(text: string, name: 'reason' | 'confirmWord' = 'reason'): Promise<void> {
  const field = dialog()?.querySelector<HTMLInputElement | HTMLTextAreaElement>(`[name="${name}"]`);

  if (!field) throw new Error(`The dialog has no ${name} box`);

  field.value = text;
  field.dispatchEvent(new Event('input', { bubbles: true }));
  await settle();
}

/** jsdom's <dialog> has no showModal() or close(); the question only needs them not to throw. */
export function allowDialogs(): void {
  const dialog = HTMLDialogElement.prototype as unknown as Record<string, unknown>;

  dialog['showModal'] ??= function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
  };
  dialog['close'] ??= function (this: HTMLDialogElement) {
    this.removeAttribute('open');
  };
}

/** Whatever a spec left open, gone before the next one. */
export function forgetDialogs(): void {
  document.querySelectorAll('ui-confirm').forEach((element) => element.remove());
}
