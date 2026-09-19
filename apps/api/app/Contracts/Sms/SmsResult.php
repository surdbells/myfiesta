<?php

namespace App\Contracts\Sms;

/** What a provider said when it was handed a message. */
final readonly class SmsResult
{
    public function __construct(
        public bool $accepted,
        public ?string $reference = null,
        public ?string $error = null,
    ) {}

    public static function accepted(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function refused(string $error): self
    {
        return new self(false, null, $error);
    }
}
