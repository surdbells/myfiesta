<?php

namespace App\Contracts\Sms;

/**
 * One provider that can put a text on somebody's phone.
 *
 * The same shape as the payment gateways: the domain talks to this, an
 * adapter per provider talks to the world, and swapping Termii for somebody
 * else is one binding rather than a search through the codebase.
 */
interface SmsSender
{
    /**
     * Send one message.
     *
     * Never throws: a text that does not arrive must not take down the thing
     * it was about. What it returns says whether the provider accepted it,
     * which is the most any provider can tell us at this point — delivery is
     * somebody else's network and minutes away.
     *
     * @param  string  $to  in E.164, with the plus
     */
    public function send(string $to, string $message): SmsResult;

    /** For the log line, and for telling two adapters apart in a test. */
    public function name(): string;
}
