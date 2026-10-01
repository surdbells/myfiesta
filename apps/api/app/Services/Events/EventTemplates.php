<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventTemplate;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeping an event as a starting point, and starting from one.
 *
 * Copying last month's night works until last month's night is edited for a
 * special guest, cancelled, or simply hard to find among forty others. A
 * template is the night as it was when the organizer said "this one", kept
 * apart from it: changing or cancelling the event afterwards changes nothing
 * here, and the next night starts from the template rather than from
 * whichever past event looks most like it.
 *
 * What it keeps is what a copy carries (EventBlueprint) — details, tiers and
 * prices, ladders, extras, questions, reminder times and the poster — and
 * nothing that happened. An event made from one is a draft that goes through
 * review like any other: a template was never approved, only the night it
 * came from.
 */
class EventTemplates
{
    /** Enough for every kind of night an organization runs, and a ceiling on what one can store. */
    public const MAX_PER_ORGANIZATION = 50;

    public function __construct(private readonly EventDuplicator $duplicator) {}

    /** Keep this event's shape under a name. */
    public function save(Event $event, string $name, ?User $by): EventTemplate
    {
        $id = (string) Str::uuid7();

        try {
            return DB::transaction(function () use ($event, $name, $by, $id) {
                $blueprint = EventBlueprint::of($event->fresh());

                $template = new EventTemplate;
                $template->id = $id;

                // The poster in files of the template's own: the event may
                // replace or delete its own long before the template is used.
                [$banner, $bannerPath] = $this->keepBanner($blueprint['banner'] ?? null, $template->id);
                $blueprint['banner'] = $banner;

                $template->forceFill([
                    'organization_id' => $event->organization_id,
                    'name' => $name,
                    'payload' => $blueprint,
                    'banner_path' => $bannerPath,
                    'source_event_id' => $event->id,
                    'created_by' => $by?->id,
                ])->save();

                return $template;
            });
        } catch (Throwable $failed) {
            // No row, so nothing would ever delete the poster copied for it.
            Storage::disk('public')->deleteDirectory("templates/{$id}");

            throw $failed;
        }
    }

    /**
     * A new draft from a template, at a date of its own.
     *
     * A venue the organization has since removed is left off rather than
     * pointed at: the organizer picks where it is, as they would on any new
     * event, instead of selling tickets to a room they stopped using.
     */
    public function createEvent(
        EventTemplate $template,
        CarbonInterface $startsAt,
        DuplicateOptions $options,
        ?User $by,
    ): Event {
        $blueprint = $template->payload;

        $venue = $blueprint['event']['venue_id'] ?? null;

        if ($venue !== null && ! Venue::query()->whereKey($venue)->where('organization_id', $template->organization_id)->exists()) {
            $blueprint['event']['venue_id'] = null;
        }

        return $this->duplicator->fromBlueprint($blueprint, $template->organization_id, $startsAt, $options, $by);
    }

    /**
     * Gone, with its poster's files.
     *
     * The files only once the row is: a delete that failed would otherwise
     * leave a template showing a poster that no longer exists. Events made
     * from it have files of their own and keep them.
     */
    public function delete(EventTemplate $template): void
    {
        $paths = $template->paths();

        $template->delete();

        DB::afterCommit(fn () => Storage::disk('public')->delete($paths));
    }

    /**
     * The poster copied into templates/{id}/, as the blueprint describes it.
     *
     * @param  array<string, mixed>|null  $banner
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function keepBanner(?array $banner, string $templateId): array
    {
        if ($banner === null) {
            return [null, null];
        }

        $disk = Storage::disk('public');
        $source = $banner['path'] ?? null;

        if (! is_string($source) || ! $disk->exists($source)) {
            // A row pointing at nothing would give every event made from
            // this a broken picture rather than none.
            return [null, null];
        }

        $stem = Str::lower(Str::random(16));
        $path = "templates/{$templateId}/{$stem}.jpg";
        $disk->copy($source, $path);

        $renditions = [];

        foreach ($banner['renditions'] ?? [] as $name => $renditionPath) {
            if (is_string($renditionPath) && $disk->exists($renditionPath)) {
                $renditions[$name] = "templates/{$templateId}/{$stem}-{$name}.jpg";
                $disk->copy($renditionPath, $renditions[$name]);
            }
        }

        return [array_merge($banner, ['path' => $path, 'renditions' => $renditions]), $path];
    }
}
