<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Models\TicketType;
use App\Models\Venue;
use App\Services\Images\ImageStore;
use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable week of nights, for looking at the public site on a laptop.
 *
 * Five cities, most categories, and every state a shelf or a badge can be in:
 * on tonight, this weekend, next month, last week; plenty left, almost sold
 * out, a handful left, sold out, no limit. Without it the front page of a
 * fresh install is one empty shelf, and nobody can judge a design against
 * that.
 *
 * Development only, and on purpose loud about it. It invents organizers and
 * sales — tickets issued straight into the table, with no order or payment
 * behind them — which is the one thing a real database must never contain.
 * It refuses to run in production whatever it is asked, and DatabaseSeeder
 * calls it only when SEED_DEMO_EVENTS is set (config/discovery.php). No test
 * uses it.
 *
 * The posters are drawn here, from shapes and colour — never photographs from
 * the internet, which would be somebody else's work on our front page.
 *
 * Run again, it clears what it made last time (organizations whose slug
 * starts demo-) and starts over, so the dates stay relative to today.
 */
class DemoEventsSeeder extends Seeder
{
    private const PREFIX = 'demo-';

    private const BUYER = 'demo-buyer@example.com';

    /** Where each city is, as the event form would have it. */
    private const CITIES = [
        'Toronto' => ['country' => 'CA', 'subdivision' => 'ON', 'timezone' => 'America/Toronto', 'currency' => 'CAD'],
        'Montreal' => ['country' => 'CA', 'subdivision' => 'QC', 'timezone' => 'America/Toronto', 'currency' => 'CAD'],
        'Vancouver' => ['country' => 'CA', 'subdivision' => 'BC', 'timezone' => 'America/Vancouver', 'currency' => 'CAD'],
        'Lagos' => ['country' => 'NG', 'subdivision' => 'LA', 'timezone' => 'Africa/Lagos', 'currency' => 'NGN'],
        'Abuja' => ['country' => 'NG', 'subdivision' => 'FC', 'timezone' => 'Africa/Lagos', 'currency' => 'NGN'],
    ];

    public function run(): void
    {
        // Thrown rather than skipped: whoever asked for this in production
        // should find out it did not happen, not read a quiet success.
        if (app()->isProduction()) {
            throw new RuntimeException('DemoEventsSeeder invents organizers and sales, and does not run in production.');
        }

        $this->clear();

        $organizers = $this->organizers();
        $venues = $this->venues($organizers);

        foreach ($this->nights() as $index => $night) {
            $this->make($night, $organizers[$night['by']], $venues[$night['venue']], $index);
        }

        $this->command->info('Demo events seeded: '.count($this->nights()).' nights across '.count(self::CITIES).' cities.');
    }

    /** What the last run made, gone — tickets first, since a ticket holds its tier. */
    private function clear(): void
    {
        $organizations = Organization::withTrashed()->where('slug', 'like', self::PREFIX.'%')->pluck('id');

        if ($organizations->isEmpty()) {
            return;
        }

        $events = Event::withTrashed()->whereIn('organization_id', $organizations)->pluck('id');

        foreach (EventImage::query()->whereIn('event_id', $events)->get() as $image) {
            Storage::disk('public')->delete($image->paths());
        }

        DB::table('tickets')->whereIn('event_id', $events)->delete();
        DB::table('inventory_holds')->whereIn('ticket_type_id', TicketType::withTrashed()->whereIn('event_id', $events)->select('id'))->delete();
        Event::withTrashed()->whereIn('id', $events)->forceDelete();
        Venue::withTrashed()->whereIn('organization_id', $organizations)->forceDelete();
        Organization::withTrashed()->whereIn('id', $organizations)->forceDelete();
    }

