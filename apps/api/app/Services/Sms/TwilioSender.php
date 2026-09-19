<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsResult;
use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Twilio, for Canadian numbers and anywhere Termii does not reach. */
class TwilioSender implements SmsSender
{
    public function send(string $to, string $message): SmsResult
    {
        $sid = config('sms.twilio.sid');
        $token = config('sms.twilio.token');
        $from = config('sms.twilio.from');

        if (blank($sid) || blank($token) || blank($from)) {
            return SmsResult::refused('Twilio is not configured.');
        }

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $message,
                ]);
        } catch (Throwable $e) {
            return SmsResult::refused($e->getMessage());
        }

        if ($response->failed()) {
            return SmsResult::refused($response->json('message') ?? 'Twilio refused the message.');
        }

        return SmsResult::accepted($response->json('sid'));
    }

    public function name(): string
    {
        return 'twilio';
    }
}
