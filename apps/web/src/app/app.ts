import { Component, DestroyRef, PLATFORM_ID, afterNextRender, inject, signal } from '@angular/core';
import { DOCUMENT, isPlatformBrowser } from '@angular/common';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
// A file at a time, never through @myfiesta/ui: whatever the shell imports is
// in every page's first download, and the index names the whole kit — the
// dropdown and all of Angular's forms with it (packages/ui/package.json).
import { UiIcon } from '@myfiesta/ui/icon';
import { UiThemeToggle } from '@myfiesta/ui/theme';
import { Menu, X } from 'lucide-angular';
import { CONSOLE_URL } from './core/console-url';
import { EmbedMode } from './core/embed';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, UiIcon, UiThemeToggle],
  templateUrl: './app.html',
})
export class App {
  /** The console, for the links that turn a visitor into an organizer. */
  readonly consoleUrl = inject(CONSOLE_URL);

  /** Inside an organizer's own site, or on ours. */
  readonly embed = inject(EmbedMode);

  protected readonly menuIcon = Menu;
  protected readonly closeIcon = X;

  /**
   * The menu on a phone.
   *
   * Below 700px the header has room for the mark and one action, and the
   * navigation used to simply disappear — leaving somebody at a door with no
   * way to "My tickets" from the header on the device they were most likely
   * holding. Closed on every navigation, so choosing a page is one tap.
   */
  readonly menuOpen = signal(false);

  constructor() {
    const subscription = inject(Router).events.subscribe((event) => {
      if (event instanceof NavigationEnd) this.menuOpen.set(false);
    });

    const destroyRef = inject(DestroyRef);
    destroyRef.onDestroy(() => subscription.unsubscribe());

    /*
     * Framed, the page reports its own height so the frame can fit it.
     *
     * A frame with a fixed height either cuts the basket off or leaves a
     * gap under it on somebody's venue site, and the content changes height
     * as tickets are chosen and questions appear. Only the browser has a
     * height to report.
     */
    const document = inject(DOCUMENT);

    if (isPlatformBrowser(inject(PLATFORM_ID))) {
      afterNextRender(() => {
        let last = 0;
        const observer = new ResizeObserver(() => {
          const height = Math.ceil(document.documentElement.scrollHeight);
          if (height === last) return;
          last = height;
          this.embed.tell({ type: 'resize', height });
        });

        observer.observe(document.body);
        destroyRef.onDestroy(() => observer.disconnect());
      });
    }
  }
}