    /** @return array<string, Organization> */
    private function organizers(): array
    {
        $made = [];

        foreach ([
            'northline' => ['Northline Sessions', 'Club nights and live sets in Toronto, since the warehouse days.', true],
            'plateau' => ['Plateau Social Club', 'Supper clubs, markets and late nights in Montreal.', true],
            'seawall' => ['Seawall Sound', 'Concerts and outdoor shows on the West Coast.', false],
            'eko' => ['Eko After Dark', 'Rooftops, beach parties and Afrobeats nights across Lagos.', true],
            'capital' => ['Capital Groove', 'Live music, comedy and culture in Abuja.', false],
            'laughs' => ['Punchline Tour', 'Stand-up comedy in both countries, with the funniest people we can find.', true],
        ] as $key => [$name, $about, $verified]) {
            $made[$key] = Organization::create([
                'name' => $name,
                'slug' => self::PREFIX.$key,
                'description' => $about,
                'verified_at' => $verified ? now() : null,
                'verified_name' => $verified ? $name : null,
            ]);
        }

        return $made;
    }

    /**
     * @param  array<string, Organization>  $organizers
     * @return array<string, Venue>
     */
    private function venues(array $organizers): array
    {
        $made = [];

        foreach ([
            'foundry' => ['northline', 'The Foundry Hall', '220 Sterling Road', 'Toronto'],
            'harbour' => ['northline', 'Harbourfront Loft', '8 Queens Quay West', 'Toronto'],
            'mile-end' => ['plateau', 'Mile End Studio', '5445 Avenue de Gaspé', 'Montreal'],
            'granville' => ['seawall', 'The Granville Room', '1100 Granville Street', 'Vancouver'],
            'sunset-park' => ['seawall', 'Sunset Beach Park', 'Beach Avenue', 'Vancouver'],
            'lekki' => ['eko', 'Lekki Arts Terrace', '12 Admiralty Way, Lekki Phase 1', 'Lagos'],
            'landmark' => ['eko', 'Oniru Beachfront', 'Water Corporation Drive, Victoria Island', 'Lagos'],
            'wuse' => ['capital', 'Wuse Garden Court', 'Adetokunbo Ademola Crescent, Wuse 2', 'Abuja'],
            'jabi' => ['capital', 'Jabi Lakeside Stage', 'Jabi Lake, Jabi', 'Abuja'],
            'comedy-to' => ['laughs', 'The Backroom', '416 Bathurst Street', 'Toronto'],
            'comedy-la' => ['laughs', 'Muson Hall', '8/9 Marina Road, Onikan', 'Lagos'],
        ] as $key => [$by, $name, $address, $city]) {
            $place = self::CITIES[$city];

            $made[$key] = Venue::create([
                'organization_id' => $organizers[$by]->id,
                'name' => $name,
                'address_line' => $address,
                'city' => $city,
                'subdivision' => $place['subdivision'],
                'country' => $place['country'],
                'timezone' => $place['timezone'],
            ]);
        }

        return $made;
    }

