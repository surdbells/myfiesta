<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent instead of a confirmation link, when the new address already has an
 * account of its own.
 *
 * The person asking is told the same thing either way — "check that inbox" —
 * so the change screen cannot be used to test who holds an account here. This
 * is the only place the truth goes, and it goes to the one person entitled to
 * it: whoever reads this address.
 *
 * Carries no link that could complete anything. A change is still left
 * waiting, so that nothing else can tell this case apart, but its token is sent
 * nowhere — this email included.
 */
class EmailChangeAddressInUse extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Somebody tried to use this address on myFiesta');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-change-address-in-use',
            with: [
                'signIn' => rtrim(config('app.console_url'), '/').'/sign-in',
                'reset' => rtrim(config('app.console_url'), '/').'/forgot-password',
            ],
        );
    }
}
