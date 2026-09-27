<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Making a gallery picture the banner.
 *
 * Organizers put the flyer in the gallery constantly — it is the larger drop
 * target — and until now the only way back was to delete it, find the file
 * again and upload it a second time.
 *
 * The interesting part is the partial unique index: one banner per event. A
 * promotion has to demote the sitting banner in the same breath or the write
 * is refused by the database.
 */
class PromoteBannerTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $org->id,
            'slug' => 'afro-fest',
            'title' => 'Afro Fest',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $user = User::factory()->create();

        $org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    private function image(string $kind, int $position = 0): EventImage
    {
        return EventImage::create([
            'event_id' => $this->event->id,
            'kind' => $kind,
            'path' => 'events/'.Str::random(8).'.jpg',
            'position' => $position,
        ]);
    }

    private function promote(EventImage $image): TestResponse
    {
        return $this->patchJson(
            "/api/organizer/events/{$this->event->id}/images/{$image->id}",
            ['kind' => 'banner'],
        );
    }

    public function test_a_gallery_picture_becomes_the_banner_and_the_old_one_steps_down(): void
    {
        $old = $this->image('banner');
        $new = $this->image('gallery', 1);

        $this->promote($new)->assertOk()->assertJsonPath('kind', 'banner');

        // Both halves, because the unique index means only one of them can be
        // true and a test that checks one would pass on a broken swap.
        $this->assertSame('banner', $new->refresh()->kind);
        $this->assertSame('gallery', $old->refresh()->kind);
    }

    public function test_promoting_when_there_is_no_banner_simply_works(): void
    {
        $only = $this->image('gallery');

        $this->promote($only)->assertOk();

        $this->assertSame('banner', $only->refresh()->kind);
    }

    public function test_promoting_the_banner_that_is_already_the_banner_changes_nothing(): void
    {
        $banner = $this->image('banner');

        $this->promote($banner)->assertOk();

        $this->assertSame('banner', $banner->refresh()->kind);
        $this->assertSame(1, $this->event->images()->where('kind', 'banner')->count());
    }

    public function test_a_caption_can_still_be_set_without_touching_the_kind(): void
    {
        $image = $this->image('gallery');

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/images/{$image->id}",
            ['caption' => 'Last April'],
        )->assertOk();

        // `kind` is now optional on this endpoint. Sending only a caption must
        // not quietly demote or promote anything.
        $this->assertSame('Last April', $image->refresh()->caption);
        $this->assertSame('gallery', $image->kind);
    }

    public function test_an_image_from_another_event_is_not_reachable(): void
    {
        $other = Event::create([
            'organization_id' => $this->event->organization_id,
            'slug' => 'other-night',
            'title' => 'Other Night',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'subdivision' => 'ON',
            'country' => 'CA',
            'status' => 'published',
        ]);

        $foreign = EventImage::create([
            'event_id' => $other->id,
            'kind' => 'gallery',
            'path' => 'events/foreign.jpg',
            'position' => 0,
        ]);

        $this->patchJson(
            "/api/organizer/events/{$this->event->id}/images/{$foreign->id}",
            ['kind' => 'banner'],
        )->assertNotFound();
    }
}
