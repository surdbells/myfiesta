<?php

namespace App\Services\Disputes;

use App\Services\Accounts\Terms;

/**
 * The refund policy as a buyer was shown it, by terms version.
 *
 * A dispute over a refund is judged on what the buyer agreed to when they
 * paid, not on what the policy says by the time the bank asks. The public
 * site only ever shows today's words, so each version's are kept here too, in
 * resources/legal/<version>, never edited once in force (LegalCopiesTest holds
 * them to that, and holds the site to the copy for the version in force).
 *
 * The summary is the one sentence beside the terms box on the site's checkout
 * and beside the pay button on Stripe's page — the same words in both places,
 * read from here by the one and checked against here for the other.
 */
class RefundPolicy
{
    public function __construct(private readonly Terms $terms) {}

    /** The refund policy page under a version, as Markdown; null for a version with no copy. */
    public function text(?string $version = null): ?string
    {
        return $this->read($version, 'refunds.md');
    }

    /** The one-sentence summary under a version; null for one that showed none. */
    public function summary(?string $version = null): ?string
    {
        $summary = $this->read($version, 'refund-summary.txt');

        return $summary === null ? null : trim($summary);
    }

    /** Where a version's words are kept. */
    public static function directory(string $version): string
    {
        return resource_path('legal/'.$version);
    }

    private function read(?string $version, string $file): ?string
    {
        $version ??= $this->terms->current();

        // A version is a name the code wrote, never a path somebody typed —
        // but it is read from orders too, so it is kept to a plain name.
        if (preg_match('/^[A-Za-z0-9._-]+$/', $version) !== 1 || str_contains($version, '..')) {
            return null;
        }

        $path = self::directory($version).'/'.$file;

        if (! is_file($path)) {
            return null;
        }

        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }
}
