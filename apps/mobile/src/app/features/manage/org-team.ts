import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { UserPlus } from 'lucide-angular';
import type { TeamMember, TeamPage } from '@myfiesta/api-types';
import { Organizer } from '../../core/organizer';
import { SessionStore } from '../../core/session';
import { messageOf } from '../../core/errors';
import { until } from '../../core/when';
import {
  Dialogs,
  MfAvatar,
  MfBadge,
  MfButton,
  MfCard,
  MfChoices,
  MfEmpty,
  MfField,
  MfIconButton,
  MfScreen,
  MfSheet,
  MfSkeleton,
  ToastStore,
  type MfChoice,
} from '../../ui';

type Invitation = TeamPage['invitations'][number];

/**
 * Who is on the team, and what each of them can do.
 *
 * Roles are chosen by what they let somebody do, said beside each one, so an
 * owner handing the door to a friend does not also hand them the payouts.
 */
@Component({
  selector: 'mf-org-team',
  imports: [MfScreen, MfIconButton, MfCard, MfBadge, MfButton, MfEmpty, MfSkeleton, MfSheet, MfField, MfChoices, MfAvatar],
  template: `
    <mf-screen title="Team" back backTo="/manage" refreshable [busy]="loading()" (refresh)="load()">
      <button mfIconButton screenActions tone="tonal" [icon]="inviteIcon" label="Invite somebody" (click)="startInvite()"></button>

      @if (error(); as message) {
        <mf-empty title="Could not load the team" [hint]="message">
          <button mfButton variant="secondary" (click)="load()">Try again</button>
        </mf-empty>
      } @else if (page(); as p) {
        <ul class="people">
          @for (m of p.members; track m.id) {
            <li>
              <button type="button" class="person" [disabled]="m.is_you" (click)="memberMenu(m)">
                <mf-avatar [name]="m.name" [size]="40" />
                <span class="who">
                  <span class="name">{{ m.name }}@if (m.is_you) { <span class="you">(you)</span> }</span>
                  <span class="sub">{{ m.email }}</span>
                </span>
                <mf-badge [tone]="m.role === 'owner' ? 'success' : 'neutral'">{{ roleLabel(m.role) }}</mf-badge>
              </button>
            </li>
          }
        </ul>

        @if (p.invitations.length > 0) {
          <h2 class="heading">Invited</h2>
          <ul class="people">
            @for (i of p.invitations; track i.id) {
              <li>
                <button type="button" class="person" (click)="invitationMenu(i)">
                  <mf-avatar [name]="i.email" [size]="40" />
                  <span class="who">
                    <span class="name">{{ i.email }}</span>
                    <span class="sub">{{ roleLabel(i.role) }} · {{ i.expired ? 'Invitation ran out' : 'Runs out ' + until(i.expires_at) }}</span>
                  </span>
                  <mf-badge [tone]="i.expired ? 'danger' : 'warning'">{{ i.expired ? 'Expired' : 'Waiting' }}</mf-badge>
                </button>
              </li>
            }
          </ul>
        }
      } @else {
        <mf-card><mf-skeleton height="6rem" /></mf-card>
      }
    </mf-screen>

    <mf-sheet [open]="inviting()" heading="Invite somebody" subheading="They get an email with a link to join." closable (closed)="inviting.set(false)">
      <div class="form">
        <mf-field label="Their email" [error]="inviteError()">
          <input type="email" inputmode="email" autocapitalize="off" autocomplete="off" [value]="email()" (input)="email.set($any($event.target).value)" placeholder="tolu@example.com" />
        </mf-field>
        <mf-choices legend="What they can do" [options]="roleChoices()" [value]="role()" (valueChange)="role.set($event)" />
      </div>
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="inviting.set(false)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="!inviteReady()" (click)="invite()">Send invitation</button>
      </ng-container>
    </mf-sheet>

    <mf-sheet [open]="!!changing()" [heading]="changing()?.name ?? ''" subheading="What they can do" closable (closed)="changing.set(null)">
      <mf-choices [options]="roleChoices()" [value]="newRole()" (valueChange)="newRole.set($event)" />
      <ng-container sheetFooter>
        <button mfButton variant="secondary" (click)="changing.set(null)">Cancel</button>
        <button mfButton [loading]="busy()" [disabled]="newRole() === changing()?.role" (click)="changeRole()">Save</button>
      </ng-container>
    </mf-sheet>
  `,
  styles: `
    .people {
      display: grid;
      margin: 0 0 var(--space-6);
      padding: 0;
      list-style: none;
      border-radius: var(--radius-xl);
      background: var(--surface-raised);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    .people li + li {
      border-top: 1px solid var(--border-subtle);
    }

    .person {
      display: flex;
      align-items: center;
      gap: var(--space-3);
      width: 100%;
      min-height: var(--mf-tap);
      padding: var(--space-3) var(--space-4);
      border: 0;
      background: transparent;
      color: inherit;
      font: inherit;
      text-align: left;
    }

    .person:not(:disabled):active {
      background: var(--surface-hover);
    }

    .who {
      flex: 1;
      display: grid;
      min-width: 0;
    }

    .name {
      font-weight: var(--font-weight-semibold);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .you {
      font-weight: var(--font-weight-regular);
      color: var(--text-subtle);
    }

    .sub {
      font-size: var(--font-size-sm);
      color: var(--text-muted);
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .heading {
      margin-bottom: var(--space-3);
      font-size: var(--font-size-lg);
    }

    .form {
      display: grid;
      gap: var(--space-4);
    }
  `,
})
export class OrgTeam implements OnInit {
  private readonly organizer = inject(Organizer);
  private readonly session = inject(SessionStore);
  private readonly dialogs = inject(Dialogs);
  private readonly toasts = inject(ToastStore);

