<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Address;

/**
 * "Reply to this email" reaches somebody who can help.
 *
 * The emails that warn somebody their account or their payouts have been
 * touched end with the same advice: if it was not you, reply straight away. A
 * reset link is no use to somebody whose address has already been moved, and
 * an owner whose payouts were just redirected needs a person, not a form. With
 * nothing set, a reply goes to the from address, which is chosen for
 * deliverability rather than for anybody reading it — and in the example
 * config is a placeholder.
 *
 * So these name the support inbox as the Reply-To, and fall back to the from
 * address when none is configured: always an inbox, even if not the ideal one.
 * The fallback is decided here, when the email is written, rather than in the
 * config file, so the two can never disagree about which one it is.
 */
trait RepliesReachSupport
{
    /** @return list<Address> */
    protected function supportReplyTo(): array
    {
        $support = config('mail.support.address');

        return [new Address(
            filled($support) ? $support : (string) config('mail.from.address'),
            config('mail.from.name'),
        )];
    }
}
