import { Component, OnInit, computed, inject, signal } from '@angular/core';
import type { Brand, BrandSocials, SocialNetwork } from '@myfiesta/api-types';
import { ApiError } from '../../core/api';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Discover } from '../../core/discovery';
import { messageOf } from '../../core/errors';
import { Dialogs, MfBadge, MfButton, MfCard, MfEmpty, MfField, MfImagePick, MfScreen, MfSkeleton, ToastStore } from '../../ui';
import { BrandChanges, OrgBrandApi, SOCIAL_FIELDS } from './org-brand-api';

/** Nothing in any box. */
const NO_SOCIALS: BrandSocials = { instagram: null, tiktok: null, x: null, facebook: null, website: null };

/**
 * How the organization appears to buyers: its name, its logo, a line about it,
 * and where else to find it.
 *
 * Each of Instagram, TikTok, X, Facebook and a website takes whatever is to
 * hand — the username, @username, or the address copied from the browser —
 * and comes back as what was kept, which is exactly what the organizer page
 * links to. A box the server refuses says why under itself.
 *
 * A verified organization renaming itself keeps the old name on show until
 * the new one is checked — that is the point of the mark — so the screen says
 * so rather than leaving somebody wondering why nothing changed.
 */
@Component({
  selector: 'mf-org-brand',
  imports: [MfScreen, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfField, MfImagePick],
  template: `
    <mf-screen title="How you appear" back backTo="/manage" task>
      @if (error(); as message) {
        <mf-empty title="Could not load this" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (brand(); as b) {
        <div class="logo">
          <mf-image-pick
            ratio="1 / 1"
            label="Add a logo"
            hint="Square, up to 8 MB"
            removable
            [src]="b.logo_url"
            [busy]="uploading()"
            [progress]="progress()"
            (chosen)="upload($event)"
            (removed)="removeLogo()"
          />
        </div>

        <div class="form">
          <p class="where">
            Your page: <span class="link">{{ pageUrl(b) }}</span>
            @if (b.is_verified) {
              <mf-badge tone="success">Verified</mf-badge>
            }
          </p>
          @if (b.verification_pending_name) {
            <p class="note">Your new name is being checked. Buyers see the old one until it is.</p>
          }
          <mf-field label="Name" [limit]="120" [count]="name().length">
            <input [value]="name()" (input)="name.set($any($event.target).value)" maxlength="120" />
          </mf-field>
          <mf-field label="About you" optional [limit]="600" [count]="about().length" hint="On your page and under every event.">
            <textarea class="tall" [value]="about()" (input)="about.set($any($event.target).value)" maxlength="600" placeholder="Lagos-born, Toronto-based. Afrobeats nights since 2016."></textarea>
          </mf-field>

          <h2 class="section">Where else to find you</h2>
          <p class="lead">Linked under your name on your organizer page. Leave a box empty to show nothing for it.</p>

          @for (field of socialFields; track field.network) {
            <mf-field [label]="field.label" optional [hint]="field.hint" [error]="socialErrors()[field.network] ?? null">
              <input
                [attr.data-network]="field.network"
                [type]="field.type"
                [value]="socials()[field.network] ?? ''"
                (input)="setSocial(field.network, $any($event.target).value)"
                maxlength="255"
                autocapitalize="off"
                autocomplete="off"
                spellcheck="false"
                [placeholder]="field.placeholder"
              />
            </mf-field>
          }
        </div>
      } @else {
        <mf-card><mf-skeleton height="12rem" /></mf-card>
      }

      <div screenFooter class="footer">
        <button mfButton block [loading]="saving()" [disabled]="!changed() || !name().trim()" (click)="save()">Save</button>
      </div>
    </mf-screen>
  `,
  styles: `
    .logo {
      width: 9rem;
      margin: 0 auto var(--space-5);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }

    .where {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: var(--space-2);
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .link {
      font-family: var(--font-family-mono);
      color: var(--text);
    }

    .note {
      padding: var(--space-3) var(--space-4);
      border-radius: var(--radius-lg);
      background: color-mix(in srgb, var(--warning) 12%, transparent);
      font-size: var(--font-size-sm);
    }

    .tall {
      min-height: 8rem;
    }

    .section {
      margin: var(--space-4) 0 0;
      font-size: var(--font-size-sm);
      font-weight: var(--font-weight-semibold);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-subtle);
    }

    .lead {
      margin: calc(var(--space-2) * -1) 0 0;
      font-size: var(--font-size-sm);
      color: var(--text-muted);
    }

    .footer {
      display: grid;
    }
  `,
})
export class OrgBrand implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly discover = inject(Discover);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);
  private readonly brandApi = inject(OrgBrandApi);

  protected readonly socialFields = SOCIAL_FIELDS;
  protected readonly socials = signal<BrandSocials>({ ...NO_SOCIALS });
  /** The server's word on each box it refused, by network. */
  protected readonly socialErrors = signal<Partial<Record<SocialNetwork, string>>>({});

  /** The boxes that differ from what is kept, as they will be sent. */
  protected readonly socialChanges = computed(() => {
    const kept = this.brand()?.socials ?? NO_SOCIALS;
    const changes: Partial<BrandSocials> = {};

    for (const { network } of SOCIAL_FIELDS) {
      const typed = (this.socials()[network] ?? '').trim();

      if (typed !== (kept[network] ?? '')) changes[network] = typed || null;
    }

    return changes;
  });

  protected readonly brand = signal<Brand | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly name = signal('');
  protected readonly about = signal('');
  protected readonly saving = signal(false);
  protected readonly uploading = signal(false);
  protected readonly progress = signal<number | null>(null);

  /** The name or the line about them differs from what is kept. */
  protected readonly detailsChanged = computed(() => {
    const b = this.brand();
    return !!b && (this.name().trim() !== b.name || (this.about().trim() || null) !== (b.description ?? null));
  });

  protected readonly changed = computed(() => this.detailsChanged() || Object.keys(this.socialChanges()).length > 0);

  ngOnInit(): void {
    void this.load();
  }

  async load(): Promise<void> {
    this.error.set(null);

    try {
      this.adopt(await this.organizer.brand());
    } catch (error) {
      this.error.set(messageOf(error));
    }
  }

  private adopt(brand: Brand): void {
    this.brand.set(brand);
    this.name.set(brand.name);
    this.about.set(brand.description ?? '');
    this.socials.set({ ...NO_SOCIALS, ...brand.socials });
    this.socialErrors.set({});
  }

  protected setSocial(network: SocialNetwork, value: string): void {
    this.socials.update((socials) => ({ ...socials, [network]: value }));
    this.socialErrors.update((errors) => {
      const rest = { ...errors };
      delete rest[network];

      return rest;
    });
  }

  protected pageUrl(b: Brand): string {
    return `${this.discover.siteBase().replace(/^https?:\/\//, '')}/o/${b.slug}`;
  }

  protected async save(): Promise<void> {
    if (this.saving()) return;

    const name = this.name().trim();
    const renamed = name !== this.brand()?.name;
    const details = this.detailsChanged();
    const socials = this.socialChanges();
    const links = Object.keys(socials).length > 0;
    const consequences: string[] = [];

    if (details) consequences.push('Every event page, your organizer page and the emails buyers get show it straight away.');
    if (renamed && this.brand()?.is_verified) consequences.push('The verified tick is hidden until myFiesta has looked at the new name.');

    if (links) {
      const changed = SOCIAL_FIELDS.filter(({ network }) => network in socials);
      const removed = changed.filter(({ network }) => socials[network] === null).map((f) => f.label);

      // Only promised when something is being linked: taking a link off
      // should not be described as linking to it.
      if (changed.length > removed.length) {
        consequences.push('Your organizer page links to where else to find you straight away, for anybody to follow.');
      }
      if (removed.length > 0) consequences.push(`${removed.join(' and ')} ${removed.length === 1 ? 'comes' : 'come'} off your page.`);
    }

    // How the organization appears everywhere it sells, said back first.
    const sure = await this.dialogs.confirm({
      title: renamed
        ? `Rename the organization to ${name}?`
        : details && links
          ? 'Save your changes?'
          : details
            ? 'Save the new description?'
            : 'Save where else to find you?',
      body: 'This is how the organization appears on the pages it sells from.',
      consequences,
      confirmLabel: 'Save changes',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);

    const changes: BrandChanges = {
      ...(details ? { name, description: this.about().trim() || null } : {}),
      ...(links ? { socials } : {}),
    };

    try {
      const saved = await this.brandApi.save(changes);
      this.adopt(saved);
      this.toasts.show(saved.verification_pending_name ? 'Saved. The new name shows once it is checked.' : 'Saved.', 'success');
      if (details) await this.session.sync();
    } catch (error) {
      // A refused link says so under its own box.
      const fields = error instanceof ApiError ? (error.fields ?? {}) : {};
      const byNetwork: Partial<Record<SocialNetwork, string>> = {};

      for (const { network } of SOCIAL_FIELDS) {
        const message = fields[`socials.${network}`]?.[0];
        if (message) byNetwork[network] = message;
      }

      this.socialErrors.set(byNetwork);
      this.toasts.show(
        Object.keys(byNetwork).length > 0 ? 'Check the links marked below.' : messageOf(error, 'That could not be saved.'),
        'danger',
      );
    } finally {
      this.saving.set(false);
    }
  }

  protected async upload(file: File): Promise<void> {
    if (file.size > 8 * 1024 * 1024) {
      this.toasts.show(`That picture is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is 8 MB.`, 'danger');
      return;
    }

    const sure = await this.dialogs.confirm({
      title: `Use ${file.name} as your logo?`,
      body: this.brand()?.logo_url
        ? 'It replaces the logo you have now, beside your name on every event page and your organizer page.'
        : 'It appears beside your name on every event page and your organizer page.',
      confirmLabel: 'Use this picture',
      tone: 'default',
    });

    if (!sure || this.uploading()) return;

    this.uploading.set(true);
    this.progress.set(0);

    try {
      this.brand.set(await this.organizer.uploadLogo(file, (p) => this.progress.set(p)));
      this.toasts.show('Logo up.', 'success');
    } catch (error) {
      this.toasts.show(messageOf(error, 'That logo could not be uploaded.'), 'danger');
    } finally {
      this.uploading.set(false);
      this.progress.set(null);
    }
  }

  protected async removeLogo(): Promise<void> {
    const sure = await this.dialogs.confirm({
      title: 'Remove your logo?',
      body: 'Your pages show the first letter of your name instead, until you add another.',
      confirmLabel: 'Remove the logo',
      tone: 'danger',
    });

    if (!sure) return;

    try {
      this.brand.set(await this.organizer.removeLogo());
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