    /**
     * The nights. `when` is read in the city's own zone (at()); `tiers` are
     * [name, price in major units, capacity or null, how many sold]; `poster`
     * false leaves one without, so the site's own stand-in is seen too.
     *
     * @return list<array<string, mixed>>
     */
    private function nights(): array
    {
        return [
            // --- on tonight ---
            ['title' => 'Friday Heat: Amapiano & Afrobeats', 'category' => 'Nightlife', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'foundry', 'when' => 'tonight 22:00', 'hours' => 6, 'featured' => true,
                'tiers' => [['Early bird', 20, 150, 150], ['General admission', 30, 400, 372], ['VIP', 75, 40, 12]]],
            ['title' => 'Sunset Sessions on the Terrace', 'category' => 'Party', 'city' => 'Lagos', 'by' => 'eko', 'venue' => 'lekki', 'when' => 'tonight 19:00', 'hours' => 7, 'featured' => true,
                'tiers' => [['Regular', 10000, 300, 181], ['Table for six', 150000, 12, 9]]],
            ['title' => 'Open Mic: New Voices', 'category' => 'Comedy', 'city' => 'Toronto', 'by' => 'laughs', 'venue' => 'comedy-to', 'when' => 'tonight 20:00', 'hours' => 2,
                'tiers' => [['General', 12, 60, 60]]],
            ['title' => 'Makers Night Market', 'category' => 'Food & drink', 'city' => 'Montreal', 'by' => 'plateau', 'venue' => 'mile-end', 'when' => 'now -1h', 'hours' => 6,
                'tiers' => [['Entry', 0, null, 214]]],

            // --- this weekend ---
            ['title' => 'Afro Nation Warm-Up Beach Party', 'category' => 'Festival', 'city' => 'Lagos', 'by' => 'eko', 'venue' => 'landmark', 'when' => 'sat 16:00', 'hours' => 10, 'featured' => true,
                'tiers' => [['Early bird', 15000, 500, 500], ['Regular', 25000, 1200, 1142], ['VIP cabana', 250000, 20, 17]]],
            ['title' => 'Jazz by the Lake', 'category' => 'Concert', 'city' => 'Abuja', 'by' => 'capital', 'venue' => 'jabi', 'when' => 'sat 18:00', 'hours' => 4,
                'tiers' => [['Regular', 8000, 400, 131], ['Premium', 20000, 80, 26]]],
            ['title' => 'Late Night Comedy Special', 'category' => 'Comedy', 'city' => 'Lagos', 'by' => 'laughs', 'venue' => 'comedy-la', 'when' => 'sat 20:00', 'hours' => 3,
                'tiers' => [['Regular', 7500, 250, 250], ['Front row', 20000, 30, 30]]],
            ['title' => 'Vinyl Brunch: Soul & Disco', 'category' => 'Food & drink', 'city' => 'Vancouver', 'by' => 'seawall', 'venue' => 'granville', 'when' => 'sun 11:00', 'hours' => 4, 'poster' => false,
                'tiers' => [['Brunch seat', 45, 70, 64]]],
            ['title' => 'Harbour Lights Rooftop', 'category' => 'Nightlife', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'harbour', 'when' => 'sat 21:00', 'hours' => 5,
                'tiers' => [['General', 35, 250, 88]]],
            ['title' => 'Sunday Service: House Music Day Party', 'category' => 'Party', 'city' => 'Montreal', 'by' => 'plateau', 'venue' => 'mile-end', 'when' => 'sun 15:00', 'hours' => 7,
                'tiers' => [['General', 25, 300, 279]]],

            // --- coming up ---
            ['title' => 'Coastal Beats Festival', 'category' => 'Festival', 'city' => 'Vancouver', 'by' => 'seawall', 'venue' => 'sunset-park', 'when' => '+12 days 14:00', 'hours' => 9, 'featured' => true,
                'tiers' => [['Day pass', 89, 3000, 1410], ['Weekend pass', 149, 1500, 1391], ['VIP', 299, 150, 44]]],
            ['title' => 'Highlife Revival: Live Band Night', 'category' => 'Music', 'city' => 'Abuja', 'by' => 'capital', 'venue' => 'wuse', 'when' => '+6 days 19:00', 'hours' => 4,
                'tiers' => [['Regular', 6000, 350, 72], ['Couples', 10000, 60, 58]]],
            ['title' => 'Sound Bath & Breathwork', 'category' => 'Classes & workshops', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'harbour', 'when' => '+4 days 18:30', 'hours' => 2,
                'tiers' => [['Mat', 30, 8, 0]]],
            ['title' => 'Founders & Friends Mixer', 'category' => 'Networking & business', 'city' => 'Lagos', 'by' => 'eko', 'venue' => 'lekki', 'when' => '+9 days 18:00', 'hours' => 3, 'poster' => false,
                'tiers' => [['Guest', 5000, null, 96]]],
            ['title' => 'Cinq à Sept Wine Tasting', 'category' => 'Food & drink', 'city' => 'Montreal', 'by' => 'plateau', 'venue' => 'mile-end', 'when' => '+8 days 17:00', 'hours' => 2,
                'tiers' => [['Tasting', 55, 40, 34]]],
            ['title' => 'Owambe Night: Dress to Impress', 'category' => 'Community & culture', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'foundry', 'when' => '+15 days 20:00', 'hours' => 6,
                'tiers' => [['General', 40, 500, 188], ['VIP table', 400, 25, 11]]],
            ['title' => 'Stand-Up Showcase: The Tour Finale', 'category' => 'Comedy', 'city' => 'Abuja', 'by' => 'laughs', 'venue' => 'wuse', 'when' => '+18 days 19:30', 'hours' => 3,
                'tiers' => [['Regular', 10000, 300, 61]]],
            ['title' => 'Five-a-Side Charity Cup', 'category' => 'Sports', 'city' => 'Vancouver', 'by' => 'seawall', 'venue' => 'sunset-park', 'when' => '+20 days 10:00', 'hours' => 7,
                'tiers' => [['Spectator', 10, null, 40], ['Team entry', 120, 16, 13]]],
            ['title' => 'Afrobeats Dance Workshop', 'category' => 'Classes & workshops', 'city' => 'Lagos', 'by' => 'eko', 'venue' => 'lekki', 'when' => '+5 days 17:00', 'hours' => 2,
                'tiers' => [['Class', 7000, 40, 40]]],
            ['title' => 'Midnight Techno: Warehouse Edition', 'category' => 'Nightlife', 'city' => 'Montreal', 'by' => 'plateau', 'venue' => 'mile-end', 'when' => '+11 days 23:00', 'hours' => 6,
                'tiers' => [['Early bird', 20, 100, 100], ['General', 30, 350, 120]]],
            ['title' => 'Spoken Word & Strings', 'category' => 'Performing arts', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'harbour', 'when' => '+25 days 19:30', 'hours' => 3,
                'tiers' => [['General', 28, 180, 23]]],
            ['title' => 'Naija Food Festival', 'category' => 'Festival', 'city' => 'Abuja', 'by' => 'capital', 'venue' => 'jabi', 'when' => '+32 days 12:00', 'hours' => 8,
                'tiers' => [['Day pass', 5000, 2000, 310]]],
            ['title' => 'Orchestra Under the Stars', 'category' => 'Concert', 'city' => 'Vancouver', 'by' => 'seawall', 'venue' => 'granville', 'when' => '+40 days 20:00', 'hours' => 3,
                'tiers' => [['Balcony', 65, 200, 70], ['Orchestra', 95, 300, 101]]],

            // --- already happened ---
            ['title' => 'Summer Closing Party', 'category' => 'Party', 'city' => 'Toronto', 'by' => 'northline', 'venue' => 'foundry', 'when' => '-6 days 22:00', 'hours' => 5,
                'tiers' => [['General', 30, 450, 450]]],
            ['title' => 'Lagos Laughs Live', 'category' => 'Comedy', 'city' => 'Lagos', 'by' => 'laughs', 'venue' => 'comedy-la', 'when' => '-12 days 19:00', 'hours' => 3,
                'tiers' => [['Regular', 7500, 400, 356]]],
            ['title' => 'Harvest Supper Club', 'category' => 'Food & drink', 'city' => 'Montreal', 'by' => 'plateau', 'venue' => 'mile-end', 'when' => '-20 days 18:30', 'hours' => 3,
                'tiers' => [['Seat', 85, 48, 48]]],
            ['title' => 'Abuja Art Walk', 'category' => 'Community & culture', 'city' => 'Abuja', 'by' => 'capital', 'venue' => 'wuse', 'when' => '-9 days 15:00', 'hours' => 4,
                'tiers' => [['Walk', 3000, null, 140]]],
        ];
    }

