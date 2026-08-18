<?php

namespace App\Support\Observability;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Last check before an error leaves the process.
 *
 * send_default_pii is off, which stops Sentry attaching user details on
 * purpose. It does not stop an exception carrying them by accident: a stack
 * trace captures local variables, and the frames around identity review or a
 * payout form hold decrypted bank details and government identifiers. Those are
 * exactly the values that must never reach a third-party service.
 *
 * So this runs on every event and removes anything whose name suggests it is
 * sensitive, wherever it appears. It errs towards over-redaction — a redacted
 * field costs a little debugging context, a leaked one costs considerably more.
 */
final class SentryScrubber
{
    /**
     * Matched case-insensitively as substrings, so `bank_account_number` and
     * `accountNumber` are both caught without listing every spelling.
     */
    private const SENSITIVE = [
        'password', 'secret', 'token', 'authorization', 'api_key', 'apikey',
        'account_number', 'accountnumber', 'transit', 'institution', 'bank',
        'interac', 'iban', 'sort_code', 'card', 'cvv', 'cvc', 'pan',
        'document_number', 'documentnumber', 'date_of_birth', 'dob',
        'legal_first_name', 'legal_last_name', 'national_id', 'passport',
        'signature', 'webhook_secret', 'private_key',
    ];

    private const REDACTED = '[redacted]';

    /**
     * Static so the config entry stays a plain array callable.
     *
     * A closure here would work and would also break `config:cache`, which is
     * the difference between a fast boot in production and a fatal error.
     */
    public static function handle(Event $event, ?EventHint $hint): ?Event
    {
        $scrubber = new self;

        if ($request = $event->getRequest()) {
            $event->setRequest($scrubber->scrub($request));
        }

        $event->setExtra($scrubber->scrub($event->getExtra()));

        // Tags are indexed and searchable in Sentry, so a sensitive value here
        // is worse than one buried in a payload.
        $tags = array_map(
            fn ($value, $key) => $scrubber->isSensitive((string) $key) ? self::REDACTED : $value,
            $event->getTags(),
            array_keys($event->getTags()),
        );
        $event->setTags(array_combine(array_keys($event->getTags()), $tags));

        return $event;
    }

    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
