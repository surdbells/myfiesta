<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventImage;
use App\Services\Images\ImageRejected;
use App\Services\Images\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The banner, and the gallery.
 *
 * Uploads come in as ordinary multipart requests and are processed here. When
 * the disk moves to R2 this becomes a presigned URL the browser uploads to
 * directly, and the processing moves to a queued job triggered by the
 * completion callback — but building that against a local disk today would be
 * building for an architecture that does not exist yet, and the Flysystem
 * boundary is what makes the change small when it comes.
 */
class EventImageController extends Controller
{
    /**
     * The most gallery pictures one event may have.
     *
     * The gallery is ordered by dragging the whole set, and every picture is
     * four renditions on disk; thirty is more than any event page shows well.
     */
    public const MAX_GALLERY = 30;

    public function __construct(private readonly ImageStore $images) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        $this->authorize('viewInConsole', $event);

        return response()->json([
            'banner' => $event->banner ? $this->present($event->banner) : null,
            'gallery' => $event->gallery->map(fn (EventImage $i) => $this->present($i))->values(),
        ]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'kind' => ['required', 'in:banner,gallery'],
            // 12MB. Larger than any phone photo and small enough that a bad
            // connection fails fast rather than half-uploading for a minute.
            //
            // The mimes rule reads the file, not the name — but it is the
            // cheap first pass, and ImageStore checks the bytes again before
            // anything is written.
            'file' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:12288'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['kind'] === 'gallery' && $event->gallery()->count() >= self::MAX_GALLERY) {
            // Checked before the upload is decoded, so a full gallery costs
            // nothing but the request.
            return response()->json([
                'message' => 'The gallery holds up to '.self::MAX_GALLERY.' pictures. Remove one to add another.',
            ], 422);
        }

        try {
            $image = $this->images->store(
                $event,
                $request->file('file'),
                $data['kind'],
                $request->user(),
                $data['caption'] ?? null,
            );
        } catch (ImageRejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($image), 201);
    }

    public function update(Request $request, Event $event, EventImage $image): JsonResponse
    {
        $this->authorize('update', $event);

        abort_unless($image->event_id === $event->id, 404);

        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'kind' => ['sometimes', 'in:banner,gallery'],
        ]);

        if (array_key_exists('caption', $data)) {
            $image->update(['caption' => $data['caption']]);
        }

        /*
         * Promoting a gallery picture to the banner.
         *
         * Organizers upload the flyer into the gallery constantly — it is the
         * larger target — and the fix should not be delete it, find the file
         * again, upload it a second time.
         *
         * A partial unique index allows one banner per event, so the sitting
         * one has to step down inside the same transaction. Doing it in two
         * requests would leave an event with no banner at all if the second
         * never arrived.
         */
        if (($data['kind'] ?? $image->kind) !== $image->kind) {
            DB::transaction(function () use ($event, $image, $data) {
                if ($data['kind'] === 'banner') {
                    $event->images()
                        ->where('kind', 'banner')
                        ->whereKeyNot($image->id)
                        ->update(['kind' => 'gallery']);
                }

                $image->update(['kind' => $data['kind']]);
            });
        }

        return response()->json($this->present($image->refresh()));
    }

    public function destroy(Request $request, Event $event, EventImage $image): JsonResponse
    {
        $this->authorize('update', $event);

        abort_unless($image->event_id === $event->id, 404);

        $this->images->delete($image);

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * Reorder the gallery.
     *
     * The whole order arrives at once rather than as a sequence of moves. A
     * drag that produces six requests can half-apply; one request either lands
     * or does not, and what an organizer sees after a reload is what they left.
     */
    public function reorder(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        $owned = $event->gallery()->pluck('id')->all();

        // Silently ignoring ids from another event would let a reorder request
        // reveal which ids exist. Refusing is both safer and more honest.
        if (array_diff($data['ids'], $owned) !== []) {
            return response()->json([
                'message' => 'That list includes a picture that is not on this event.',
            ], 422);
        }

        DB::transaction(function () use ($data, $event) {
            foreach ($data['ids'] as $position => $id) {
                $event->images()->whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json([
            'gallery' => $event->gallery()->get()->map(fn (EventImage $i) => $this->present($i))->values(),
        ]);
    }

    private function present(EventImage $image): array
    {
        return [
            'id' => $image->id,
            'kind' => $image->kind,
            'url' => $image->url(),
            'thumb_url' => $image->renditionUrl('thumb'),
            'display_url' => $image->renditionUrl('display'),
            'caption' => $image->caption,
            'width' => $image->width,
            'height' => $image->height,
            'position' => $image->position,
        ];
    }
}
