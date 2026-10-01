<?php

namespace Tests\Unit;

use App\Services\Organizations\Socials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where else to find an organizer, read the same way whoever typed it.
 *
 * The legacy importer carried across whatever the old platform held — whole
 * addresses, @handles, links with tracking on the end — and the console takes
 * whichever an organizer has to hand. Each is read as the part that names the
 * account, and anything else is left off rather than linked to.
 */
class SocialsTest extends TestCase
{
    /** @return array<string, array{string, string, ?string}> */
    public static function readable(): array
    {
        return [
            'an instagram username' => ['instagram', 'lagos.nights', 'lagos.nights'],
            'the same with its @' => ['instagram', '@lagos_nights', 'lagos_nights'],
            'an instagram address' => ['instagram', 'https://www.instagram.com/lagosnights/', 'lagosnights'],
            'without its https' => ['instagram', 'instagram.com/lagosnights', 'lagosnights'],
            'with tracking on the end' => ['instagram', 'https://instagram.com/lagosnights?igsh=MWx0eTZ', 'lagosnights'],
            'the app link' => ['instagram', 'https://www.instagram.com/_u/lagosnights', 'lagosnights'],
            'old http' => ['instagram', 'http://instagram.com/lagosnights', 'lagosnights'],
            'spaces round it' => ['instagram', '  @lagosnights  ', 'lagosnights'],
            'an x username' => ['x', 'LagosNights', 'LagosNights'],
            'a twitter address' => ['x', 'https://twitter.com/LagosNights', 'LagosNights'],
            'an x address' => ['x', 'x.com/LagosNights?s=21', 'LagosNights'],
            'a mobile twitter address' => ['x', 'https://mobile.twitter.com/LagosNights', 'LagosNights'],
            'a tiktok username' => ['tiktok', '@lagos.nights', 'lagos.nights'],
            'a tiktok address' => ['tiktok', 'https://www.tiktok.com/@lagos.nights?lang=en', 'lagos.nights'],
            'a facebook page name' => ['facebook', 'lagosnightsto', 'lagosnightsto'],
            'a facebook address' => ['facebook', 'https://www.facebook.com/lagosnightsto/', 'lagosnightsto'],
            'a mobile facebook address' => ['facebook', 'm.facebook.com/lagosnightsto', 'lagosnightsto'],
            'a page with an old hyphenated name' => ['facebook', 'facebook.com/lagos-nights-toronto', 'lagos-nights-toronto'],
            'a page with no name' => ['facebook', 'https://facebook.com/profile.php?id=100064&mibextid=abc', 'https://www.facebook.com/profile.php?id=100064'],
            'an old /pages/ address' => ['facebook', 'https://www.facebook.com/pages/Lagos-Nights/123456', 'https://www.facebook.com/pages/Lagos-Nights/123456'],
            'a page named with accents' => ['facebook', 'https://www.facebook.com/pages/Café-Montréal/123456789', 'https://www.facebook.com/pages/Caf%C3%A9-Montr%C3%A9al/123456789'],
            'the same as a browser copies it' => ['facebook', 'https://www.facebook.com/pages/Caf%C3%A9-Montr%C3%A9al/123456789', 'https://www.facebook.com/pages/Caf%C3%A9-Montr%C3%A9al/123456789'],
            'a /people/ address with a space' => ['facebook', 'https://www.facebook.com/people/Lagos%20Nights/100089123456789/', 'https://www.facebook.com/people/Lagos%20Nights/100089123456789'],
            'a page with no username today' => ['facebook', 'https://www.facebook.com/p/Lagos-Nights-100063/', 'https://www.facebook.com/p/Lagos-Nights-100063'],
            'the same with tracking on the end' => ['facebook', 'facebook.com/p/Soirées-Montréal-100063/?mibextid=abc', 'https://www.facebook.com/p/Soir%C3%A9es-Montr%C3%A9al-100063'],
            'a website' => ['website', 'https://lagosnights.com', 'https://lagosnights.com'],
            'a website without its https' => ['website', 'lagosnights.com', 'https://lagosnights.com'],
            'a website page' => ['website', 'https://www.lagosnights.com/about?ref=bio', 'https://www.lagosnights.com/about?ref=bio'],
        ];
    }

    #[DataProvider('readable')]
    public function test_it_reads_what_people_type(string $network, string $typed, ?string $kept): void
    {
        $this->assertSame($kept, Socials::normalise($network, $typed));
    }

    /**
     * What is kept is read again every time a page is shown, so it has to
     * read back as itself, or a link saved today is gone tomorrow.
     */
    #[DataProvider('readable')]
    public function test_what_is_kept_reads_back_as_itself(string $network, string $typed, ?string $kept): void
    {
        $this->assertSame($kept, Socials::normalise($network, Socials::normalise($network, $typed)));
    }

    /** @return array<string, array{string, mixed}> */
    public static function unreadable(): array
    {
        return [
            'nothing' => ['instagram', ''],
            'not a string' => ['instagram', 42],
            'null' => ['x', null],
            'a placeholder' => ['instagram', 'N/A'],
            'another placeholder' => ['x', 'nil'],
            'too long for instagram' => ['instagram', str_repeat('a', 31)],
            'a hyphen on instagram' => ['instagram', 'lagos-nights'],
            'too long for x' => ['x', 'LagosNightsToronto'],
            'too short for tiktok' => ['tiktok', 'a'],
            'an instagram post' => ['instagram', 'https://www.instagram.com/p/C1abcdEf/'],
            'an x share button' => ['x', 'https://twitter.com/intent/tweet?text=hi'],
            'a tiktok video link' => ['tiktok', 'https://vm.tiktok.com/ZMabcdef/'],
            'a facebook post' => ['facebook', 'https://www.facebook.com/share/p/abc123/'],
            'a facebook event' => ['facebook', 'https://www.facebook.com/events/123456/'],
            'a /p/ with nothing after it' => ['facebook', 'https://www.facebook.com/p/'],
            'a slash hidden in a page name' => ['facebook', 'https://www.facebook.com/pages/Lagos%2FNights/123456'],
            'a page name that is not letters' => ['facebook', 'https://www.facebook.com/pages/%3Cscript%3E/123456'],
            'too long for its column once encoded' => ['facebook', 'https://www.facebook.com/pages/'.str_repeat('é', 60).'/123456'],
            'too long for its column with the www put back' => ['facebook', 'facebook.com/pages/'.str_repeat('a', 100).'/'.str_repeat('b', 100).'/'.str_repeat('c', 34)],
            'an address on another site' => ['instagram', 'https://evil.example/lagosnights'],
            'a lookalike host' => ['x', 'https://x.com.evil.example/lagosnights'],
            'a host that only ends the same' => ['instagram', 'https://notinstagram.com/lagosnights'],
            'a script address' => ['website', 'javascript:alert(1)'],
            'plain http' => ['website', 'http://lagosnights.com'],
            'a password in front' => ['website', 'https://user:pass@lagosnights.com'],
            'no dot in the name' => ['website', 'https://localhost/'],
            'spaces inside' => ['website', 'https://lagos nights.com'],
            'an unknown network' => ['myspace', 'lagosnights'],
        ];
    }

    #[DataProvider('unreadable')]
    public function test_what_cannot_be_read_as_an_account_is_left_off(string $network, mixed $typed): void
    {
        $this->assertNull(Socials::normalise($network, $typed));
    }
}
