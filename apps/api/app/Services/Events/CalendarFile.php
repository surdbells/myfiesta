<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Support\RichText;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * An event as an iCalendar file, for "add to calendar".
 *
 * Buyers had a date in an email and nothing to put in a calendar, which is how
 * a ticket bought three weeks out gets forgotten. One .ics works for Apple
 * Calendar, Outlook and Google alike, and Gmail offers to add it when it
 * arrives as an attachment.
 *
 * Times are written in UTC. Every calendar converts UTC correctly; a TZID
 * would need a VTIMEZONE block describing the zone's rules, and a hand-written
 * one is how an event moves by an hour across a daylight-saving change.
 */
class CalendarFile
{
    /** Without an end, calendars draw a zero-length event nobody can see. */
    public const DEFAULT_HOURS = 3;

    public function for(Event $event): string
    {
        $event->loadMissing('venue');

        $starts = $event->starts_at;
        $ends = $event->ends_at ?? $starts->copy()->addHours(self::DEFAULT_HOURS);
        $url = $this->url($event);

        $location = collect([
            $event->venue?->name,
            $event->venue?->address_line,
            $event->venue?->city ?? $event->city,
        ])->filter()->unique()->implode(', ');

        $about = Str::limit((string) RichText::toText($event->description), 600);
        $description = trim(($about !== '' ? $about."\n\n" : '').'Tickets: '.$url);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//myFiesta//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            // Stable per event, so adding it twice updates rather than duplicates.
            'UID:'.$event->id.'@myfiesta',
            'DTSTAMP:'.$this->utc(now()),
            'DTSTART:'.$this->utc($starts),
            'DTEND:'.$this->utc($ends),
            'SUMMARY:'.$this->escape($event->title),
            ...($location !== '' ? ['LOCATION:'.$this->escape($location)] : []),
            'DESCRIPTION:'.$this->escape($description),
            'URL:'.$url,
            // Cancelled events stay addable, and say so: somebody who added it
            // earlier and re-downloads sees the change in their calendar.
            'STATUS:'.($event->status === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    public function filename(Event $event): string
    {
        return (Str::slug($event->title) ?: 'event').'.ics';
    }

    /**
     * Both ways in, for any page that shows the event.
     *
     * @return array{ics_url: string, google_url: string}
     */
    public function links(Event $event): array
    {
        return [
            'ics_url' => url("/api/events/{$event->slug}/calendar.ics"),
            'google_url' => $this->googleLink($event),
        ];
    }

    /** "Add to Google Calendar", which takes a link rather than a file. */
    public function googleLink(Event $event): string
    {
        $ends = $event->ends_at ?? $event->starts_at->copy()->addHours(self::DEFAULT_HOURS);

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => $this->utc($event->starts_at).'/'.$this->utc($ends),
            'location' => $event->venue?->name ?? $event->city,
            'details' => 'Tickets: '.$this->url($event),
        ]);
    }

    private function url(Event $event): string
    {
        return rtrim((string) config('app.public_url'), '/').'/'.$event->slug;
    }

    private function utc(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545 text: backslash, semicolon, comma and newlines are escaped. */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $text,
        );
    }

    /**
     * Lines longer than 75 octets continue on the next with a leading space.
     *
     * Cut on octets, not characters, and never inside a multibyte character —
     * a title in Yoruba or with an emoji split mid-character is a file Outlook
     * refuses to open.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $parts[] = $current;
                $current = '';
                // Continuation lines carry the leading space in their 75.
                $limit = 74;
            }

            $current .= $character;
        }

        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
