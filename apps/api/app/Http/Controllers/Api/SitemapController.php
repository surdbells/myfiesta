<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\HelpVideo;
use App\Models\Organization;
use App\Services\Discovery\Places;
use Illuminate\Http\Response;

/**
 * The public site's sitemap, served through the site at /sitemap.xml.
 *
 * Event pages are rendered per request and linked from the listing, but the
 * listing only shows what is on sale now and paginates — so an event three
 * pages down, or one sold out, was a page a search engine had to stumble on.
 * This lists every public event page directly, and every organizer page with
 * it: an organizer's page is linked from their events and from nowhere else,
 * so a crawler that never reaches an event never learns it exists.
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
    private const PAGES = ['', 'events', 'help', 'help/videos', 'contact', 'terms', 'privacy', 'refunds'];

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

        // The how-to videos page once there is a video on it, and not before:
        // until then it is an empty list, and a crawler sent there is told
        // to index nothing.
        $pages = HelpVideo::query()->where('published', true)->exists()
            ? self::PAGES
            : array_values(array_diff(self::PAGES, ['help/videos']));

        $urls = array_map(fn (string $path) => $this->url($base.'/'.$path), $pages);

        foreach ($events as $event) {
            $urls[] = $this->url($base.'/'.$event->slug, $event->updated_at->toAtomString());
        }

        foreach ($this->organizers() as $organizer) {
            $urls[] = $this->url($base.'/o/'.$organizer->slug, $organizer->updated_at?->toAtomString());
        }

        // Each category and city with something coming up has a page of its
        // own, with its own title — the page somebody searching "comedy in
        // Lagos" should land on. Only those with something on: a sitemap
        // entry for an empty page is a crawler told to index nothing.
        $places = app(Places::class);

        foreach ($places->categories(50) as $category) {
            $urls[] = $this->url($base.'/events/category/'.$category['slug']);
        }

        foreach (collect($places->cities(200))->pluck('slug')->unique() as $city) {
            $urls[] = $this->url($base.'/events/city/'.$city);
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

    /**
     * Organizers with a page, which is organizers who have published a night.
     *
     * The same rule the page itself applies: an account that has only ever
     * registered has no page, so listing it here would be a sitemap pointing
     * at 404s. Past events count — a name with a history is exactly what
     * somebody searching for a promoter is trying to find.
     */
    private function organizers()
    {
        return Organization::query()
            ->whereExists(fn ($query) => $query
                ->selectRaw(1)
                ->from('events')
                ->whereColumn('events.organization_id', 'organizations.id')
                ->where('events.status', 'published')
                ->where('events.kind', 'ticketed')
                ->whereNull('events.deleted_at'))
            ->orderBy('slug')
            ->limit(self::LIMIT - count(self::PAGES))
            ->get(['slug', 'updated_at']);
    }

    private function url(string $location, ?string $modified = null): string
    {
        return '  <url><loc>'.htmlspecialchars($location, ENT_XML1).'</loc>'
            .($modified ? '<lastmod>'.$modified.'</lastmod>' : '')
            .'</url>';
    }
}