    /** @param  array<string, mixed>  $night */
    private function make(array $night, Organization $by, Venue $venue, int $index): void
    {
        $place = self::CITIES[$night['city']];
        $starts = $this->at($night['when'], $place['timezone']);
        // Cents and kobo alike: prices below are in dollars and naira.
        $minor = 100;

        $event = Event::create([
            'organization_id' => $by->id,
            'venue_id' => $venue->id,
            'slug' => $this->slug($night['title'], $night['city']),
            'title' => $night['title'],
            'description' => $this->about($night),
            'category' => $night['category'],
            'currency' => $place['currency'],
            'starts_at' => $starts,
            'ends_at' => $starts->addHours((int) $night['hours']),
            'timezone' => $place['timezone'],
            'city' => $night['city'],
            'subdivision' => $place['subdivision'],
            'country' => $place['country'],
            'status' => 'published',
            'published_at' => now()->subDays(20),
            'is_featured' => (bool) ($night['featured'] ?? false),
            'min_age' => in_array($night['category'], ['Nightlife', 'Party'], true) ? 19 : null,
            // On sale means looked at first (EventReviews), so each reads as
            // approved the day it went on sale — where the column exists.
            ...(Schema::hasColumn('events', 'approved_at') ? ['approved_at' => now()->subDays(20)] : []),
        ]);

        foreach ($night['tiers'] as $order => [$name, $price, $capacity, $sold]) {
            $type = TicketType::create([
                'event_id' => $event->id,
                'name' => $name,
                'price_amount' => $price * $minor,
                'quantity_available' => $capacity,
                'status' => 'on_sale',
                'sort_order' => $order,
                'max_per_order' => 10,
            ]);

            $this->issue($type, (int) $sold);
        }

        if ($night['poster'] ?? true) {
            $this->poster($event, $night['category'], $index);
        }
    }

