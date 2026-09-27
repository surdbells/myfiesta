<?php

namespace App\Support;

use RuntimeException;

/**
 * Production refusing to start, and why.
 *
 * The message names every variable to change and nothing about their values:
 * it lands in a container log, which is read by more people than read the
 * secrets themselves.
 */
final class PreflightFailed extends RuntimeException
{
    /** @param  array<string, string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(
            "Refusing to run in production until these are fixed:\n"
                .implode("\n", array_map(
                    fn (string $variable, string $why) => "  {$variable} {$why}",
                    array_keys($problems),
                    $problems,
                ))
                ."\nRun `php artisan app:preflight` to check again."
        );
    }

    /** @param  array<string, string>  $problems */
    public static function with(array $problems): self
    {
        return new self($problems);
    }
}
