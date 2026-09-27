<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesReachSupport;
use App\Models\PendingRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The link that makes an account, sent to the address it will be made for.
 *
 * Nothing exists until it is opened: this is the only proof anybody has that
 * the address is theirs. Nothing in it comes from the person who asked — not
 * the name they gave, not the events page they named — because anybody can
 * sign up with any address, and whatever it carried of theirs would be words
 * a stranger could put in front of anybody, in an email from us. The page the
 * link opens holds to the same rule, and shows only the address: its password
 * box is the one place a stranger's words would do the most harm.
 *
 * Sent straight away rather than queued: the link is a working credential for
 * a day, and a queued mail is a serialized copy of it in the jobs table. The
 * sign-up form answers the same way whether this was sent or its
 * SignUpAddressInUse counterpart was, and both leaving during the request is
 * what keeps the time it takes from telling them apart.
 */
class SignUpConfirm extends Mailable
{
    use Queueable, RepliesReachSupport;

    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: 'Finish setting up your myFiesta account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sign-up-confirm',
            with: [
                'url' => $this->url,
                'hours' => PendingRegistration::EXPIRES_HOURS,
            ],
        );
    }
}