    /**
     * A time in the city's own zone: "tonight 22:00", "now -1h", "sat 16:00"
     * (this weekend's, or next week's once it has gone), "+12 days 14:00",
     * "-6 days 22:00".
     */
    private function at(string $when, string $zone): CarbonImmutable
    {
        $now = CarbonImmutable::now($zone);

        if ($when === 'now -1h') {
            return $now->subHour()->startOfHour()->utc();
        }

        [$day, $time] = explode(' ', str_replace([' days', ' day'], 'd', $when)) + [1 => '20:00'];
        [$hour, $minute] = array_map('intval', explode(':', $time));

        $date = match (true) {
            $day === 'tonight' => $now->setTime($hour, $minute),
            in_array($day, ['fri', 'sat', 'sun'], true) => $now
                ->addDays(5 - $now->isoWeekday())
                ->addDays(['fri' => 0, 'sat' => 1, 'sun' => 2][$day])
                ->setTime($hour, $minute),
            default => $now->addDays((int) rtrim($day, 'd'))->setTime($hour, $minute),
        };

        // Tonight's already started: an hour from now instead. A weekend day
        // that has gone: the same day next week.
        if ($day === 'tonight' && $date->lessThan($now->addMinutes(30))) {
            $date = $now->addHour()->startOfHour();
        } elseif (in_array($day, ['fri', 'sat', 'sun'], true) && $date->lessThan($now)) {
            $date = $date->addWeek();
        }

        return $date->utc();
    }

    private function slug(string $title, string $city): string
    {
        $base = Str::slug($title.' '.$city);

        return Event::slugIsTaken($base, true) ? $base.'-'.Str::lower(Str::random(4)) : $base;
    }

    /** @param  array<string, mixed>  $night */
    private function about(array $night): string
    {
        return '<p>'.e($night['title']).' — '.strtolower($night['category']).' in '.e($night['city']).'.</p>'
            .'<p>Doors open at the time on your ticket. Bring the QR code on your phone; it works without signal at the door.</p>'
            .'<ul><li>Photo ID may be requested</li><li>Tickets are transferable up to the day</li></ul>';
    }

    /** Tickets straight into the table, in batches: a sale with no order behind it, which is why this is dev only. */
    private function issue(TicketType $type, int $sold): void
    {
        foreach (array_chunk(range(1, max(0, $sold)), 500) as $batch) {
            if ($sold === 0) {
                break;
            }

            DB::table('tickets')->insert(array_map(fn () => [
                'id' => (string) Str::uuid(),
                'event_id' => $type->event_id,
                'ticket_type_id' => $type->id,
                'owner_email' => self::BUYER,
                'code' => strtoupper(Str::random(20)),
                'status' => 'valid',
                'created_at' => now(),
                'updated_at' => now(),
            ], $batch));
        }
    }

