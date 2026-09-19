<?php

namespace App\Services\Sms;

use App\Contracts\Sms\SmsResult;
use App\Contracts\Sms\SmsSender;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Termii, for Nigerian numbers.
 *
 * The sender id must be one Termii has registered for the account; an
 * unregistered one is accepted by the API and then quietly never delivered,
 * which is the failure that wastes an afternoon.
 */
class TermiiSender implements SmsSender
{
    public function send(string $to, string $message): SmsResult
    {
        $key = config('sms.termii.key');

        if (blank($key)) {
            return SmsResult::refused('No Termii API key is configured.');
        }

        try {
            $response = Http::timeout(15)->post(config('sms.termii.endpoint'), [
                // Termii wants the number without the plus.
                'to' => ltrim($to, '+'),
                'from' => config('sms.termii.from'),
                'sms' => $message,
                'type' => 'plain',
                'channel' => 'generic',
                'api_key' => $key,
            ]);
        } catch (Throwable $e) {
            return SmsResult::refused($e->getMessage());
        }

        if ($response->failed()) {
            return SmsResult::refused($response->json('message') ?? 'Termii refused the message.');
        }

        return SmsResult::accepted($response->json('message_id'));
    }

    public function name(): string
    {
        return 'termii';
    }
}
