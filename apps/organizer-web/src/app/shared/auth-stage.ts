import { Component } from '@angular/core';

/**
 * Where somebody signs in, signs up or gets back in: the form, beside a panel
 * that says what they are signing in to.
 *
 * On a wide screen the left half is the product in a sentence and three
 * facts, on the brand's own wash, so the first screen of the console looks
 * like the product rather than like a login prompt anyone could have put up.
 * On a phone the panel goes and the form has the screen, as before — there it
 * is the form somebody came for, and a banner above it pushes the password
 * field under the keyboard.
 */
@Component({
  selector: 'app-auth-stage',
  template: `
    <main class="stage ambient">
      <aside class="stage__story" aria-hidden="true">
        <div class="stage__brand">
          <img src="/brand/mark-64.png" alt="" width="36" height="36" />
          <span>myfiesta</span>
        </div>

        <div class="stage__pitch">
          <p class="stage__eyebrow">Organizer console</p>
          <p class="stage__headline">Sell the night. See it fill. Open the doors.</p>
          <ul class="stage__facts">
            <li><strong>Tickets on sale in minutes</strong> — codes, presales and your own website included.</li>
            <li><strong>Every figure live</strong> — what sold, where buyers came from, how the night is pacing.</li>
            <li><strong>A door that works offline</strong> — scan from any phone, even with no signal.</li>
          </ul>
        </div>

        <p class="stage__fine">Toronto · Lagos · everywhere in between</p>
      </aside>

      <div class="stage__form">
        <ng-content />
      </div>
    </main>
  `,
  styles: `
    .stage {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 34rem);
      min-height: 100dvh;
    }
    .stage__story {
      position: relative;
      isolation: isolate;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: var(--space-8);
      padding: var(--space-8) var(--space-9);
      color: var(--text-inverse);
      background:
        radial-gradient(70% 60% at 15% 10%, color-mix(in srgb, var(--accent) 40%, transparent), transparent 70%),
        radial-gradient(80% 70% at 90% 90%, color-mix(in srgb, var(--color-brand-400) 55%, transparent), transparent 70%),
        var(--color-brand-900);
    }
    /* A faint grid over the wash: texture, not decoration anyone reads. */
    .stage__story::after {
      content: '';
      position: absolute;
      inset: 0;
      z-index: -1;
      background-image:
        linear-gradient(color-mix(in srgb, white 6%, transparent) 1px, transparent 1px),
        linear-gradient(90deg, color-mix(in srgb, white 6%, transparent) 1px, transparent 1px);
      background-size: 48px 48px;
      mask-image: radial-gradient(70% 70% at 50% 40%, black, transparent);
    }
    .stage__brand {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      font-family: var(--font-family-display);
      font-size: var(--font-size-xl);
      font-weight: var(--font-weight-semibold);
      color: var(--color-neutral-0);
    }
    .stage__pitch { display: grid; gap: var(--space-5); max-width: 32rem; }
    .stage__eyebrow {
      font-size: var(--font-size-xs);
      font-weight: var(--font-weight-semibold);
      letter-spacing: var(--font-tracking-wide);
      text-transform: uppercase;
      color: var(--color-gold-300);
    }
    .stage__headline {
      font-family: var(--font-family-display);
      font-size: var(--font-size-4xl);
      font-weight: var(--font-weight-semibold);
      line-height: var(--font-leading-tight);
      letter-spacing: var(--font-tracking-tighter);
      color: var(--color-neutral-0);
      text-wrap: balance;
    }
    .stage__facts {
      display: grid;
      gap: var(--space-3);
      margin: 0;
      padding: 0;
      list-style: none;
      font-size: var(--font-size-sm);
      color: color-mix(in srgb, var(--color-neutral-0) 78%, transparent);
    }
    .stage__facts strong { color: var(--color-neutral-0); font-weight: var(--font-weight-semibold); }
    .stage__fine { font-size: var(--font-size-xs); color: color-mix(in srgb, var(--color-neutral-0) 60%, transparent); }

    .stage__form {
      display: grid;
      place-items: center;
      padding: var(--space-6);
    }

    @media (max-width: 960px) {
      .stage { grid-template-columns: minmax(0, 1fr); }
      .stage__story { display: none; }
    }
    @media (max-width: 420px) {
      .stage__form { align-items: start; padding: 0; }
    }
  `,
})
export class AuthStage {}