    // --- the posters ---------------------------------------------------------

    /** Drawn, saved through the same store an upload goes through, so every rendition exists. */
    private function poster(Event $event, string $category, int $seed): void
    {
        $path = tempnam(sys_get_temp_dir(), 'poster').'.jpg';

        try {
            imagejpeg($this->paint($category, $seed), $path, 90);

            app(ImageStore::class)->store($event, new UploadedFile($path, 'poster.jpg', 'image/jpeg', null, true), 'banner');
        } finally {
            @unlink($path);
        }
    }

    /** @var array<string, array{0: array{int,int,int}, 1: array{int,int,int}, 2: array{int,int,int}}> */
    private const PALETTES = [
        'Nightlife' => [[18, 8, 48], [120, 20, 140], [255, 70, 160]],
        'Party' => [[255, 94, 58], [255, 42, 120], [255, 214, 64]],
        'Music' => [[8, 40, 60], [16, 140, 130], [250, 200, 80]],
        'Concert' => [[10, 10, 28], [70, 30, 160], [80, 200, 255]],
        'Festival' => [[255, 140, 30], [230, 50, 70], [255, 230, 120]],
        'Comedy' => [[30, 14, 60], [245, 180, 30], [255, 90, 60]],
        'Performing arts' => [[60, 6, 20], [170, 20, 50], [250, 200, 140]],
        'Food & drink' => [[80, 30, 10], [220, 110, 40], [250, 220, 150]],
        'Community & culture' => [[6, 70, 50], [14, 117, 59], [208, 203, 41]],
        'Classes & workshops' => [[20, 40, 90], [40, 120, 200], [240, 240, 255]],
        'Networking & business' => [[14, 20, 30], [30, 70, 110], [120, 200, 230]],
        'Sports' => [[10, 60, 30], [30, 160, 80], [240, 250, 120]],
    ];

    /**
     * Soft colour fields with one motif on top, by category: beams for a club
     * night, rings for music, rays for a festival, confetti for a party.
     */
    private function paint(string $category, int $seed): GdImage
    {
        mt_srand(crc32($category) + $seed * 7919);

        [$deep, $mid, $light] = self::PALETTES[$category] ?? self::PALETTES['Music'];
        $width = 1600;
        $height = 900;

        $image = imagecreatetruecolor($width, $height) ?: throw new RuntimeException('GD could not make an image.');
        $this->gradient($image, $deep, $mid, $width, $height);
        imagealphablending($image, true);

        // Soft pools of colour: each a stack of faint discs, so the edge fades
        // rather than stops.
        foreach (range(1, 6) as $_) {
            $this->glow($image, [$mid, $light, $deep][mt_rand(0, 2)], mt_rand(0, $width), mt_rand(0, $height), mt_rand(260, 620));
        }

        match ($category) {
            'Nightlife', 'Concert' => $this->beams($image, $light, $width, $height),
            'Music', 'Performing arts' => $this->rings($image, $light, $width, $height),
            'Festival', 'Food & drink' => $this->rays($image, $light, $width, $height),
            'Party', 'Comedy' => $this->confetti($image, [$light, $mid, [255, 255, 255]], $width, $height),
            default => $this->dots($image, $light, $width, $height),
        };

        // A little grain, so the fields do not band.
        foreach (range(1, 9000) as $_) {
            $tone = mt_rand(0, 1) ? 255 : 0;
            imagesetpixel($image, mt_rand(0, $width - 1), mt_rand(0, $height - 1), (int) imagecolorallocatealpha($image, $tone, $tone, $tone, 110));
        }

        return $image;
    }

