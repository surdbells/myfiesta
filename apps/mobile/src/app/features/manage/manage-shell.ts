import { Component, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { MfSuspensionBanner } from './suspension-banner';

/**
 * Around every organizer screen: what is true of the whole organization,
 * above whichever screen is open.
 *
 * Today that is one thing, the suspension strip. It sits at the very top,
 * under the status bar, and the screen takes the rest of the height, so each
 * screen's own bar, scrolling and footer work exactly as they do anywhere
 * else in the app. Mounted once for /manage, so moving between organizer
 * screens keeps it — and whether it was unfolded — rather than asking again.
 */
@Component({
  selector: 'mf-manage-shell',
  imports: [RouterOutlet, MfSuspensionBanner],
  template: `
    <div class="shell" [class.noticed]="noticed()">
      <mf-suspension-banner (showing)="noticed.set($event)" />
      <div class="screens">
        <router-outlet />
      </div>
    </div>
  `,
  styles: `
    :host {
      display: block;
      height: 100%;
    }

    .shell {
      display: grid;
      grid-template-rows: auto minmax(0, 1fr);
      height: 100%;
    }

    /*
     * The outlet inserts each screen as its own sibling, so screens are sized
     * from here. Through ::ng-deep because a routed screen's element is not
     * part of this template and never carries its style scope: a plain
     * selector here would match nothing and leave each screen to size itself.
     */
    .screens {
      position: relative;
      min-height: 0;
      overflow: hidden;
    }

    .screens ::ng-deep > :not(router-outlet) {
      position: absolute;
      inset: 0;
      display: block;
    }

    /*
     * With the strip above it, a screen no longer reaches the status bar,
     * so its bar does not pad for one. Sheets are fixed to the whole screen
     * and still do, or a tall one would slide up under the clock.
     */
    .noticed .screens {
      --mf-safe-top: 0px;
    }

    .noticed .screens ::ng-deep mf-sheet {
      --mf-safe-top: env(safe-area-inset-top, 0px);
    }
  `,
})
export class ManageShell {
  protected readonly noticed = signal(false);
}
