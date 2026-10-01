<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Filament\Resources\HelpVideos\HelpVideoResource;
use App\Filament\Resources\HelpVideos\Pages\CreateHelpVideo;
use App\Filament\Resources\HelpVideos\Pages\EditHelpVideo;
use App\Filament\Resources\HelpVideos\Pages\ListHelpVideos;
use App\Models\AuditLog;
use App\Models\HelpVideo;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Admin\SupportFixtures;
use Tests\TestCase;

/**
 * The how-to videos on help/videos.
 *
 * Two things matter. That only what staff published reaches the page, in
 * their order. And that a video is only ever YouTube's id for it, never an
 * address somebody typed, so nothing in the admin decides where a reader's
 * browser is sent: the page builds the address itself, on the no-cookie
 * domain.
 */
class HelpVideoTest extends TestCase
{
    use RefreshDatabase, SupportFixtures;

    private function video(array $attributes = []): HelpVideo
    {
        return HelpVideo::create([
            'title' => 'Buying a ticket',
            'youtube_id' => 'abcDEF12345',
            'audience' => 'buyers',
            'sort_order' => 0,
            'published' => true,
            ...$attributes,
        ]);
    }

    public function test_the_page_lists_what_is_published_in_staffs_order(): void
    {
        $this->video(['title' => 'Selling your first night', 'audience' => 'organizers', 'sort_order' => 1, 'youtube_id' => 'org_video-1']);
        $this->video(['title' => 'Getting in at the door', 'sort_order' => 2, 'youtube_id' => 'door_video1']);
        $this->video(['title' => 'Buying a ticket', 'sort_order' => 1, 'youtube_id' => 'buy_video-1', 'description' => 'From the event page to the QR code.']);
        // Same number: by title.
        $this->video(['title' => 'Adding it to your wallet', 'sort_order' => 2, 'youtube_id' => 'wallet_vid1']);
        $this->video(['title' => 'Not ready yet', 'published' => false, 'youtube_id' => 'draft_video']);

        $body = $this->getJson('/api/help/videos')->assertOk()->json('data');

        $this->assertSame(
            ['Buying a ticket', 'Selling your first night', 'Adding it to your wallet', 'Getting in at the door'],
            array_column($body, 'title'),
        );

        $this->assertSame(['id', 'title', 'youtube_id', 'description', 'audience'], array_keys($body[0]));
        $this->assertSame('buy_video-1', $body[0]['youtube_id']);
        $this->assertSame('From the event page to the QR code.', $body[0]['description']);
        $this->assertSame('organizers', $body[1]['audience']);
    }

