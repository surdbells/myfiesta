<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsSender;
use App\Models\Order;
use App\Models\PhonePreference;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether to send somebody a text, and what it says.
 *
 * Two messages, both of which the person asked for by buying a ticket: the
 * ticket itself, and the reminder before the doors. Nothing sells anything —
 * a marketing text needs consent this platform does not collect, in both
 * markets, and the campaign machinery is email for that reason.
 *
 * Three gates before anything is sent. There has to be a number; it has to be
 * in a country worth texting, because a text costs where an email does not;
 * and the number must not have replied STOP, which is a suppression list of
 * the same kind as the email one and is honoured for every message including
 * the ticket.
 *
 * Nothing here ever throws. A ticket that arrives by email and not by text is
 * a worse afternoon for us and no difference at all to the buyer.
 */
class Texts
{
    /** Roughly the length of one message part; longer costs twice. */
    public const ONE_PART = 160;

    public function __construct(private readonly SmsSender $sender) {}

    /** The link to the tickets, right after paying. */
    public function ticketsReady(Order $order): bool
    {
        $event = $order->event;

        return $this->send($order->buyer_phone, sprintf(
            '%s: your %s %s ready. %s',
            $event?->title ?? 'myFiesta',
            $order->tickets()->count() === 1 ? 'ticket is' : 'tickets are',
            'now',
            $this->ticketsUrl($order),
        ));
    }

    /** A few hours before the doors, to the number on the order. */
    public function doorsSoon(Order $order, string $whenWords): bool
    {
        $event = $order->event;

        return $this->send($order->buyer_phone, sprintf(
            '%s %s. %s',
            $event?->title ?? 'Your event',
            $whenWords,
            $this->ticketsUrl($order),
        ));
    }

    /**
     * @param  string|null  $to  whatever was typed at checkout, if anything
     */
    private function send(?string $to, string $message): bool
    {
        $number = PhoneNumber::e164($to);

        if ($number === null || ! $this->worthTexting($number) || PhonePreference::hasOptedOut($number)) {
            return false;
        }

        try {
            $result = $this->sender->send($number, $this->trim($message));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if (! $result->accepted) {
            Log::warning('Text message refused.', ['provider' => $this->sender->name(), 'error' => $result->error]);
        }

        return $result->accepted;
    }

    /**
     * Only where a text is the message that gets read.
     *
     * Nigeria today. A Canadian buyer already has the email, and paying a
     * penny a head to tell them the same thing twice is a cost with no
     * argument behind it.
     */
    private function worthTexting(string $number): bool
    {
        $codes = config('sms.countries', []);

        foreach ($codes as $code) {
            if (str_starts_with($number, '+'.trim($code))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep it to one message where possible.
     *
     * Not a cosmetic limit: a message over the length is billed as two and,
     * on some networks, arrives as two.
     */
    private function trim(string $message): string
    {
        return mb_strlen($message) <= self::ONE_PART
            ? $message
            : mb_substr($message, 0, self::ONE_PART - 1).'…';
    }

    private function ticketsUrl(Order $order): string
    {
        return rtrim((string) config('app.public_url'), '/').'/tickets/'.$order->access_token;
    }
}
