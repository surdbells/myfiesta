import { Component, ElementRef, computed, inject, signal, viewChild } from '@angular/core';
import { SessionStore } from '../../core/session';
import { fieldErrors, messageOf } from '../../core/errors';
import { Dialogs, MfAvatar, MfButton, ToastStore } from '../../ui';
import { ProfileApi } from './profile-api';

/** The server takes up to 12 MB; said here before the upload, rather than after it. */
const MAX_BYTES = 12 * 1024 * 1024;

/**
 * What the server can read, and nothing else. HEIC is left out on purpose:
 * an iPhone converts a library photo to JPEG for a picker that does not ask
 * for HEIC, and hands over the HEIC original to one that does — which the
 * server cannot decode.
 */
export const AVATAR_ACCEPT = 'image/jpeg,image/png,image/webp';

/** Said for a refused file, in place of the validator's own sentence. */
export const AVATAR_REFUSED = "That photo can't be used. Choose a JPEG, PNG or WebP photo.";

/**
 * The account's photo at the top of "Signed in as", with Change photo and
 * Remove photo. It is the face on the home screen's greeting too.
 *
 * Seen by nobody else: not organizers, not the door. Choosing one goes
 * through the phone's own picker — a plain file input, which offers the
 * camera, the library and Files with the platform's own permission prompts —
 * and the server squares it and strips where it was taken.
 *
 * Not for a door pass, which has no account of its own to put a face on.
 */
@Component({
  selector: 'mf-settings-avatar',
  imports: [MfAvatar, MfButton],
  template: `
    @if (shown()) {
      <div class="photo">
        <div class="face">
          <mf-avatar [name]="name()" [src]="src()" [size]="64" />
          @if (uploading()) {
            <span
              class="progress"
              role="progressbar"
              aria-label="Uploading your photo"
              aria-valuemin="0"
              aria-valuemax="100"
              [attr.aria-valuenow]="progress()"
            >
              {{ progress() === null ? '' : progress() + '%' }}
            </span>
          }
        </div>

        <div class="actions">
          <button mfButton size="sm" variant="secondary" [loading]="uploading()" [label]="uploadingLabel()" (click)="choose()">
            {{ src() ? 'Change photo' : 'Add a photo' }}
          </button>
          @if (src() && !uploading()) {
            <button mfButton size="sm" variant="ghost" class="remove" (click)="remove()">Remove photo</button>
          }
        </div>

        <input #file class="file" type="file" [accept]="accept" (change)="picked($event)" />
      </div>
    }
  `,
  styles: `
    :host {
      display: contents;
    }

    .photo {
      display: flex;
      align-items: center;
      gap: var(--space-4);
      margin-bottom: var(--space-4);
      min-width: 0;
    }

    .face {
      position: relative;
      display: grid;
      flex: none;
    }

    .progress {
      position: absolute;
      inset: 0;
      display: grid;
      place-items: center;
      border-radius: var(--radius-full);
      background: rgb(4 8 5 / 0.55);
      color: var(--color-neutral-0);
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      font-variant-numeric: tabular-nums;
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      min-width: 0;
    }

    .remove {
      color: var(--danger-text);
    }

    .file {
      display: none;
    }
  `,
})
export class MfSettingsAvatar {
  private readonly session = inject(SessionStore);
  private readonly profile = inject(ProfileApi);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  private readonly file = viewChild<ElementRef<HTMLInputElement>>('file');

  protected readonly accept = AVATAR_ACCEPT;

  protected readonly shown = computed(() => this.session.signedIn() && !this.session.locked());
  protected readonly name = computed(() => this.session.session()?.name ?? '');
  protected readonly src = computed(() => this.session.session()?.avatarUrl ?? null);

  readonly uploading = signal(false);
  /** 0–100 while it goes, null while the phone cannot tell. */
  readonly progress = signal<number | null>(null);

  protected readonly uploadingLabel = computed(() => {
    const percent = this.progress();

    return percent === null ? 'Uploading…' : `Uploading… ${percent}%`;
  });

  choose(): void {
    this.file()?.nativeElement.click();
  }

  protected picked(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // Cleared, so choosing the same photo again still counts as a choice.
    input.value = '';

    if (file) void this.upload(file);
  }

  /** Send the chosen photo, and show it once the server has kept it. */
  async upload(file: File): Promise<void> {
    if (this.uploading()) return;

    if (file.size > MAX_BYTES) {
      this.toasts.show(`That photo is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is 12 MB.`, 'danger');

      return;
    }

    this.uploading.set(true);
    this.progress.set(0);

    try {
      const { avatar_url } = await this.profile.uploadAvatar(file, (percent) => this.progress.set(percent));
      await this.session.adoptAccount({ avatar_url });
      this.toasts.show('Photo saved.', 'success');
    } catch (error) {
      // A refusal of the file itself comes back in the validator's words
      // ("must be a file of type: jpeg, jpg…"); the server's own sentences,
      // like an image it could not read, are already written for a person.
      const refused = fieldErrors(error)['file'] !== undefined;

      this.toasts.show(refused ? AVATAR_REFUSED : messageOf(error, 'That photo could not be uploaded. Try again.'), 'danger');
    } finally {
      this.uploading.set(false);
      this.progress.set(null);
    }
  }

  /** Asks first: the photo is deleted, not hidden, and initials take its place. */
  async remove(): Promise<void> {
    const removed = await this.dialogs.confirm({
      title: 'Remove your photo?',
      body: 'It is deleted, and your initials take its place here and on the home screen until you add another.',
      confirmLabel: 'Remove photo',
      busyLabel: 'Removing…',
      tone: 'danger',
      run: async () => {
        const { avatar_url } = await this.profile.removeAvatar();
        await this.session.adoptAccount({ avatar_url });
      },
    });

    if (removed) this.toasts.show('Photo removed.', 'success');
  }
}