    public function test_there_may_be_none(): void
    {
        $this->video(['published' => false]);

        $this->getJson('/api/help/videos')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_it_needs_no_account_and_may_be_kept_for_five_minutes(): void
    {
        $response = $this->getJson('/api/help/videos')->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_change_reaches_the_page_straight_away(): void
    {
        // Kept for an hour, so the first read is cached.
        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(0, 'data');

        $video = $this->video();

        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(1, 'data');

        $video->update(['title' => 'Buying a ticket on your phone']);

        $this->getJson('/api/help/videos')->assertOk()->assertJsonPath('data.0.title', 'Buying a ticket on your phone');

        $video->update(['published' => false]);

        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(0, 'data');

        $this->video(['youtube_id' => 'another_vid']);
        HelpVideo::query()->where('youtube_id', 'another_vid')->firstOrFail()->delete();

        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_database_keeps_only_a_youtube_id(): void
    {
        // An address, or anything with a quote or a slash in it, would end up
        // in an attribute on a public page.
        $this->expectException(QueryException::class);

        $this->video(['youtube_id' => 'abc"><scrip']);
    }

    public function test_the_database_keeps_only_the_two_audiences(): void
    {
        $this->expectException(QueryException::class);

        $this->video(['audience' => 'everybody']);
    }

    public function test_the_id_is_read_from_whatever_staff_paste(): void
    {
        foreach ([
            'abcDEF12345',
            '  abcDEF12345 ',
            'https://www.youtube.com/watch?v=abcDEF12345',
            'https://www.youtube.com/watch?feature=share&v=abcDEF12345&t=30s',
            'https://m.youtube.com/watch?v=abcDEF12345',
            'youtube.com/watch?v=abcDEF12345',
            'https://youtu.be/abcDEF12345?si=xyz',
            'https://www.youtube.com/shorts/abcDEF12345',
            'https://www.youtube.com/embed/abcDEF12345',
            'https://www.youtube-nocookie.com/embed/abcDEF12345',
            'https://www.youtube.com/live/abcDEF12345?feature=shared',
        ] as $pasted) {
            $this->assertSame('abcDEF12345', HelpVideo::idFrom($pasted), $pasted);
        }

        foreach ([
            '',
            null,
            'abcDEF1234',
            'abcDEF123456',
            'https://vimeo.com/123456789',
            'https://evil.example/watch?v=abcDEF12345',
            'https://www.youtube.com.evil.example/watch?v=abcDEF12345',
            'https://www.youtube.com/@myfiesta',
            'abc"><scrip',
        ] as $pasted) {
            $this->assertNull(HelpVideo::idFrom($pasted), (string) $pasted);
        }
    }

    public function test_support_adds_a_video_by_pasting_its_address_and_it_starts_as_a_draft(): void
    {
        $staff = $this->actAs($this->staff(PlatformRole::Support));

        Livewire::test(CreateHelpVideo::class)
            ->fillForm([
                'title' => 'Buying a ticket',
                'youtube_id' => 'https://youtu.be/abcDEF12345?si=share',
                'description' => 'From the event page to the QR code.',
                'audience' => 'buyers',
                'sort_order' => 3,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $video = HelpVideo::query()->sole();

        $this->assertSame('abcDEF12345', $video->youtube_id);
        $this->assertSame(3, $video->sort_order);
        $this->assertFalse($video->published);
        $this->assertSame($staff->id, AuditLog::query()->where('action', 'help_video.created')->sole()->actor_id);

        // Not on the page until somebody publishes it.
        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_address_that_is_not_a_youtube_video_is_refused(): void
    {
        $this->actAs($this->staff(PlatformRole::Support));

        Livewire::test(CreateHelpVideo::class)
            ->fillForm([
                'title' => 'Buying a ticket',
                'youtube_id' => 'https://vimeo.com/123456789',
                'audience' => 'buyers',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['youtube_id']);

        $this->assertSame(0, HelpVideo::query()->count());
    }

    public function test_publishing_asks_first_and_puts_it_on_the_page(): void
    {
        $staff = $this->actAs($this->staff(PlatformRole::Support));
        $video = $this->video(['published' => false]);

        Livewire::test(ListHelpVideos::class)
            ->assertActionExists(TestAction::make('publish')->table($video), fn (Action $action) => $action->shouldOpenModal()
                && $action->hasCustomModalHeading()
                && filled($action->getModalDescription()))
            ->assertTableActionHidden('unpublish', $video)
            ->callTableAction('publish', $video)
            ->assertHasNoTableActionErrors();

        $this->assertTrue($video->fresh()->published);
        $this->assertSame($staff->id, AuditLog::query()->where('action', 'help_video.published')->sole()->actor_id);
        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(1, 'data');

        Livewire::test(EditHelpVideo::class, ['record' => $video->getRouteKey()])
            ->assertActionHidden('publish')
            ->assertActionExists('unpublish', fn (Action $action) => $action->getColor() === 'danger'
                && $action->hasCustomModalHeading()
                && filled($action->getModalDescription()))
            ->callAction('unpublish')
            ->assertHasNoActionErrors();

        $this->assertFalse($video->fresh()->published);
        $this->getJson('/api/help/videos')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleting_asks_by_name_and_is_recorded(): void
    {
        $this->actAs($this->staff(PlatformRole::Admin));
        $video = $this->video();

        Livewire::test(ListHelpVideos::class)
            ->assertActionExists(TestAction::make('delete')->table($video), fn (Action $action) => $action->shouldOpenModal()
                && $action->getModalHeading() === 'Delete “Buying a ticket”?'
                && filled($action->getModalDescription()))
            ->callTableAction('delete', $video);

        $this->assertNull(HelpVideo::query()->find($video->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'help_video.deleted')->count());
    }

    public function test_finance_may_look_but_not_change_the_videos(): void
    {
        $this->actAs($this->staff(PlatformRole::Finance));
        $video = $this->video();

        $this->assertTrue(HelpVideoResource::canViewAny());
        $this->assertFalse(HelpVideoResource::canCreate());
        $this->assertFalse(HelpVideoResource::canEdit($video));
        $this->assertFalse(HelpVideoResource::canDelete($video));

        Livewire::test(ListHelpVideos::class)
            ->assertSuccessful()
            ->assertTableActionHidden('unpublish', $video)
            ->assertTableActionHidden('delete', $video);
    }

    public function test_somebody_not_on_staff_cannot_open_them(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(HelpVideoResource::canViewAny());
    }
}