  protected readonly page = signal<TeamPage | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected readonly inviting = signal(false);
  protected readonly email = signal('');
  protected readonly role = signal('');
  protected readonly inviteError = signal<string | null>(null);

  protected readonly changing = signal<TeamMember | null>(null);
  protected readonly newRole = signal('');

  protected readonly inviteIcon = UserPlus;
  protected readonly until = until;

  protected readonly roleChoices = computed<MfChoice[]>(() =>
    (this.page()?.roles ?? []).map((r) => ({ value: r.value, label: r.label, hint: r.description })),
  );

  protected readonly inviteReady = computed(() => /.+@.+\..+/.test(this.email().trim()) && !!this.role() && !this.busy());

  ngOnInit(): void {
    void this.load();
  }

  async load(): Promise<void> {
    this.loading.set(true);
    this.error.set(null);

    try {
      this.page.set(await this.organizer.team());
    } catch (error) {
      this.error.set(messageOf(error));
    } finally {
      this.loading.set(false);
    }
  }

  protected roleLabel(role: string): string {
    return this.page()?.roles.find((r) => r.value === role)?.label ?? role;
  }

  protected startInvite(): void {
    const roles = this.page()?.roles ?? [];
    // The least a new person can do, unless somebody chooses otherwise.
    this.role.set(roles[roles.length - 1]?.value ?? '');
    this.email.set('');
    this.inviteError.set(null);
    this.inviting.set(true);
  }

  protected async invite(): Promise<void> {
    if (!this.inviteReady()) return;

    this.busy.set(true);
    this.inviteError.set(null);

    try {
      const { message } = await this.organizer.invite(this.email().trim(), this.role());
      this.inviting.set(false);
      this.toasts.show(message, 'success');
      await this.load();
    } catch (error) {
      this.inviteError.set(messageOf(error, 'That invitation could not be sent.'));
    } finally {
      this.busy.set(false);
    }
  }

  protected async memberMenu(member: TeamMember): Promise<void> {
    if (member.is_you) return;

    const chosen = await this.dialogs.menu({
      title: member.name,
      subtitle: `${this.roleLabel(member.role)} · ${member.email}`,
      actions: [
        { key: 'role', label: 'Change what they can do' },
        { key: 'remove', label: 'Take them off the team', danger: true },
      ],
    });

    if (chosen === 'role') {
      this.newRole.set(member.role);
      this.changing.set(member);
    }

    if (chosen === 'remove') {
      const sure = await this.dialogs.confirm({
        title: `Take ${member.name} off the team?`,
        message: 'They lose access straight away, on every device. What they did stays in the record.',
        confirm: 'Take them off',
        danger: true,
      });

      if (!sure) return;

      try {
        const { message } = await this.organizer.removeMember(member.id);
        this.toasts.show(message, 'success');
        await this.load();
      } catch (error) {
        this.toasts.show(messageOf(error), 'danger');
      }
    }
  }

  protected async changeRole(): Promise<void> {
    const member = this.changing();
    if (!member) return;

    this.busy.set(true);

    try {
      const { message } = await this.organizer.changeRole(member.id, this.newRole());
      this.changing.set(null);
      this.toasts.show(message, 'success');
      await this.load();
      await this.session.sync();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    } finally {
      this.busy.set(false);
    }
  }

  protected async invitationMenu(invitation: Invitation): Promise<void> {
    const chosen = await this.dialogs.menu({
      title: invitation.email,
      subtitle: this.roleLabel(invitation.role),
      actions: [
        ...(invitation.expired ? [{ key: 'again', label: 'Invite them again' }] : []),
        { key: 'revoke', label: 'Take the invitation back', danger: true },
      ],
    });

    try {
      if (chosen === 'again') {
        await this.organizer.revokeInvitation(invitation.id);
        const { message } = await this.organizer.invite(invitation.email, invitation.role);
        this.toasts.show(message, 'success');
      } else if (chosen === 'revoke') {
        const { message } = await this.organizer.revokeInvitation(invitation.id);
        this.toasts.show(message, 'success');
      } else {
        return;
      }

      await this.load();
    } catch (error) {
      this.toasts.show(messageOf(error), 'danger');
    }
  }
}
