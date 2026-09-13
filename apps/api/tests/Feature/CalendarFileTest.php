<?php

namespace Tests\Feature;

use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Venue;
use App\Services\Events\CalendarFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Add to calendar": the .ics a buyer downloads or gets attached to their tickets.
 */
class CalendarFileTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.public_url' => 'https://myfiesta.test']);

        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $venue = Venue::create([
            'organization_id' => $org->id,
            'name' => 'The Opera House',
            'address_line' => '735 Queen St E',
            'city' => 'Toronto',
            'country' => 'CA',
            'timezone' => 'America/Toronto',
        ]);

        $this->event = Event::create([
            'organization_id' => $org->id,
            'venue_id' => $venue->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest; Lagos, Toronto & more',
            'description' => '<p>A night of <strong>highlife</strong>.</p>',
            'currency' => 'CAD',
            // 10pm in Toronto, in daylight time: 02:00 UTC the next day.
            'starts_at' => Carbon::parse('2026-10-03 22:00', 'America/Toronto'),
            'ends_at' => Carbon::parse('2026-10-04 03:00', 'America/Toronto'),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    private function unfold(string $ics): string
    {
        return str_replace("\r\n ", '', $ics);
    }

    public function test_a_published_event_downloads_as_a_calendar_file(): void
    {
        $response = $this->get('/api/events/afro-fest/calendar.ics')->assertOk();

        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="afro-fest-lagos-toronto-more.ics"', $response->headers->get('Content-Disposition'));

        $ics = $this->unfold($response->getContent());

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("UID:{$this->event->id}@myfiesta\r\n", $ics);
        // UTC, converted from the event's own zone.
        $this->assertStringContainsString("DTSTART:20261004T020000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:20261004T070000Z\r\n", $ics);
        // Commas and semicolons are separators in iCalendar and must be escaped.
        $this->assertStringContainsString('SUMMARY:Afro Fest\; Lagos\, Toronto & more', $ics);
        $this->assertStringContainsString('LOCATION:The Opera House\, 735 Queen St E\, Toronto', $ics);
        // Text, not markup.
        $this->assertStringContainsString('DESCRIPTION:A night of highlife.\n\nTickets: https://myfiesta.test/afro-fest', $ics);
        $this->assertStringNotContainsString('<strong>', $ics);
        $this->assertStringContainsString("STATUS:CONFIRMED\r\n", $ics);
    }

    public function test_no_line_is_longer_than_the_format_allows(): void
    {
        $this->event->update([
            'title' => str_repeat('Ọjọ́ ìbí àti àríyá 🎉 ', 12),
            'description' => '<p>'.str_repeat('Ẹ káàbọ̀ sí ayẹyẹ wa. ', 40).'</p>',
        ]);

        $ics = app(CalendarFile::class)->for($this->event->fresh());

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            // Never cut inside a character.
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), "Broken UTF-8 in: {$line}");
        }

        $this->assertStringContainsString('SUMMARY:'.str_repeat('Ọjọ́ ìbí àti àríyá 🎉 ', 12), $this->unfold($ics));
    }

    public function test_an_event_with_no_end_gets_a_visible_length(): void
    {
        $this->event->update(['ends_at' => null]);

        $ics = app(CalendarFile::class)->for($this->event->fresh());

        $this->assertStringContainsString("DTEND:20261004T050000Z\r\n", $ics);
    }

    public function test_a_cancelled_event_says_so_and_drafts_and_invitations_stay_hidden(): void
    {
        $this->event->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        $this->assertStringContainsString('STATUS:CANCELLED', $this->get('/api/events/afro-fest/calendar.ics')->assertOk()->getContent());

        $this->event->update(['status' => 'draft', 'cancelled_at' => null]);
        $this->get('/api/events/afro-fest/calendar.ics')->assertNotFound();

        $this->event->update(['status' => 'published', 'kind' => 'invitation']);
        $this->get('/api/events/afro-fest/calendar.ics')->assertNotFound();
    }

    public function test_the_event_page_offers_both_calendar_links(): void
    {
        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.calendar.ics_url', url('/api/events/afro-fest/calendar.ics'))
            ->assertJsonPath('data.calendar.google_url', fn (string $link) => str_starts_with($link, 'https://calendar.google.com/calendar/render?')
                && str_contains($link, 'dates=20261004T020000Z%2F20261004T070000Z'));
    }

    public function test_the_tickets_email_carries_the_calendar_file(): void
    {
        $order = new Order(['reference' => 'ABC123', 'buyer_name' => 'Ada', 'access_token' => 'token']);
        $order->setRelation('event', $this->event);
        $order->setRelation('tickets', collect());

        // The file is stamped with the time it was made.
        $this->freezeTime();
        $mail = new TicketsIssued($order);

        $mail->assertHasAttachedData(app(CalendarFile::class)->for($this->event), 'afro-fest-lagos-toronto-more.ics', ['mime' => 'text/calendar']);
    }
}
