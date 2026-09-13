import { Component, DestroyRef, inject, signal } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { UiIcon } from '@myfiesta/ui';
import { Menu, X } from 'lucide-angular';
import { CONSOLE_URL } from './core/console-url';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive, UiIcon],
  templateUrl: './app.html',
})
export class App {
  /** The console, for the links that turn a visitor into an organizer. */
  readonly consoleUrl = inject(CONSOLE_URL);

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

    inject(DestroyRef).onDestroy(() => subscription.unsubscribe());
  }
}
