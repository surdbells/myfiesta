<?php

namespace Tests\Feature;

use App\Models\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The site's own pages at its root, checked against the slugs an event may not
 * be given.
 *
 * Event pages live at myfiesta.ca/{slug}, below every page the site answers at
 * its root. A page added there without a word in Event::RESERVED_SLUGS takes
 * the address of the next event given that slug: /refunds went in on its own,
 * an event titled "Refunds" would have been given `refunds`, and its link
 * would have shown the refund policy. The phone keeps its own copy of the
 * list, to know which links are not events, and missed /refunds the same way —
 * a link to the policy opened as an event that was "not found".
 *
 * A test rather than a generated list, as with the permissions: three short
 * lists in two languages, and what was missing was somebody being told.
 */
class ReservedSlugMirrorTest extends TestCase
{
    private const WEB_ROUTES = __DIR__.'/../../../web/src/app/app.routes.ts';

    private const PHONE_LINKS = __DIR__.'/../../../mobile/src/app/core/deep-links.ts';

    #[Test]
    public function no_event_can_be_given_a_page_the_site_answers_at_its_root(): void
    {
        $this->assertSame(
            [],
            array_values(array_diff($this->sitePages(), Event::RESERVED_SLUGS)),
            'A top-level route in apps/web is missing from Event::RESERVED_SLUGS.',
        );
    }

    #[Test]
    public function the_phone_knows_every_page_the_site_answers_at_its_root(): void
    {
        if (! file_exists(self::PHONE_LINKS)) {
            $this->markTestSkipped('The phone app is not checked out beside the API.');
        }

        $phone = str(file_get_contents(self::PHONE_LINKS))
            ->after('const SITE_PAGES = new Set([')
            ->before(']);')
            ->matchAll("/'([^']+)'/")
            ->all();

        $this->assertNotEmpty($phone, 'SITE_PAGES was not found in the phone app; has it moved?');

        $this->assertSame(
            [],
            array_values(array_diff($this->sitePages(), $phone)),
            'A top-level route in apps/web is missing from SITE_PAGES in apps/mobile/src/app/core/deep-links.ts.',
        );

        // The other way round too: a word the phone never opens as an event
        // has to be one no event can have, or that event's link opens nothing.
        $this->assertSame(
            [],
            array_values(array_diff($phone, Event::RESERVED_SLUGS)),
            'SITE_PAGES in the phone app names a slug an event could still be given.',
        );
    }

    /**
     * The first segment of every route in apps/web that is a word rather than
     * a parameter — the pages that sit above the event wildcard.
     *
     * @return list<string>
     */
    private function sitePages(): array
    {
        if (! file_exists(self::WEB_ROUTES)) {
            $this->markTestSkipped('The public site is not checked out beside the API.');
        }

        $pages = str(file_get_contents(self::WEB_ROUTES))
            ->matchAll("/\\bpath:\\s*'([^']*)'/")
            ->map(fn (string $path) => explode('/', $path)[0])
            ->reject(fn (string $segment) => $segment === '' || str_starts_with($segment, ':') || str_starts_with($segment, '*'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        // An empty list would pass everything above; a route file that has
        // moved or changed shape should say so rather than check nothing.
        $this->assertNotEmpty($pages, 'No routes were found in apps/web/src/app/app.routes.ts; has it moved?');

        return $pages;
    }
}
