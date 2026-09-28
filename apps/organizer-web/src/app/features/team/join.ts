import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { UiAlert, UiButton } from '@myfiesta/ui';
import { Api } from '../../core/api';
import { InvitationDetails } from '../../core/api.types';
import { messageFor } from '../../core/errors';
import { SessionStore } from '../../core/session';
import { AuthStage } from '../../shared/auth-stage';

/**
 * Where an invitation email lands.
 *
 * Three people arrive here: somebody already signed in with the invited
 * address (one button), somebody with an account who is not signed in (sign
 * in, then back here), and somebody new (create an account for that address,
 * which joins them in the same step).
 */
@Component({
  selector: 'app-join',
  imports: [AuthStage, RouterLink, UiButton, UiAlert],
  templateUrl: './join.html',
})
export class Join {
  private readonly api = inject(Api);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  readonly session = inject(SessionStore);

  readonly token = this.route.snapshot.paramMap.get('token')!;

  readonly invitation = signal<InvitationDetails | null>(null);
  readonly notFound = signal(false);
  readonly accepting = signal(false);
  readonly error = signal<string | null>(null);

  /** Signed in as the address the invitation went to. */
  readonly signedInAsInvitee = computed(() => {
    const invitation = this.invitation();
    const user = this.session.user();

    return !!invitation && !!user && user.email.toLowerCase() === invitation.email.toLowerCase();
  });

  readonly returnHere = computed(() => `/join/${this.token}`);

  constructor() {
    this.api.invitation(this.token).subscribe({
      next: (invitation) => this.invitation.set(invitation),
      error: () => this.notFound.set(true),
    });
  }

  accept(): void {
    if (this.accepting()) return;

    this.accepting.set(true);
    this.error.set(null);

    this.api.acceptInvitation(this.token).subscribe({
      next: (session) => {
        // The new session carries the organizer ability and the new membership.
        this.session.start(session);
        this.session.select(session.joined);
        void this.router.navigateByUrl('/');
      },
      error: (response) => {
        this.accepting.set(false);
        this.error.set(messageFor(response, 'That invitation could not be accepted.'));
      },
    });
  }

  signOutAndSwitch(): void {
    this.session.clear();
    void this.router.navigate(['/sign-in'], { queryParams: { next: this.returnHere(), email: this.invitation()?.email } });
  }
}
