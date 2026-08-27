import { DOCUMENT } from '@angular/common';
import { UiButton } from '@myfiesta/ui';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { eventIdFrom } from '../../core/event-id';
import { Api } from '../../core/api';
import { OrganizerEventDetail, PromoCode } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { formatMoney, toMinorUnits } from '../../core/money';
import { SessionStore } from '../../core/session';

/**
 * Discount and promoter codes.
 *
 * One screen for both, because that is how they are actually used: in
 * nightlife a promoter's discount code *is* their attribution, and asking an
 * organizer to keep two separate lists in step would mean neither is right.
 *
 * The API and its tests have existed since Phase 4 with nothing able to reach
 * them. Reporting the feature as done on green tests was wrong — a code an
 * organizer cannot create does not exist.
 *
 * Numbers go in as an organizer says them and travel as integers. A percentage
 * is typed as 20 and sent as 2000 basis points; a fixed amount is typed as
 * 5.00 and sent as 500 minor units. Neither conversion belongs anywhere but
 * here, at the edge.
 */
@Component({
  selector: 'app-event-codes',
  imports: [FormsModule, UiButton],
  templateUrl: './event-codes.html',
})
export class EventCodes {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  readonly session = inject(SessionStore);

  private readonly document = inject(DOCUMENT);

  readonly eventId = eventIdFrom(this.route);

  readonly event = signal<OrganizerEventDetail | null>(null);
  readonly codes = signal<PromoCode[]>([]);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly error = signal<string | null>(null);
  readonly notice = signal<string | null>(null);
  readonly copied = signal<string | null>(null);

  readonly form = signal({
    code: '',
    label: '',
    kind: 'discount' as 'discount' | 'promoter' | 'both',
    discount_type: 'percentage' as 'percentage' | 'fixed',
    discount_value: '',
    promoter_name: '',
    ref_slug: '',
    max_redemptions: '',
    max_per_customer: '',
  });

  readonly discounts = computed(() => this.form().kind !== 'promoter');
  readonly attributes = computed(() => this.form().kind !== 'discount');

  readonly currency = computed(() => this.event()?.currency ?? 'CAD');

  constructor() {
    this.api.event(this.eventId).subscribe({
      next: (event) => this.event.set(event),
      error: () => undefined,
    });

    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.codes(this.eventId).subscribe({
      next: ({ data }) => {
        this.codes.set(data);
        this.loading.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        this.error.set(messageFor(response, 'Could not load the codes for this event.'));
      },
    });
  }

  /** "20% off", "CA$5.00 off", or nothing when the code only attributes. */
  describeDiscount(code: PromoCode): string {
    if (code.discount_type === 'percentage' && code.discount_value !== null) {
      // Basis points back to a percentage, trimmed so 2000 reads as 20% and
      // 1250 still reads as 12.5%.
      return `${Number((code.discount_value / 100).toFixed(2))}% off`;
    }

    if (code.discount_type === 'fixed' && code.discount_value !== null) {
      return `${formatMoney({
        amount: code.discount_value,
        currency: code.discount_currency ?? this.currency(),
      })} off`;
    }

    return 'Tracking only';
  }

  /** What a promoter is actually given: the event link carrying their slug. */
  linkFor(code: PromoCode): string | null {
    const slug = this.event()?.slug;

    if (!code.ref_slug || !slug) return null;

    // An event lives at the root — myfiesta.ca/{slug} — not under /events.
    // Getting this wrong hands every promoter a link that 404s, and they find
    // out from the people who followed it.
    return `${this.publicOrigin()}/${slug}?ref=${encodeURIComponent(code.ref_slug)}`;
  }

  copy(code: PromoCode): void {
    const link = this.linkFor(code);

    if (!link) return;

    void navigator.clipboard.writeText(link).then(
      () => this.copied.set(code.id),
      // Clipboard access can be refused, and silently doing nothing would look
      // like a broken button.
      () => this.error.set('Your browser would not let us copy. Select the link instead.'),
    );
  }

  submit(): void {
    if (this.saving()) return;

    const form = this.form();

    this.saving.set(true);
    this.error.set(null);
    this.notice.set(null);

    this.api
      .createCode(this.eventId, {
        code: form.code.trim().toUpperCase(),
        label: form.label.trim() || null,
        discount_type: this.discounts() ? form.discount_type : null,
        discount_value: this.discounts() ? this.discountValue() : null,
        promoter_name: this.attributes() ? form.promoter_name.trim() || null : null,
        ref_slug: this.attributes() ? this.refSlug() : null,
        max_redemptions: form.max_redemptions ? Number(form.max_redemptions) : null,
        max_per_customer: form.max_per_customer ? Number(form.max_per_customer) : null,
        event_scoped: true,
      })
      .subscribe({
        next: () => {
          this.saving.set(false);
          this.notice.set('Code created.');
          this.form.set({
            ...form,
            code: '',
            label: '',
            discount_value: '',
            promoter_name: '',
            ref_slug: '',
            max_redemptions: '',
            max_per_customer: '',
          });
          this.load();
        },
        error: (response) => {
          this.saving.set(false);
          this.error.set(messageFor(response, 'That code could not be created.'));
        },
      });
  }

  turnOff(code: PromoCode): void {
    this.error.set(null);
    this.notice.set(null);

    this.api.deactivateCode(this.eventId, code.id).subscribe({
      next: (result) => {
        this.notice.set(result.message);
        this.load();
      },
      error: (response) => {
        this.error.set(messageFor(response, 'That code could not be turned off.'));
      },
    });
  }

  /**
   * A percentage in basis points, a fixed amount in minor units.
   *
   * Both integers. A rate that multiplies money must never be a float, and an
   * amount of money must never be one either.
   */
  private discountValue(): number | null {
    const { discount_type, discount_value } = this.form();

    if (!discount_value) return null;

    return discount_type === 'percentage'
      ? Math.round(Number(discount_value) * 100)
      : toMinorUnits(discount_value);
  }

  /** Defaults to the code itself, which is what a promoter would expect. */
  private refSlug(): string | null {
    const { ref_slug, code } = this.form();

    return (ref_slug.trim() || code.trim()).toLowerCase() || null;
  }

  private publicOrigin(): string {
    // The console and the public site are separate deployments, so the origin
    // cannot be inferred from this one. Named in a meta tag for the same reason
    // the API base is: a built bundle should not carry a hostname.
    const meta = this.document.querySelector<HTMLMetaElement>('meta[name="public-base"]');

    return meta?.content || 'https://myfiesta.ca';
  }
}
