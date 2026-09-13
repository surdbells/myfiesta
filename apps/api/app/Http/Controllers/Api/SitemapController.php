<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Response;

/**
 * The public site's sitemap, served through the site at /sitemap.xml.
 *
 * Event pages are rendered per request and linked from the listing, but the
 * listing only shows what is on sale now and paginates — so an event three
 * pages down, or one sold out, was a page a search engine had to stumble on.
 * This lists every public event page directly.
 *
 * Built here because the API is what knows which events are public. The site
 * proxies it, so the sitemap lives on the site's own origin where crawlers
 * expect it.
 */
class SitemapController extends Controller
{
    /** Past events stay listed a while: the gallery is what sells the next one. */
    public const PAST_DAYS = 90;

    /** The protocol's limit per file, well past anything this needs for now. */
    public const LIMIT = 50000;

    /** Fixed pages that are worth a crawler's time. */
    private const PAGES = ['', 'events', 'help', 'contact', 'terms', 'privacy'];

    public function __invoke(): Response
    {
        $base = rtrim((string) config('app.public_url'), '/');

        $events = Event::query()
            ->where('status', 'published')
            // Invitation events are reachable only by their guests' links.
            ->where('kind', 'ticketed')
            ->where('starts_at', '>=', now()->subDays(self::PAST_DAYS))
            ->orderBy('starts_at')
            ->limit(self::LIMIT - count(self::PAGES))
            ->get(['slug', 'updated_at']);

        $urls = array_map(fn (string $path) => $this->url($base.'/'.$path), self::PAGES);

        foreach ($events as $event) {
            $urls[] = $this->url($base.'/'.$event->slug, $event->updated_at->toAtomString());
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $urls)."\n"
            .'</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function url(string $location, ?string $modified = null): string
    {
        return '  <url><loc>'.htmlspecialchars($location, ENT_XML1).'</loc>'
            .($modified ? '<lastmod>'.$modified.'</lastmod>' : '')
            .'</url>';
    }
}
