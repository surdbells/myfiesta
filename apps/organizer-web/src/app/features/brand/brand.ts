import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ToastStore, UiAlert, UiButton, UiField, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { Brand as BrandData } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

/**
 * How the organization appears on the pages it sells from.
 *
 * Until now these fields could only be set by the legacy importer, so every
 * organizer who signed up after the migration published events under a blank
 * card — an initial in a circle and nothing else — with no way to fill it in.
 *
 * The web address is shown and not editable. It is in links organizers have
 * already handed out, and tidying up a display name should not quietly break
 * them; the API refuses a change to it for the same reason.
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
  readonly session = inject(SessionStore);

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

  /** What is left of the description's room, once it is worth mentioning. */
  readonly remaining = computed(() => 600 - this.description().length);

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

  save(): void {
    if (!this.changed() || this.saving()) return;

    const name = this.name().trim();

    if (name.length < 2) {
      this.nameError.set('An organization needs a name people can recognise.');

      return;
    }

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

  removeLogo(): void {
    if (this.uploading()) return;

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
