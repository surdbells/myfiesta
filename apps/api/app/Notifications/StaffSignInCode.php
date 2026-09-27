<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The code that finishes a staff sign-in, sent to the address on the account.
 *
 * Every staff sign-in asks for one: a password alone never opens the admin.
 * The admin can refund, settle and read bank details, and a password is the
 * thing most likely to have been reused somewhere that leaked.
 *
 * Sent during the request rather than queued, like the other emails that
 * carry something that exists nowhere else: the session keeps only a hash of
 * the code, and a queued notification would be a readable copy of it sitting
 * in the jobs table for as long as the worker took to get to it. It also means
 * the code arrives while the person is still looking at the box to type it in,
 * whether or not a worker is running.
 *
 * Filament builds this with the code and its lifetime as named arguments, so
 * the constructor's parameter names are part of the contract.
 */
class StaffSignInCode extends Notification
{
    /** How long a code works. Long enough for a slow inbox, short enough to be useless later. */
    public const EXPIRES_MINUTES = 10;

    public function __construct(
        #[SensitiveParameter]
        public readonly string $code,
        public readonly int $codeExpiryMinutes = self::EXPIRES_MINUTES,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $support = config('mail.support.address');

        return (new MailMessage)
            // The code is not in the subject: subjects show on lock screens
            // and in notification previews, to whoever is looking.
            ->subject('Your myFiesta admin sign-in code')
            ->greeting('Finish signing in')
            ->line('Enter this code on the admin sign-in screen:')
            ->line('**'.$this->code.'**')
            ->line('It works once, for '.$this->codeExpiryMinutes.' minutes.')
            ->line('Not signing in right now? Then somebody else has your admin password. Change it straight away and tell another administrator — the code on its own is useless to them.')
            ->replyTo(filled($support) ? $support : (string) config('mail.from.address'), config('mail.from.name'));
    }
}
