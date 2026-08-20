<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pictures on an event page.
 *
 * The tests worth reading here are not the happy path. They are the ones about
 * what an uploaded file can do to a server, and what a phone photo says about
 * the person who took it.
 */
class EventImageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);

        $this->event = Event::create([
            'organization_id' => $this->org->id,
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
    }

    private function member(Role $role): User
    {
        $user = User::factory()->create();

        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        return $user->fresh()->load('organizations');
    }

    private function asOrganizer(Role $role = Role::Manager): void
    {
        Sanctum::actingAs($this->member($role), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);
    }

    /** A real JPEG, not a text file wearing the extension. */
    private function photo(int $width = 2000, int $height = 1200, string $name = 'poster.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    private function upload(UploadedFile $file, string $kind = 'banner', array $extra = []): TestResponse
    {
        return $this->postJson("/api/organizer/events/{$this->event->id}/images", array_merge([
            'kind' => $kind,
            'file' => $file,
        ], $extra));
    }

    // --- the banner --------------------------------------------------------

    public function test_a_banner_can_be_uploaded(): void
    {
        $this->asOrganizer();

        $this->upload($this->photo())
            ->assertCreated()
            ->assertJsonPath('kind', 'banner')
            ->assertJsonStructure(['id', 'url', 'thumb_url', 'display_url', 'width', 'height']);

        $this->assertSame(1, EventImage::where('kind', 'banner')->count());
    }

    public function test_every_rendition_is_written_to_disk(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo());

        $image = EventImage::first();
        $disk = Storage::disk('public');

        // Resizing happens once, on the way in. A page that resizes per request
        // resizes for every visitor, and the visitor who matters is on a phone
        // outside a venue.
        $this->assertTrue($disk->exists($image->path));

        foreach (['display', 'thumb', 'og'] as $name) {
            $this->assertArrayHasKey($name, $image->renditions);
            $this->assertTrue($disk->exists($image->renditions[$name]), $name.' missing');
        }
    }

    public function test_the_open_graph_rendition_is_exactly_what_the_networks_want(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo(3000, 1000));

        $image = EventImage::first();
        $bytes = Storage::disk('public')->get($image->renditions['og']);
        [$width, $height] = getimagesizefromstring($bytes);

        // 1200×630 is the whole reason a banner earns its cost: it is what
        // makes a shared link unfurl as a picture instead of a line of text.
        // Cropped to that shape, never letterboxed to it.
        $this->assertSame(1200, $width);
        $this->assertSame(630, $height);
    }

    public function test_uploading_a_second_banner_replaces_the_first(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo());

        $first = EventImage::first();

        $this->upload($this->photo(1800, 1000))->assertCreated();

        $this->assertSame(1, EventImage::where('kind', 'banner')->count());

        // The bytes go too. Deleting only the row leaves files nothing points
        // at, which stays invisible until a storage bill makes it visible.
        foreach ($first->paths() as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_the_banner_reaches_the_public_event_page(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo());

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.poster_url', fn ($url) => is_string($url) && $url !== '')
            ->assertJsonPath('data.og_image_url', fn ($url) => is_string($url) && $url !== '');
    }

    // --- what an upload can do to you --------------------------------------

    public function test_a_decompression_bomb_is_refused_before_it_is_decoded(): void
    {
        $this->asOrganizer();

        // Ninety bytes on disk, nine hundred million pixels once expanded, and
        // about three and a half gigabytes of memory to decode. This is the
        // standard way to take a server down with a technically valid image,
        // and the point is that it costs the attacker nothing to send.
        //
        // Building it by hand rather than with the fake image factory is not
        // incidental: asking PHP to draw thirty thousand square pixels runs the
        // test suite out of memory, which is the attack working on us.
        $bomb = UploadedFile::fake()->createWithContent(
            'bomb.png',
            $this->pngHeaderClaiming(30000, 30000),
        );

        $this->upload($bomb)->assertStatus(422);
        $this->assertSame(0, EventImage::count());
    }

    /**
     * A PNG signature and IHDR, and nothing else.
     *
     * getimagesize reads exactly this much, which is why the guard can refuse
     * a bomb without allocating anything.
     */
    private function pngHeaderClaiming(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    public function test_a_file_that_only_claims_to_be_an_image_is_refused(): void
    {
        $this->asOrganizer();

        $notAnImage = UploadedFile::fake()->createWithContent(
            'photo.jpg',
            "<?php system(\$_GET['c']); ?>",
        );

        // The oldest upload attack there is. The name says jpg; the bytes do
        // not, and the bytes are what decide.
        $this->upload($notAnImage)->assertStatus(422);
        $this->assertSame(0, EventImage::count());
    }

    public function test_what_is_stored_is_re_encoded_rather_than_the_bytes_uploaded(): void
    {
        $this->asOrganizer();

        // A phone photo carries EXIF: where it was taken, on what, and when.
        // An organizer uploading a shot from inside their venue should not be
        // publishing its GPS coordinates, and will never think to ask.
        $uploaded = UploadedFile::fake()->image('phone.jpg', 1200, 900);
        $sent = file_get_contents($uploaded->getRealPath());

        $this->upload($uploaded)->assertCreated();

        $stored = Storage::disk('public')->get(EventImage::first()->path);

        // Not the same bytes. Everything is decoded and written out fresh, so
        // whatever metadata arrived does not survive the trip.
        $this->assertNotSame($sent, $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('GPS', $stored);
    }

    public function test_an_oversized_file_is_refused_by_size_alone(): void
    {
        $this->asOrganizer();

        $this->upload(UploadedFile::fake()->create('huge.jpg', 20_000, 'image/jpeg'))
            ->assertStatus(422);
    }

    public function test_a_stored_image_is_capped_rather_than_kept_at_full_size(): void
    {
        $this->asOrganizer();
        // Big enough to exercise the cap, small enough that drawing it does
        // not run the suite out of memory.
        $this->upload($this->photo(3200, 2400));

        $image = EventImage::first();

        $this->assertLessThanOrEqual(2400, $image->width);
        $this->assertLessThanOrEqual(2400, $image->height);
    }

    public function test_a_small_image_is_not_enlarged(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo(600, 400));

        // Scaling a small photo up only blurs it, which looks like a fault in
        // the platform rather than in the picture.
        $this->assertSame(600, EventImage::first()->width);
    }

    // --- the gallery -------------------------------------------------------

    public function test_a_gallery_takes_many_pictures_in_order(): void
    {
        $this->asOrganizer();

        foreach (['one', 'two', 'three'] as $name) {
            $this->upload($this->photo(name: $name.'.jpg'), 'gallery')->assertCreated();
        }

        $this->assertSame([0, 1, 2], $this->event->gallery()->pluck('position')->all());
    }

    public function test_a_gallery_can_be_reordered_in_one_request(): void
    {
        $this->asOrganizer();

        foreach (range(1, 3) as $n) {
            $this->upload($this->photo(name: "p{$n}.jpg"), 'gallery');
        }

        $ids = $this->event->gallery()->pluck('id')->all();
        $reversed = array_reverse($ids);

        // The whole order at once rather than a request per move: a drag that
        // produces six requests can half-apply, and what an organizer sees
        // after a reload should be what they left.
        $this->postJson("/api/organizer/events/{$this->event->id}/images/order", ['ids' => $reversed])
            ->assertOk();

        $this->assertSame($reversed, $this->event->gallery()->pluck('id')->all());
    }

    public function test_reordering_with_a_picture_from_another_event_is_refused(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo(), 'gallery');

        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $foreign = EventImage::create([
            'event_id' => $other->id,
            'kind' => 'gallery',
            'path' => 'events/x/y.jpg',
        ]);

        $this->postJson("/api/organizer/events/{$this->event->id}/images/order", [
            'ids' => [$foreign->id],
        ])->assertStatus(422);
    }

    public function test_a_caption_is_kept_and_can_be_changed(): void
    {
        $this->asOrganizer();

        $this->upload($this->photo(), 'gallery', ['caption' => 'Ada on the decks'])
            ->assertCreated()
            ->assertJsonPath('caption', 'Ada on the decks');

        $image = EventImage::first();

        $this->patchJson("/api/organizer/events/{$this->event->id}/images/{$image->id}", [
            'caption' => 'Ada closing the night',
        ])->assertOk()->assertJsonPath('caption', 'Ada closing the night');
    }

    public function test_the_gallery_reaches_the_public_event_page(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo(), 'gallery', ['caption' => 'The room at midnight']);

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonCount(1, 'data.gallery')
            ->assertJsonPath('data.gallery.0.caption', 'The room at midnight');
    }

    public function test_deleting_a_picture_removes_its_files(): void
    {
        $this->asOrganizer();
        $this->upload($this->photo(), 'gallery');

        $image = EventImage::first();
        $paths = $image->paths();

        $this->deleteJson("/api/organizer/events/{$this->event->id}/images/{$image->id}")
            ->assertOk();

        $this->assertSame(0, EventImage::count());

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    // --- who may -----------------------------------------------------------

    public function test_marketing_cannot_change_an_events_pictures(): void
    {
        $this->asOrganizer(Role::Marketing);

        // Messaging attendees is not the same as changing what the event
        // looks like to everyone who has not bought yet.
        $this->upload($this->photo())->assertForbidden();
    }

    public function test_another_organizations_event_cannot_be_decorated(): void
    {
        $otherOrg = Organization::create(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $stranger = User::factory()->create();
        $otherOrg->members()->attach($stranger->id, [
            'id' => (string) Str::uuid(),
            'role' => Role::Owner->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($stranger->fresh()->load('organizations'), [
            TokenAbility::Attendee->value,
            TokenAbility::Organizer->value,
        ]);

        $this->upload($this->photo())->assertForbidden();
        $this->assertSame(0, EventImage::count());
    }

    public function test_a_picture_from_another_event_cannot_be_deleted_through_this_one(): void
    {
        $this->asOrganizer();

        $other = Event::create([
            'organization_id' => $this->org->id,
            'slug' => 'other',
            'title' => 'Other',
            'currency' => 'CAD',
            'starts_at' => now()->addMonth(),
            'timezone' => 'America/Toronto',
            'city' => 'Toronto',
            'country' => 'CA',
            'status' => 'draft',
        ]);

        $foreign = EventImage::create([
            'event_id' => $other->id,
            'kind' => 'gallery',
            'path' => 'events/x/y.jpg',
        ]);

        $this->deleteJson("/api/organizer/events/{$this->event->id}/images/{$foreign->id}")
            ->assertNotFound();

        $this->assertSame(1, EventImage::count());
    }
}
