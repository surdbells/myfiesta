<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your account now uses a different address", sent to the one it used before.
 *
 * The last word this address gets about signing in to the account, so it has
 * to be enough on its own. If the change was not theirs, a reset link is no
 * help any more — it would go to the new address — so it tells them to reply,
 * which reaches a person.
 *
 * The old address is passed in rather than read from the account, which by now
 * holds the new one. Both addresses and the name were typed by whoever holds
 * the account, which after a takeover is not the person reading this — hence
 * KeepsTypedTextPlain.
 */
class EmailChanged extends Mailable
{
    use KeepsTypedTextPlain, Queueable;

    public function __construct(
        public readonly User $user,
        public readonly string $oldEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your email address has been changed');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.email-changed',
            with: [
                'name' => $this->user->name,
                'old' => $this->oldEmail,
                'new' => $this->user->email,
            ],
        );
    }
}
