<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\EmailChange;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Somebody asked to move your account to another address."
 *
 * Sent to the address the account uses now, every time a change is asked for —
 * including when the new address could not be used, so that whether this email
 * arrives says nothing about who else holds an account here.
 *
 * It is also the owner's one warning if the request was not theirs, so it says
 * what to do about it: a new password cancels the change and signs everybody
 * else out, and that works from the forgot-password screen even for somebody
 * who has no idea what the password was changed to.
 *
 * The new address in it, and the name, were typed by whoever is signed in —
 * the person it is warning about, if it was not the owner. KeepsTypedTextPlain
 * is what stops either of them turning into a link beside that advice.
 *
 * Sent straight away, like the link it warns about (see EmailChangeConfirm).
 * Queued, it would wait on the queue worker while the link did not, so a
 * stopped worker would deliver the link and hold back the warning for as long
 * as the link works. It is also not built to be queued: nothing here reduces
 * the account to an id, so a queued copy would be the whole row, password hash
 * included, in the jobs table.
 *
 * Somebody frightened by this will reply to it whatever it says, so a reply
 * goes to the support inbox (RepliesReachSupport).
 */
class EmailChangeRequested extends Mailable
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport;

    public function __construct(
        public readonly User $user,
        public readonly string $newEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: $this->supportReplyTo(),
            subject: 'Somebody asked to change your email address',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-change-requested',
            with: [
                'name' => $this->user->name,
                'new' => $this->newEmail,
                'minutes' => EmailChange::EXPIRES_MINUTES,
                'reset' => rtrim(config('app.console_url'), '/').'/forgot-password',
            ],
        );
    }
}
