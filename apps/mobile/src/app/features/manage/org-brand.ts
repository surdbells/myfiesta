import { Component, OnInit, computed, inject, signal } from '@angular/core';
import type { Brand } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { Discover } from '../../core/discovery';
import { messageOf } from '../../core/errors';
import { Dialogs, MfBadge, MfButton, MfCard, MfEmpty, MfField, MfImagePick, MfScreen, MfSkeleton, ToastStore } from '../../ui';

/**
 * How the organization appears to buyers: its name, its logo, a line about it.
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
          <mf-field label="About you" optional [limit]="1000" [count]="about().length" hint="On your page and under every event.">
            <textarea class="tall" [value]="about()" (input)="about.set($any($event.target).value)" maxlength="1000" placeholder="Lagos-born, Toronto-based. Afrobeats nights since 2016."></textarea>
          </mf-field>
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

  protected readonly brand = signal<Brand | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly name = signal('');
  protected readonly about = signal('');
  protected readonly saving = signal(false);
  protected readonly uploading = signal(false);
  protected readonly progress = signal<number | null>(null);

  protected readonly changed = computed(() => {
    const b = this.brand();
    return !!b && (this.name().trim() !== b.name || (this.about().trim() || null) !== (b.description ?? null));
  });

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
  }

  protected pageUrl(b: Brand): string {
    return `${this.discover.siteBase().replace(/^https?:\/\//, '')}/o/${b.slug}`;
  }

  protected async save(): Promise<void> {
    if (this.saving()) return;

    const name = this.name().trim();
    const renamed = name !== this.brand()?.name;
    const consequences = ['Every event page, your organizer page and the emails buyers get show it straight away.'];

    if (renamed && this.brand()?.is_verified) consequences.push('The verified tick is hidden until myFiesta has looked at the new name.');

    // How the organization appears everywhere it sells, said back first.
    const sure = await this.dialogs.confirm({
      title: renamed ? `Rename the organization to ${name}?` : 'Save the new description?',
      body: 'This is how the organization appears on the pages it sells from.',
      consequences,
      confirmLabel: 'Save changes',
      tone: 'default',
    });

    if (!sure || this.saving()) return;

    this.saving.set(true);

    try {
      const saved = await this.organizer.saveBrand({ name, description: this.about().trim() || null });
      this.adopt(saved);
      this.toasts.show(saved.verification_pending_name ? 'Saved. The new name shows once it is checked.' : 'Saved.', 'success');
      await this.session.sync();
    } catch (error) {
      this.toasts.show(messageOf(error, 'That could not be saved.'), 'danger');
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
