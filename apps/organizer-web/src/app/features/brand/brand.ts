import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HttpErrorResponse } from '@angular/common/http';
import { ConfirmDialog, ToastStore, UiAlert, UiButton, UiField, UiPageHeader } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { Brand as BrandData, BrandSocials, SocialNetwork } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { SITE_URL } from '../../core/site-url';
import { BrandApi, SOCIAL_FIELDS } from './brand-api';

/** Nothing in any box. */
const NO_SOCIALS: BrandSocials = { instagram: null, tiktok: null, x: null, facebook: null, website: null };

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
 * Where else to find them (Instagram, TikTok, X, Facebook, a website) is a
 * form of its own with its own Save, so tidying a link never carries a
 * half-typed rename along with it. Each box takes whatever an organizer has
 * to hand: the username, @username, or the address copied from the browser.
 * What comes back is what was kept, so the box shows exactly what the page
 * will link to.
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
  private readonly brandApi = inject(BrandApi);

  readonly brand = signal<BrandData | null>(null);
  readonly loading = signal(true);
  readonly failed = signal<string | null>(null);

  readonly name = signal('');
  readonly description = signal('');
  readonly saving = signal(false);
  readonly nameError = signal<string | null>(null);

  protected readonly socialFields = SOCIAL_FIELDS;
  readonly socials = signal<BrandSocials>({ ...NO_SOCIALS });
  readonly savingSocials = signal(false);
  /** The API's word on each box it refused, by network. */
  readonly socialErrors = signal<Partial<Record<SocialNetwork, string>>>({});
  readonly socialsError = signal<string | null>(null);

  /** The boxes that differ from what is kept, as they will be sent. */
  readonly socialChanges = computed(() => {
    const kept = this.brand()?.socials ?? NO_SOCIALS;
    const changes: Partial<BrandSocials> = {};

    for (const { network } of SOCIAL_FIELDS) {
      const typed = (this.socials()[network] ?? '').trim();

      if (typed !== (kept[network] ?? '')) changes[network] = typed || null;
    }

    return changes;
  });

  readonly socialsChanged = computed(() => Object.keys(this.socialChanges()).length > 0);

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

  setSocial(network: SocialNetwork, value: string): void {
    this.socials.update((socials) => ({ ...socials, [network]: value }));
    this.socialErrors.update((errors) => {
      const rest = { ...errors };
      delete rest[network];

      return rest;
    });
  }

  async saveSocials(): Promise<void> {
    const changes = this.socialChanges();
    if (!this.socialsChanged() || this.savingSocials()) return;

    const changed = SOCIAL_FIELDS.filter(({ network }) => network in changes);
    const removed = changed.filter(({ network }) => changes[network] === null);
    const linked = changed.length > removed.length;
    const consequences: string[] = [];

    // Only promised when something is being linked: an owner taking a link
    // off should not be told the page links to it.
    if (linked) {
      consequences.push('Your organizer page links to them straight away, for anybody to follow.');
    }

    if (removed.length > 0) {
      const names = removed.map((field) => field.label).join(' and ');
      consequences.push(`${names} ${removed.length === 1 ? 'comes' : 'come'} off your page.`);
    }

    const sure = await this.confirmDialog.confirm({
      title: 'Save where else to find you?',
      body: linked
        ? 'These are shown on your organizer page, under your name.'
        : 'Your organizer page stops linking there. You can add a link back at any time.',
      consequences,
      confirmLabel: 'Save links',
      tone: 'default',
    });

    if (!sure || this.savingSocials()) return;

    this.savingSocials.set(true);
    this.socialsError.set(null);
    this.socialErrors.set({});

    this.brandApi.saveSocials(changes).subscribe({
      next: (brand) => {
        this.brand.set(brand);
        this.socials.set({ ...NO_SOCIALS, ...brand.socials });
        this.savingSocials.set(false);
        this.toasts.show('Saved. Your organizer page links to these now.', 'success');
      },
      error: (response: HttpErrorResponse) => {
        this.savingSocials.set(false);

        // A refused box says so under itself; anything else, above Save.
        const errors = (response.error?.errors ?? {}) as Record<string, string[] | undefined>;
        const byNetwork: Partial<Record<SocialNetwork, string>> = {};

        for (const { network } of SOCIAL_FIELDS) {
          const message = errors[`socials.${network}`]?.[0];
          if (message) byNetwork[network] = message;
        }

        this.socialErrors.set(byNetwork);

        if (Object.keys(byNetwork).length === 0) {
          this.socialsError.set(messageFor(response, 'Those could not be saved.'));
        }
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
    // Saving the name or the mark answers with the whole brand. Links typed
    // and not yet saved stay in their boxes rather than being reset under
    // somebody's hands. Asked before the new brand replaces the old.
    const typedLinks = this.socialsChanged();

    this.brand.set(brand);
    this.name.set(brand.name);
    this.description.set(brand.description ?? '');

    if (!typedLinks) this.socials.set({ ...NO_SOCIALS, ...brand.socials });
  }
}