    /**
     * @param  array{int,int,int}  $from
     * @param  array{int,int,int}  $to
     */
    private function gradient(GdImage $image, array $from, array $to, int $width, int $height): void
    {
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $colour = imagecolorallocate(
                $image,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t),
            );
            imageline($image, 0, $y, $width, $y, (int) $colour);
        }
    }

    /** @param  array{int,int,int}  $colour */
    private function glow(GdImage $image, array $colour, int $x, int $y, int $radius): void
    {
        $steps = 34;

        for ($step = $steps; $step >= 1; $step--) {
            $fill = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], 122);
            $size = (int) ($radius * 2 * $step / $steps);
            imagefilledellipse($image, $x, $y, $size, $size, (int) $fill);
        }
    }

    /** @param  array{int,int,int}  $colour */
    private function beams(GdImage $image, array $colour, int $width, int $height): void
    {
        foreach (range(1, 6) as $_) {
            $foot = mt_rand((int) ($width * 0.2), (int) ($width * 0.8));
            $top = mt_rand(-400, $width + 400);
            $spread = mt_rand(60, 180);
            $fill = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], mt_rand(92, 108));
            imagefilledpolygon($image, [$foot - 12, $height, $foot + 12, $height, $top + $spread, 0, $top - $spread, 0], (int) $fill);
        }
    }

    /** @param  array{int,int,int}  $colour */
    private function rings(GdImage $image, array $colour, int $width, int $height): void
    {
        $cx = mt_rand((int) ($width * 0.3), (int) ($width * 0.7));
        $cy = mt_rand((int) ($height * 0.3), (int) ($height * 0.7));

        // GD draws an ellipse one pixel wide whatever the thickness, so each
        // ring is several, side by side.
        foreach (range(1, 14) as $ring) {
            $line = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], min(118, 30 + $ring * 6));

            foreach (range(0, 5) as $offset) {
                imageellipse($image, $cx, $cy, $ring * 110 + $offset, $ring * 110 + $offset, (int) $line);
            }
        }
    }

    /** @param  array{int,int,int}  $colour */
    private function rays(GdImage $image, array $colour, int $width, int $height): void
    {
        $cx = (int) ($width / 2);
        $cy = (int) ($height * 0.72);

        foreach (range(0, 17) as $ray) {
            $angle = deg2rad(180 + $ray * 10);
            $next = deg2rad(180 + $ray * 10 + 5);
            $fill = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], 100);
            imagefilledpolygon($image, [
                $cx, $cy,
                (int) ($cx + cos($angle) * 2000), (int) ($cy + sin($angle) * 2000),
                (int) ($cx + cos($next) * 2000), (int) ($cy + sin($next) * 2000),
            ], (int) $fill);
        }

        $sun = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], 30);
        imagefilledellipse($image, $cx, $cy, 360, 360, (int) $sun);
    }

    /** @param  list<array{int,int,int}>  $colours */
    private function confetti(GdImage $image, array $colours, int $width, int $height): void
    {
        foreach (range(1, 160) as $_) {
            $colour = $colours[mt_rand(0, count($colours) - 1)];
            $fill = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], mt_rand(10, 50));
            $x = mt_rand(0, $width);
            $y = mt_rand(0, $height);
            $size = mt_rand(8, 26);

            if (mt_rand(0, 1)) {
                imagefilledellipse($image, $x, $y, $size, $size, (int) $fill);
            } else {
                $turn = mt_rand(0, 90);
                $points = [];

                foreach ([0, 90, 180, 270] as $corner) {
                    $a = deg2rad($turn + $corner);
                    $points[] = (int) ($x + cos($a) * $size);
                    $points[] = (int) ($y + sin($a) * $size * 0.45);
                }

                imagefilledpolygon($image, $points, (int) $fill);
            }
        }
    }

    /** @param  array{int,int,int}  $colour */
    private function dots(GdImage $image, array $colour, int $width, int $height): void
    {
        for ($y = 60; $y < $height; $y += 70) {
            for ($x = 60; $x < $width; $x += 70) {
                $near = 1 - min(1, hypot($x - $width * 0.7, $y - $height * 0.4) / 900);
                $fill = imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], (int) (120 - 90 * $near));
                imagefilledellipse($image, $x, $y, (int) (6 + 18 * $near), (int) (6 + 18 * $near), (int) $fill);
            }
        }
    }
}
