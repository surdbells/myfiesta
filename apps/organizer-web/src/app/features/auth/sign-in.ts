import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Api } from '../../core/api';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';

@Component({
  selector: 'app-sign-in',
  imports: [FormsModule],
  templateUrl: './sign-in.html',
  styleUrl: './sign-in.css',
})
export class SignIn {
  private readonly api = inject(Api);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  readonly email = signal('');
  readonly password = signal('');
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.busy()) return;

    this.busy.set(true);
    this.error.set(null);

    this.api.signIn(this.email().trim(), this.password()).subscribe({
      next: (session) => {
        // Belonging to no organization means there is no console to show. Said
        // here rather than after a redirect into an empty screen.
        if (session.organizations.length === 0) {
          this.busy.set(false);
          this.error.set(
            'This account is not part of an organization yet. Ask whoever invited you to add you.',
          );

          return;
        }

        this.session.start(session);

        const next = this.route.snapshot.queryParamMap.get('next');
        void this.router.navigateByUrl(next ?? '/events');
      },
      error: (response) => {
        this.busy.set(false);
        // The API answers the same way for a wrong password and an unknown
        // address; messageFor passes that through and refuses to show a 5xx
        // body, which is written for us rather than for whoever is signing in.
        this.error.set(messageFor(response, 'Those details do not match an account.'));
      },
    });
  }
}
