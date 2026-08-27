import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { CONSOLE_URL } from './core/console-url';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  templateUrl: './app.html',
})
export class App {
  /** The console, for the links that turn a visitor into an organizer. */
  readonly consoleUrl = inject(CONSOLE_URL);
}
