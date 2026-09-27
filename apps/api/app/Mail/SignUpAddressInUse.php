<?php

namespace App\Mail;

use App\Mail\Concerns\RepliesReachSupport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent instead of a sign-up link, when the address already has an account.
 *
 * The sign-up form says "check your email" either way, so it cannot be used to
 * test who holds an account here. This is the only place the truth goes, and
 * it goes to the one person entitled to it: whoever reads this address. It
 * carries nothing that completes anything — a way in is a sign-in or a reset,
 * both of which the owner can already do.
 *
 * Sent straight away, not queued, because the link it stands in for is (see
 * SignUpConfirm). Queued while the link was not, this case would answer the
 * sign-up form sooner than the other — and how long the answer took would say
 * whether the address has an account.
 */
class SignUpAddressInUse extends Mailable
{
    use Queueable, RepliesReachSupport;

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: 'You already have a myFiesta account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sign-up-address-in-use',
            with: [
                'signIn' => rtrim((string) config('app.console_url'), '/').'/sign-in',
                'reset' => rtrim((string) config('app.console_url'), '/').'/forgot-password',
            ],
        );
    }
}
