<?php

namespace App\Mail;

use App\Models\OrganizationInvitation;
use App\Services\Team\TeamService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Ada added you to Lagos Nights as Finance."
 *
 * Carries the plain token, which exists nowhere else: the database keeps only
 * its hash. Sent straight away rather than queued for the same reason — a
 * queued mail is a serialized copy of the token sitting in the jobs table. Says what the role can do, because "finance" means different
 * things at different companies and the person accepting should know whether
 * they are being handed the payouts.
 */
class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly OrganizationInvitation $invitation,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Join {$this->invitation->organization->name} on myFiesta");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.team-invitation',
            with: [
                'organization' => $this->invitation->organization->name,
                'inviter' => $this->invitation->inviter?->name,
                'role' => $this->invitation->role->label(),
                'can' => self::describe($this->invitation->role->value),
                'url' => rtrim(config('app.console_url'), '/').'/join/'.$this->token,
                'days' => TeamService::INVITATION_DAYS,
            ],
        );
    }

    /** What a role can do, in the words the console uses. */
    public static function describe(string $role): string
    {
        return match ($role) {
            'owner' => 'everything, including payouts and the team',
            'manager' => 'run events end to end — tickets, codes, the door, orders and refunds',
            'finance' => 'see what events made, and process refunds',
            'marketing' => 'the guest list, promoter codes and messages to ticket holders',
            'door' => 'scan tickets at the door, and nothing else',
            default => $role,
        };
    }
}
