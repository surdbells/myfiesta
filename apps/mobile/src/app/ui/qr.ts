import { Component, effect, inject, input, signal } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import QRCode from 'qrcode';

/**
 * The code a door reads.
 *
 * Drawn on the phone rather than fetched, because the one moment a ticket has
 * to work is in a queue outside a venue where the signal has gone. The ticket
 * code arrives with the ticket; everything after that is local.
 *
 * Always on white, whatever the theme. A QR inverted for dark mode is one most
 * scanners refuse, and "turn your brightness up" is not a thing to say to
 * somebody at the front of a queue.
 */
@Component({
  selector: 'mf-qr',
  template: `
    <div class="frame" role="img" [attr.aria-label]="'Ticket ' + code()">
      @if (symbol(); as svg) {
        <div class="symbol" [style.width.px]="size()" [style.height.px]="size()" [innerHTML]="svg"></div>
      } @else {
        <div class="symbol" [style.width.px]="size()" [style.height.px]="size()"></div>
      }
    </div>
  `,
  styles: `
    .frame {
      display: inline-grid;
      place-items: center;
      padding: var(--space-3);
      border-radius: var(--radius-lg);
      /* The white ground is part of the symbol, not decoration. */
      background: #fff;
    }

    .symbol ::ng-deep svg {
      display: block;
      width: 100%;
      height: 100%;
    }
  `,
})
export class MfQr {
  private readonly sanitizer = inject(DomSanitizer);

  readonly code = input.required<string>();
  readonly size = input(220);

  protected readonly symbol = signal<SafeHtml | null>(null);

  constructor() {
    effect(() => {
      const code = this.code();

      void QRCode.toString(code, {
        type: 'svg',
        margin: 0,
        // M: about 15% of the symbol can be obscured and it still reads. A
        // ticket is a thing on a cracked screen behind a fingerprint.
        errorCorrectionLevel: 'M',
        color: { dark: '#000000', light: '#ffffff' },
      }).then((svg) => {
        // Generated here from a code this app holds; nothing from a response
        // body is rendered as markup.
        this.symbol.set(this.sanitizer.bypassSecurityTrustHtml(svg));
      });
    });
  }
}
