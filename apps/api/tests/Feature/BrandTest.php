<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How an organization appears on the pages it sells from.
 *
 * These three fields could only be set by the legacy importer, so every
 * organizer who signed up after the migration published events under a blank
 * card with no way to fill it in.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->org = Organization::create(['name' => 'Lagos Nights', 'slug' => 'lagos-nights']);
    }

    private function signedInAs(Role $role): User
    {
        $user = User::factory()->create();
        $this->org->members()->attach($user->id, [
            'id' => (string) Str::uuid(),
            'role' => $role->value,
            'accepted_at' => now(),
        ]);

        Sanctum::actingAs($user->fresh()->load('organizations'), [TokenAbility::Organizer->value]);

        return $user;
    }

    private function logo(): UploadedFile
    {
        return UploadedFile::fake()->image('logo.png', 900, 600);
    }

    public function test_an_owner_writes_what_the_organization_says_about_itself(): void
    {
        $this->signedInAs(Role::Owner);

        $this->patchJson('/api/organizer/brand', [
            'name' => 'Lagos Nights Toronto',
            'description' => 'Afrobeats and amapiano since 2019.',
        ])
            ->assertOk()
            ->assertJsonPath('name', 'Lagos Nights Toronto')
            ->assertJsonPath('description', 'Afrobeats and amapiano since 2019.');

        // The slug is in links organizers have already handed out. Tidying a
        // display name must not quietly break them.
        $this->assertSame('lagos-nights', $this->org->fresh()->slug);
    }

    public function test_the_slug_cannot_be_changed_by_sending_one(): void
    {
        $this->signedInAs(Role::Owner);

        $this->patchJson('/api/organizer/brand', ['slug' => 'something-else'])->assertOk();

        $this->assertSame('lagos-nights', $this->org->fresh()->slug);
    }

    public function test_a_manager_may_look_but_not_change(): void
    {
        $this->signedInAs(Role::Manager);

        // The card is on a settings screen everybody can open.
        $this->getJson('/api/organizer/brand')->assertOk()->assertJsonPath('name', 'Lagos Nights');

        // Changing it is the owner's, like the team.
        $this->patchJson('/api/organizer/brand', ['name' => 'Not Theirs'])->assertForbidden();
        $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])->assertForbidden();

        $this->assertSame('Lagos Nights', $this->org->fresh()->name);
    }

    public function test_a_mark_is_stored_square_and_reported_back(): void
    {
        $this->signedInAs(Role::Owner);

        $body = $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])
            ->assertOk()
            ->json();

        $this->assertNotNull($body['logo_url']);

        $path = $this->org->fresh()->logo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        // Drawn in a circle on an event page. Fitting a wide logo inside
        // instead leaves it floating in a ring of background.
        $this->assertSame(512, $width);
        $this->assertSame(512, $height);
    }

    public function test_replacing_a_mark_does_not_leave_the_old_one_on_disk(): void
    {
        $this->signedInAs(Role::Owner);

        $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])->assertOk();
        $first = $this->org->fresh()->logo_path;

        $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])->assertOk();
        $second = $this->org->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_a_mark_takes_the_bytes_with_it(): void
    {
        $this->signedInAs(Role::Owner);
        $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])->assertOk();
        $path = $this->org->fresh()->logo_path;

        $this->deleteJson('/api/organizer/brand/logo')->assertOk()->assertJsonPath('logo_url', null);

        $this->assertNull($this->org->fresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->signedInAs(Role::Owner);

        // The oldest upload attack there is: a payload wearing an image's name.
        $this->postJson('/api/organizer/brand/logo', [
            'file' => UploadedFile::fake()->create('logo.png', 8, 'image/png'),
        ])->assertStatus(422);

        $this->assertNull($this->org->fresh()->logo_path);
    }

    public function test_what_an_organizer_writes_reaches_the_event_page(): void
    {
        $this->signedInAs(Role::Owner);
        $this->patchJson('/api/organizer/brand', ['description' => 'Afrobeats since 2019.'])->assertOk();
        $this->postJson('/api/organizer/brand/logo', ['file' => $this->logo()])->assertOk();

        $event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'starts_at' => now()->addWeek(),
        ]);

        $page = $this->getJson("/api/events/{$event->slug}")->assertOk()->json('data.organizer');

        $this->assertSame('Afrobeats since 2019.', $page['description']);
        $this->assertNotNull($page['logo_url']);
    }

    public function test_renaming_a_verified_organization_hides_the_tick(): void
    {
        $this->org->update(['verified_at' => now(), 'verified_name' => 'Lagos Nights']);
        $event = Event::factory()->published()->create([
            'organization_id' => $this->org->id,
            'slug' => 'afro-fest',
            'starts_at' => now()->addWeek(),
        ]);

        $this->assertTrue($this->getJson("/api/events/{$event->slug}")->json('data.organizer.is_verified'));

        $this->signedInAs(Role::Owner);
        $this->patchJson('/api/organizer/brand', ['name' => 'Someone Else Entirely'])
            ->assertOk()
            ->assertJsonPath('is_verified', false)
            ->assertJsonPath('verification_pending_name', true);

        // The whole value of a tick is that somebody checked the name beside
        // it. Nobody has checked this one.
        $this->assertFalse($this->getJson("/api/events/{$event->slug}")->json('data.organizer.is_verified'));
    }

    public function test_the_verification_itself_survives_a_rename(): void
    {
        $this->org->update(['verified_at' => now(), 'verified_name' => 'Lagos Nights']);

        $this->signedInAs(Role::Owner);
        $this->patchJson('/api/organizer/brand', ['name' => 'Lagos Nights Toronto'])->assertOk();

        // Suspended, not destroyed: staff confirm the new name in a glance
        // rather than an organizer re-uploading a passport over a typo.
        $organization = $this->org->fresh();
        $this->assertNotNull($organization->verified_at);
        $this->assertTrue($organization->awaitsRenameCheck());

        $organization->update(['verified_name' => $organization->name]);
        $this->assertTrue($organization->fresh()->isVerified());
    }

    public function test_changing_only_the_description_keeps_the_tick(): void
    {
        $this->org->update(['verified_at' => now(), 'verified_name' => 'Lagos Nights']);

        $this->signedInAs(Role::Owner);
        $this->patchJson('/api/organizer/brand', ['description' => 'Afrobeats since 2019.'])
            ->assertOk()
            ->assertJsonPath('is_verified', true);
    }

    public function test_an_unverified_organization_may_rename_without_consequence(): void
    {
        $this->signedInAs(Role::Owner);

        $this->patchJson('/api/organizer/brand', ['name' => 'Anything At All'])
            ->assertOk()
            ->assertJsonPath('is_verified', false)
            ->assertJsonPath('verification_pending_name', false);
    }

    public function test_a_door_token_cannot_read_or_rewrite_the_brand(): void
    {
        $user = $this->signedInAs(Role::Owner);
        Sanctum::actingAs($user, ['door:'.(string) Str::uuid()]);

        $this->getJson('/api/organizer/brand')->assertForbidden();
        $this->patchJson('/api/organizer/brand', ['name' => 'Theirs Now'])->assertForbidden();
    }
}
