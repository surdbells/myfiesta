<?php

namespace App\Mail;

use App\Models\EmailChange;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The link that moves an account to this address, sent to this address.
 *
 * Opening it is the only proof anybody has that they can read the new inbox,
 * which is the whole point: until it is opened, nothing about the account has
 * changed.
 *
 * Nothing in it comes from the person asking — not their name, not the address
 * they use now. Any account can ask to move to any address, so whatever it
 * carried of theirs would be something a stranger could put in front of anybody
 * at all, in an email from us. A name can even be written to show up as a
 * link.
 *
 * Carries the plain token, which exists nowhere else — the database keeps only
 * its hash. Sent straight away rather than queued for the same reason as a team
 * invitation: a queued mail is a serialized copy of the token sitting in the
 * jobs table.
 *
 * The link opens the console, like a password reset. The API serves no pages,
 * and the console is where the reset-password screen already lives.
 */
class EmailChangeConfirm extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your new email address');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-change-confirm',
            with: [
                'url' => self::url($this->token),
                'minutes' => EmailChange::EXPIRES_MINUTES,
            ],
        );
    }

    /** Where the link lands: the console's confirm screen, with the token in the query as a reset link carries it. */
    public static function url(string $token): string
    {
        return rtrim(config('app.console_url'), '/').'/confirm-email?token='.$token;
    }
}
