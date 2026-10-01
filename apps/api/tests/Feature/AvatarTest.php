<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\User;
use App\Services\Images\ImageStore;
use App\Services\PersonalData\Eraser;
use App\Services\PersonalData\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A face on the phone's greeting.
 *
 * users.avatar_path was in the table from the start and nothing ever filled
 * it. Now the phone's settings put a photo there, and the home screen shows it
 * beside "Good evening" with the first name. What is being protected: that the
 * photo is kept square and re-encoded, never as it was uploaded; that an old
 * one does not stay on the disk after it is replaced or removed, or after the
 * account is erased; that a door pass cannot put a face on the account that
 * issued it; and that signing in and "who am I" both say where it is.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->user = User::factory()->create([
            'name' => 'Ada Okafor',
            'email' => 'ada@example.com',
            'timezone' => 'America/Vancouver',
        ]);
    }

    private function signedIn(): void
    {
        Sanctum::actingAs($this->user, [TokenAbility::Attendee->value]);
    }

    /** A wide photo, so a square answer proves it was cropped rather than kept. */
    private function photo(string $name = 'me.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 900, 600);
    }

    public function test_a_photo_is_kept_square_as_a_jpeg_and_reported_back(): void
    {
        $this->signedIn();

        $body = $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json();

        $path = $this->user->fresh()->avatar_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("avatars/{$this->user->id}/", $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertSame(Storage::disk('public')->url($path), $body['avatar_url']);
        Storage::disk('public')->assertExists($path);

        // Re-encoded as JPEG whatever came in, which is what drops where a
        // phone photo was taken; and 256 square, because it is drawn in a circle.
        [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(256, $width);
        $this->assertSame(256, $height);
        $this->assertSame(IMAGETYPE_JPEG, $type);
    }

    public function test_replacing_a_photo_takes_the_old_one_off_the_disk(): void
    {
        $this->signedIn();

        $first = $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk()->json('avatar_url');
        $firstPath = $this->user->fresh()->avatar_path;

        $second = $this->post('/api/auth/avatar', ['file' => $this->photo('again.jpg')], ['Accept' => 'application/json'])->assertOk()->json('avatar_url');
        $secondPath = $this->user->fresh()->avatar_path;

        // A new address for a new photo, so no phone goes on showing the old
        // one from its cache.
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_removing_the_photo_takes_the_bytes_with_it(): void
    {
        $this->signedIn();
        $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->avatar_path;

        $this->deleteJson('/api/auth/avatar')->assertOk()->assertExactJson(['avatar_url' => null]);

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
        $this->getJson('/api/auth/me')->assertJsonPath('avatar_url', null);
    }

    public function test_removing_when_there_is_no_photo_changes_nothing(): void
    {
        $this->signedIn();

        $this->deleteJson('/api/auth/avatar')->assertOk()->assertExactJson(['avatar_url' => null]);

        $this->assertNull($this->user->fresh()->avatar_path);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->signedIn();

        // A payload wearing a photo's name.
        $this->post('/api/auth/avatar', [
            'file' => UploadedFile::fake()->create('me.jpg', 8, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->post('/api/auth/avatar', [
            'file' => UploadedFile::fake()->create('me.pdf', 8, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->post('/api/auth/avatar', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($this->user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_photo_larger_than_twelve_megabytes_is_refused(): void
    {
        $this->signedIn();

        $this->post('/api/auth/avatar', [
            'file' => UploadedFile::fake()->image('huge.jpg', 900, 600)->size(12 * 1024 + 1),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($this->user->fresh()->avatar_path);
    }

    public function test_a_door_pass_cannot_change_the_photo_of_the_account_that_issued_it(): void
    {
        // Real tokens, as the phones hold them: the scope check reads the
        // abilities a token was issued with.
        $own = $this->user->createToken('phone', [TokenAbility::Attendee->value], now()->addDay())->plainTextToken;
        $door = $this->user->createToken('door', ['door:'.(string) Str::uuid()], now()->addDay())->plainTextToken;

        $this->withToken($own)->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->avatar_path;

        // Sanctum sees the door phone as the issuer. The scope is what stops it.
        $this->app['auth']->forgetGuards();

        $this->withToken($door)->post('/api/auth/avatar', ['file' => $this->photo('theirs.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->withToken($door)->deleteJson('/api/auth/avatar')->assertForbidden();
        $this->withToken($door)->getJson('/api/auth/me')->assertForbidden();

        $this->assertSame($path, $this->user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_nobody_signed_in_cannot_upload_or_remove(): void
    {
        $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->deleteJson('/api/auth/avatar')->assertUnauthorized();
    }

    public function test_who_am_i_says_where_the_photo_is_and_which_zone_to_greet_in(): void
    {
        $this->signedIn();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('avatar_url', null)
            ->assertJsonPath('timezone', 'America/Vancouver');

        $url = $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk()->json('avatar_url');

        $body = $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('avatar_url', $url)->json();

        // Everything the contract declares for the account is there, null or not.
        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));

        foreach (array_keys($spec['components']['schemas']['Account']['properties']) as $field) {
            $this->assertArrayHasKey($field, $body, "Account declares '{$field}' and GET /api/auth/me does not return it.");
        }

        foreach (array_keys($spec['components']['schemas']['AccountPhoto']['properties']) as $field) {
            $this->assertArrayHasKey($field, $this->deleteJson('/api/auth/avatar')->json(), "AccountPhoto declares '{$field}' and DELETE /api/auth/avatar does not return it.");
        }
    }

    public function test_signing_in_carries_the_photo_and_the_zone(): void
    {
        $this->signedIn();
        $url = $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk()->json('avatar_url');

        // So the home screen greets with a face, at the right time of day,
        // from the first screen after signing in.
        $body = $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.avatar_url', $url)
            ->assertJsonPath('user.timezone', 'America/Vancouver')
            ->json();

        $spec = Yaml::parseFile(base_path('../../packages/contract/openapi.yaml'));

        foreach (array_keys($spec['components']['schemas']['Session']['properties']['user']['properties']) as $field) {
            $this->assertArrayHasKey($field, $body['user'], "Session.user declares '{$field}' and signing in does not return it.");
        }
    }

    public function test_an_account_without_a_zone_leaves_the_choice_to_the_phone(): void
    {
        $this->user->forceFill(['timezone' => null])->save();

        // Never guessed on the server: null means the phone's own zone.
        $this->postJson('/api/auth/login', ['email' => 'ada@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.timezone', null)
            ->assertJsonPath('user.avatar_url', null);
    }

    public function test_the_zone_is_saved_with_the_details_and_an_unknown_one_is_refused(): void
    {
        $this->signedIn();

        $this->patchJson('/api/auth/profile', ['timezone' => 'America/Halifax'])
            ->assertOk()
            ->assertJsonPath('timezone', 'America/Halifax');

        $this->patchJson('/api/auth/profile', ['timezone' => 'Mars/Olympus_Mons'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');

        $this->getJson('/api/auth/me')->assertJsonPath('timezone', 'America/Halifax');
    }

    public function test_the_older_zone_names_a_phone_lists_are_kept_too(): void
    {
        $this->signedIn();

        // The phone's list is ICU's, which names Atikokan, Ontario as
        // America/Coral_Harbour and Kolkata as Asia/Calcutta. Somebody there
        // has no other entry to choose, and their name and phone are saved
        // in the same request.
        foreach (['America/Coral_Harbour', 'Asia/Calcutta'] as $zone) {
            $this->patchJson('/api/auth/profile', ['name' => 'Ada Okafor', 'timezone' => $zone])
                ->assertOk()
                ->assertJsonPath('timezone', $zone);
        }
    }

    public function test_two_uploads_at_once_leave_no_photo_behind_after_erasure(): void
    {
        // A double tap, or two phones: each request holds the account as it
        // was before either one saved, with no photo.
        $first = User::query()->findOrFail($this->user->id);
        $second = User::query()->findOrFail($this->user->id);

        $images = app(ImageStore::class);
        $images->avatar($first, $this->photo('one.png'));
        $kept = $images->avatar($second, $this->photo('two.png'));

        // Only the photo the account points at is on the disk.
        $this->assertSame([$kept], Storage::disk('public')->allFiles("avatars/{$this->user->id}"));

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        $this->assertSame([], Storage::disk('public')->allFiles("avatars/{$this->user->id}"));
    }

    public function test_a_removal_from_a_stale_account_still_clears_the_photo(): void
    {
        $stale = User::query()->findOrFail($this->user->id);

        $path = app(ImageStore::class)->avatar($this->user, $this->photo());

        // Held from before the upload, so it thinks there is no photo.
        app(ImageStore::class)->removeAvatar($stale);

        $this->assertNull($this->user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_uploads_are_limited_to_twenty_a_minute(): void
    {
        $this->signedIn();

        for ($i = 0; $i < 20; $i++) {
            $this->post('/api/auth/avatar', ['file' => UploadedFile::fake()->image("me{$i}.png", 40, 40)], ['Accept' => 'application/json'])->assertOk();
        }

        $this->post('/api/auth/avatar', ['file' => UploadedFile::fake()->image('again.png', 40, 40)], ['Accept' => 'application/json'])->assertStatus(429);

        // Twenty photos, and one of them on the disk.
        $this->assertCount(1, Storage::disk('public')->allFiles("avatars/{$this->user->id}"));
    }

    public function test_erasing_the_account_takes_the_photo_off_the_disk(): void
    {
        $this->signedIn();
        $this->post('/api/auth/avatar', ['file' => $this->photo()], ['Accept' => 'application/json'])->assertOk();
        $path = $this->user->fresh()->avatar_path;

        app(Eraser::class)->erase(Subject::forEmail('ada@example.com'));

        $this->assertNull(User::withTrashed()->find($this->user->id)?->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }
}
