import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ConfirmDialog, ToastStore, UiAlert, UiButton, UiField, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { Brand as BrandData } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';

/**
 * How the organization appears on the pages it sells from.
 *
 * Until now these fields could only be set by the legacy importer, so every
 * organizer who signed up after the migration published events under a blank
 * card — an initial in a circle and nothing else — with no way to fill it in.
 *
 * The web address is shown and not editable. It is in links organizers have
 * already handed out, and tidying up a display name should not quietly break
 * them; the API refuses a change to it for the same reason. It is now the
 * address of a real page — everything they have on and everything they have
 * run — so it is shown whole and linked rather than left as a slug in a box.
 *
 * Anybody on the team can open this screen. Only an owner can change anything
 * on it, and the controls say so rather than failing on submit.
 */
@Component({
  selector: 'app-brand',
  imports: [FormsModule, UiPageHeader, UiField, UiButton, UiAlert],
  templateUrl: './brand.html',
})
export class Brand {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);
  private readonly confirmDialog = inject(ConfirmDialog);
  readonly session = inject(SessionStore);
  private readonly siteUrl = inject(SITE_URL);

  readonly brand = signal<BrandData | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly name = signal('');
  readonly description = signal('');
  readonly saving = signal(false);
  readonly nameError = signal<string | null>(null);

  readonly uploading = signal(false);
  readonly logoError = signal<string | null>(null);

  readonly mayEdit = this.session.canManageBrand;

  readonly changed = computed(() => {
    const brand = this.brand();
    if (!brand) return false;

    return (
      this.name().trim() !== brand.name ||
      this.description().trim() !== (brand.description ?? '')
    );
  });

  /**
   * Whether the name in the box differs from the saved one.
   *
   * Used to warn a verified organizer before they save, rather than after
   * their tick has quietly gone.
   */
  readonly nameChanged = computed(() => {
    const brand = this.brand();

    return !!brand && this.name().trim() !== brand.name;
  });

  /** What is left of the description's room, once it is worth mentioning. */
  readonly remaining = computed(() => 600 - this.description().length);

  /** Their page on the public site — the link they hand out. */
  readonly pageUrl = computed(() => `${this.siteUrl}/o/${this.brand()?.slug ?? ''}`);

  readonly initial = computed(() => (this.brand()?.name.trim().charAt(0) ?? '?').toUpperCase());

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(null);

    this.api.brand().subscribe({
      next: (brand) => {
        this.adopt(brand);
        this.loading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.failed.set(messageFor(response, 'That could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  async save(): Promise<void> {
    if (!this.changed() || this.saving()) return;

    const name = this.name().trim();

    if (name.length < 2) {
      this.nameError.set('An organization needs a name people can recognise.');

      return;
    }

    const consequences = ['Every event page, your organizer page and the emails buyers get show it straight away.'];

    if (this.nameChanged() && this.brand()?.is_verified) {
      consequences.push('The verified tick is hidden until myFiesta has looked at the new name.');
    }

    const sure = await this.confirmDialog.confirm({
      title: this.nameChanged() ? `Rename the organization to ${name}?` : 'Save the new description?',
      body: 'This is how the organization appears on the pages it sells from.',
      consequences,
      confirmLabel: 'Save changes',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);
    this.nameError.set(null);

    this.api
      .saveBrand({ name, description: this.description().trim() || null })
      .subscribe({
        next: (brand) => {
          this.adopt(brand);
          this.saving.set(false);
          this.toasts.show('Saved. Your event pages show this now.', 'success');
        },
        error: (response: HttpErrorResponse) => {
          this.saving.set(false);
          this.nameError.set(messageFor(response, 'That could not be saved.'));
        },
      });
  }

  chooseLogo(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    // The same input is used again for the next attempt, and a browser fires
    // no change event when the same file is picked twice.
    input.value = '';

    if (!file) return;

    void this.useLogo(file);
  }

  private async useLogo(file: File): Promise<void> {
    const sure = await this.confirmDialog.confirm({
      title: `Use ${file.name} as your mark?`,
      body: this.brand()?.logo_url
        ? 'It replaces the mark you have now, beside your name on every event page and your organizer page.'
        : 'It appears beside your name on every event page and your organizer page.',
      confirmLabel: 'Use this picture',
      tone: 'default',
    });

    if (!sure || this.uploading()) return;

    this.uploading.set(true);
    this.logoError.set(null);

    this.api.uploadLogo(file).subscribe({
      next: (brand) => {
        this.adopt(brand);
        this.uploading.set(false);
        this.toasts.show('That is your mark now.', 'success');
      },
      error: (response: HttpErrorResponse) => {
        this.uploading.set(false);
        this.logoError.set(messageFor(response, 'That picture could not be used.'));
      },
    });
  }

  async removeLogo(): Promise<void> {
    if (this.uploading()) return;

    const sure = await this.confirmDialog.confirm({
      title: 'Remove your mark?',
      body: 'Your pages show the first letter of your name in a circle instead, until you add another.',
      confirmLabel: 'Remove the mark',
      tone: 'danger',
    });

    if (!sure || this.uploading()) return;

    this.uploading.set(true);
    this.logoError.set(null);

    this.api.removeLogo().subscribe({
      next: (brand) => {
        this.adopt(brand);
        this.uploading.set(false);
      },
      error: (response: HttpErrorResponse) => {
        this.uploading.set(false);
        this.logoError.set(messageFor(response, 'That could not be removed.'));
      },
    });
  }

  private adopt(brand: BrandData): void {
    this.brand.set(brand);
    this.name.set(brand.name);
    this.description.set(brand.description ?? '');
  }
}
