import { Component, inject } from '@angular/core';
import { Router, RouterOutlet } from '@angular/router';
import { Api } from './core/api';
import { SessionStore } from './core/session';

@Component({
  selector: 'app-root',
  imports: [RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly api = inject(Api);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  signOut(): void {
    // The local session is cleared either way. A network failure must not
    // leave somebody stuck signed in on a shared machine.
    this.api.signOut().subscribe({
      next: () => this.finishSignOut(),
      error: () => this.finishSignOut(),
    });
  }

  private finishSignOut(): void {
    this.session.clear();
    void this.router.navigate(['/sign-in']);
  }
}
