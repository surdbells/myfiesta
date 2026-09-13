import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  ToastStore,
  UiBadge,
  UiButton,
  UiConfirm,
  UiEmpty,
  UiErrorState,
  UiPageHeader,
  UiSelect,
  UiSkeleton,
  type SelectOption,
} from '@myfiesta/ui';
import { Api } from '../../core/api';
import { TeamMember, TeamPage } from '../../core/api.types';
import { messageFor } from '../../core/errors';

/**
 * Who works on this organization, and what each of them can do.
 *
 * Before this, roles existed and nobody could be given one: the door, finance
 * and whoever runs socials shared the owner's password, payouts screen and
 * all. Each role is described where it is chosen, because "finance" means
 * different things at different companies and the choice is about who sees
 * the money.
 */
@Component({
  selector: 'app-team',
  imports: [FormsModule, UiPageHeader, UiButton, UiBadge, UiSelect, UiConfirm, UiEmpty, UiErrorState, UiSkeleton],
  templateUrl: './team.html',
})
export class Team {
  private readonly api = inject(Api);
  private readonly toasts = inject(ToastStore);

  readonly page = signal<TeamPage | null>(null);

  /**
   * A role chosen in a dropdown, until the server has answered.
   *
   * The dropdown shows this rather than the member's role directly. Bound to
   * the role alone, a refused change left it showing the refused choice: the
   * reload handed it the same role as before, which it did not see as a
   * change. Clearing the draft moves the bound value back, which it does.
   */
  private readonly roleDrafts = signal<Record<string, string>>({});

  displayRole(member: TeamMember): string {
    return this.roleDrafts()[member.id] ?? member.role;
  }

  private clearDraft(memberId: string): void {
    const { [memberId]: _, ...rest } = this.roleDrafts();
    this.roleDrafts.set(rest);
  }
  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly refused = signal(false);

  readonly inviteEmail = signal('');
  readonly inviteRole = signal('manager');
  readonly inviting = signal(false);
  readonly inviteError = signal<string | null>(null);

  readonly removing = signal<TeamMember | null>(null);
  readonly removingBusy = signal(false);

  readonly roleOptions = computed<SelectOption[]>(() =>
    (this.page()?.roles ?? []).map((r) => ({ value: r.value, label: r.label, hint: `Can ${r.description}` })),
  );

  readonly chosenRole = computed(() => this.page()?.roles.find((r) => r.value === this.inviteRole()) ?? null);

  constructor() {
    this.load();
  }

  load(): void {
    this.api.team().subscribe({
      next: (page) => {
        this.page.set(page);
        this.loading.set(false);
        this.failed.set(false);
      },
      error: (response) => {
        this.loading.set(false);
        response?.status === 403 ? this.refused.set(true) : this.failed.set(true);
      },
    });
  }

  invite(): void {
    const email = this.inviteEmail().trim();
    if (!email || this.inviting()) return;

    this.inviting.set(true);
    this.inviteError.set(null);

    this.api.inviteMember(email, this.inviteRole()).subscribe({
      next: ({ message }) => {
        this.inviting.set(false);
        this.inviteEmail.set('');
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.inviting.set(false);
        this.inviteError.set(messageFor(response, 'That invitation could not be sent.'));
      },
    });
  }

  changeRole(member: TeamMember, role: string | null): void {
    if (!role || role === this.displayRole(member)) return;

    this.roleDrafts.set({ ...this.roleDrafts(), [member.id]: role });

    this.api.updateMemberRole(member.id, role).subscribe({
      next: ({ message }) => {
        this.toasts.show(message, 'success');
        this.load();
        this.clearDraft(member.id);
      },
      error: (response) => {
        this.toasts.show(messageFor(response, 'That role could not be changed.'), 'danger');
        // Back to what the server still holds.
        this.clearDraft(member.id);
      },
    });
  }

  confirmRemove(): void {
    const member = this.removing();
    if (!member) return;

    this.removingBusy.set(true);

    this.api.removeMember(member.id).subscribe({
      next: ({ message }) => {
        this.removingBusy.set(false);
        this.removing.set(null);
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => {
        this.removingBusy.set(false);
        this.removing.set(null);
        this.toasts.show(messageFor(response, 'That person could not be removed.'), 'danger');
      },
    });
  }

  revoke(invitationId: string): void {
    this.api.revokeInvitation(invitationId).subscribe({
      next: ({ message }) => {
        this.toasts.show(message, 'success');
        this.load();
      },
      error: (response) => this.toasts.show(messageFor(response, 'That invitation could not be withdrawn.'), 'danger'),
    });
  }

  roleLabel(value: string): string {
    return this.page()?.roles.find((r) => r.value === value)?.label ?? value;
  }

  expires(iso: string): string {
    return new Intl.DateTimeFormat('en-CA', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(iso));
  }
}
