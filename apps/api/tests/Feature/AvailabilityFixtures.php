<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nights, tiers and sales, made quickly, for the availability and discovery
 * tests.
 *
 * Tickets go in with one insert rather than a model each: a tier "90 of 100
 * sold" is ninety rows, and the rule being tested is about counting them, not
 * about how they were made.
 */
trait AvailabilityFixtures
{
    private ?Organization $host = null;

    private function host(): Organization
    {
        return $this->host ??= Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights-'.Str::lower(Str::random(6))]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function night(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'organization_id' => $this->host()->id,
            'slug' => 'e-'.Str::lower(Str::random(10)),
            'title' => 'A Night',
            'currency' => 'CAD',
            'starts_at' => now()->addWeeks(2),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'category' => 'Music',
            'status' => 'published',
            'published_at' => now(),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function tier(Event $event, ?int $capacity, array $overrides = []): TicketType
    {
        return TicketType::create(array_merge([
            'event_id' => $event->id,
            'name' => 'General',
            'price_amount' => 2500,
            'status' => 'on_sale',
            'quantity_available' => $capacity,
        ], $overrides));
    }

    /** Tickets issued against a tier, in whatever state. */
    private function sell(TicketType $type, int $count, string $status = 'valid'): void
    {
        if ($count === 0) {
            return;
        }

        DB::table('tickets')->insert(array_map(fn () => [
            'id' => (string) Str::uuid(),
            'event_id' => $type->event_id,
            'ticket_type_id' => $type->id,
            'owner_email' => 'buyer@example.com',
            'code' => strtoupper(Str::random(16)),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, $count)));
    }

    /** A basket in progress: places held, live or already run out. */
    private function hold(TicketType $type, int $quantity, bool $live = true): void
    {
        InventoryHold::create([
            'ticket_type_id' => $type->id,
            'quantity' => $quantity,
            'expires_at' => $live ? now()->addMinutes(10) : now()->subMinute(),
        ]);
    }

    /** A banner row, as ImageStore leaves one. No file is needed for its URL. */
    private function poster(Event $event): EventImage
    {
        $stem = Str::lower(Str::random(8));

        return EventImage::create([
            'event_id' => $event->id,
            'kind' => 'banner',
            'path' => "events/{$event->id}/{$stem}.jpg",
            'renditions' => ['display' => "events/{$event->id}/{$stem}-display.jpg"],
            'width' => 1600,
            'height' => 900,
            'byte_size' => 1000,
            'mime' => 'image/jpeg',
            'position' => 0,
        ]);
    }
}
