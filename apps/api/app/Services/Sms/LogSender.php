<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsResult;
use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * The driver with no account behind it.
 *
 * Writes what it would have sent and says it worked, which is what lets the
 * whole path — the deciding, the wording, the opt-out — be built and reviewed
 * before anybody signs a provider contract. The number is written in full: the
 * log is already where a ticket's own emails are traced in development, and
 * this driver is never the one running in production.
 */
class LogSender implements SmsSender
{
    public function send(string $to, string $message): SmsResult
    {
        Log::info('SMS (not really sent)', ['to' => $to, 'message' => $message]);

        return SmsResult::accepted('log');
    }

    public function name(): string
    {
        return 'log';
    }
}
