<?php

namespace Tests\Feature;

use App\Services\Disputes\RefundPolicy;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What buyers were shown, kept as they were shown it.
 *
 * A dispute is judged on the refund policy the buyer agreed to when they
 * paid, and the public site only ever shows today's. So each terms version's
 * refund policy, and the sentence by the pay button, are kept in the API
 * (resources/legal/<version>) — and three things are checked here, the way
 * the reserved slugs are checked against the site's routes:
 *
 *   the version in force has its words kept;
 *   no kept words have changed since they were in force — changed words are
 *   a new version, with a directory of its own;
 *   the site's refund page and checkout say what the copy for the version in
 *   force says, word for word.
 */
class LegalCopiesTest extends TestCase
{
    private const WEB_INFO = __DIR__.'/../../../web/src/app/features/info/info.html';

    private const WEB_CHECKOUT = __DIR__.'/../../../web/src/app/features/checkout/checkout.html';

    /**
     * Every kept file, and what it said when its version was in force.
     *
     * Add a line for each file of a new version. Never change a line: a
     * checksum that no longer matches is words somebody agreed to being
     * rewritten after the fact.
     */
    private const KEPT = [
        '2026-09-27/refunds.md' => 'a1e91fb2aae2d52e80ed615051156233c637ac3a4aab9a9371da908f088769f0',
        '2026-09-27.2/refunds.md' => 'a1e91fb2aae2d52e80ed615051156233c637ac3a4aab9a9371da908f088769f0',
        '2026-09-27.2/refund-summary.txt' => '15767da658cd32c42302d7eb5f8b4c7c33c957e7e945d2abbe85d7def19e3d72',
    ];

    public function test_the_version_in_force_has_its_words_kept(): void
    {
        $version = config('terms.version');
        $policy = app(RefundPolicy::class);

        $this->assertNotNull($policy->text(), "resources/legal/{$version}/refunds.md is missing: the terms version in force has no copy of its refund policy.");
        $this->assertNotNull($policy->summary(), "resources/legal/{$version}/refund-summary.txt is missing: checkout and Stripe's pay button have nothing to say about refunds.");
        $this->assertStringNotContainsString("\n", (string) $policy->summary(), 'The refund summary is one sentence on one line.');
    }

    public function test_no_kept_words_have_changed_since_they_were_in_force(): void
    {
        $root = resource_path('legal');

        $files = collect(glob($root.'/*/*') ?: [])
            ->map(fn (string $path) => Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', $root).'/'))
            ->sort()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            array_keys(self::KEPT),
            $files,
            'A file under resources/legal has no checksum here, or one listed here is gone. A new version adds its files and their checksums; nothing is ever removed.',
        );

        foreach (self::KEPT as $file => $checksum) {
            // Line endings as git stores them, whatever the checkout did.
            $contents = str_replace("\r\n", "\n", (string) file_get_contents($root.'/'.$file));

            $this->assertSame(
                $checksum,
                hash('sha256', $contents),
                "resources/legal/{$file} has changed since its version was in force. Put the new words in a new version instead.",
            );
        }
    }

    public function test_the_sites_refund_page_says_what_the_copy_in_force_says(): void
    {
        $html = $this->read(self::WEB_INFO);

        $page = Str::between($html, "@case ('refunds') {", "@case ('contact')");
        $this->assertNotSame($html, $page, "The refund page was not found in info.html; has the @case ('refunds') block moved?");

        // The operator's details are drawn in from the API's config, not
        // written on the page, and are not part of the policy.
        $page = (string) preg_replace('/<!--.*?-->|<ng-container[^>]*\/>/s', '', $page);
        $page = (string) preg_replace('/\}\s*$/', '', trim($page));
        $page = html_entity_decode(strip_tags($page), ENT_QUOTES | ENT_HTML5);

        $kept = (string) app(RefundPolicy::class)->text();
        $kept = (string) preg_replace('/^#+\s*/m', '', $kept);
        $kept = (string) preg_replace('/\*\*(.+?)\*\*/', '$1', $kept);
        $kept = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $kept);

        $this->assertSame(
            $this->words($kept),
            $this->words($page),
            'The refund page on the public site no longer says what resources/legal/'.config('terms.version').'/refunds.md says. '
                .'Changed words are a new terms version: bump config/terms.php and keep the new words under it.',
        );
    }

    public function test_the_sites_checkout_says_the_summary_in_force_beside_the_terms_box(): void
    {
        $checkout = $this->words(strip_tags($this->read(self::WEB_CHECKOUT)));

        $this->assertStringContainsString(
            $this->words((string) app(RefundPolicy::class)->summary()),
            $checkout,
            'The checkout on the public site does not show the refund summary for the terms in force, word for word.',
        );
    }

    private function read(string $path): string
    {
        if (! file_exists($path)) {
            $this->markTestSkipped('The public site is not checked out beside the API.');
        }

        return (string) file_get_contents($path);
    }

    /** The words in order, with the spacing and line breaks of the markup left out. */
    private function words(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
