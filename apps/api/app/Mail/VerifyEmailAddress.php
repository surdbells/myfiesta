<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesReachSupport;
use App\Services\Accounts\EmailVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A link that proves an existing account's address.
 *
 * Asked for from the account, or sent on its own when the account tries
 * something that needs a proved address — publishing an event, or anything to
 * do with where its money goes.
 *
 * Sent straight away rather than queued, like every email carrying a link that
 * works on its own: a queued mail is a copy of it sitting in the jobs table.
 */
class VerifyEmailAddress extends Mailable
{
    use Queueable, RepliesReachSupport;

    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: 'Confirm your email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verify-email-address',
            with: [
                'url' => $this->url,
                'hours' => EmailVerification::EXPIRES_HOURS,
            ],
        );
    }
}
