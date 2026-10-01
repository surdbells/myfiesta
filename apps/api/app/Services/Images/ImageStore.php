<?php

namespace App\Services\Images;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

/**
 * Taking a picture off a phone and putting it on an event page.
 *
 * Everything here happens on the way in, once, rather than on the way out
 * repeatedly. A page that resizes on request is a page that resizes on every
 * request, and the request that matters is the one from somebody standing in a
 * venue queue on a bad connection.
 *
 * Three things this does that are easy to leave out:
 *
 * Every file is re-encoded rather than stored as uploaded. That strips EXIF,
 * which on a phone photo carries the GPS coordinates the picture was taken at
 * and the device that took it. An organizer uploading a shot from inside their
 * own venue should not be publishing its location and their phone's serial
 * number, and they will never think to ask.
 *
 * Dimensions are read before anything is decoded. A 60,000 × 60,000 PNG is a
 * small file and about ten gigabytes of memory once expanded — the standard way
 * to take a server down with a valid image.
 *
 * The uploaded bytes are checked for what they are, not what they claim. A
 * file named .jpg with a PHP payload inside is the oldest upload attack there
 * is, and the extension is chosen from what was actually decoded.
 */
class ImageStore
{
    /**
     * Renditions produced per kind.
     *
     * A banner needs an Open Graph crop at exactly the size the social networks
     * want; a gallery photo does not. Both need a thumbnail, because a gallery
     * of full-size images is a page nobody waits for.
     */
    private const RENDITIONS = [
        'banner' => [
            'display' => ['w' => 1600, 'h' => 900, 'fit' => 'cover'],
            'thumb' => ['w' => 480, 'h' => 270, 'fit' => 'cover'],
            // 1200×630 is what Facebook, LinkedIn and Slack read, and what
            // makes a shared link unfurl as a picture rather than a line of
            // text. This is the whole reason a banner earns its cost.
            'og' => ['w' => 1200, 'h' => 630, 'fit' => 'cover'],
        ],
        'gallery' => [
            'display' => ['w' => 1600, 'h' => 1600, 'fit' => 'contain'],
            'thumb' => ['w' => 480, 'h' => 480, 'fit' => 'cover'],
        ],
    ];

    /** Beyond this, decoding costs more memory than the picture is worth. */
    private const MAX_PIXELS = 50_000_000;

    private const MAX_STORED_EDGE = 2400;

    public function __construct(private readonly ImageManager $images) {}

    /**
     * @throws ImageRejected when the file is not something we will publish
     */
    public function store(
        Event $event,
        UploadedFile $file,
        string $kind,
        ?User $uploader = null,
        ?string $caption = null,
    ): EventImage {
        $this->guardDimensions($file);

        $image = $this->decode($file);

        // Orientation is applied and then discarded with the rest of the EXIF.
        // Without this, a photo taken in portrait publishes on its side —
        // correct in the file, wrong in every browser that does not rotate.
        $image->orient();

        $image = $image->scaleDown(self::MAX_STORED_EDGE, self::MAX_STORED_EDGE);

        $directory = "events/{$event->id}";
        $stem = Str::lower(Str::random(16));
        $disk = Storage::disk('public');

        $path = "{$directory}/{$stem}.jpg";
        $encoded = (string) $image->toJpeg(quality: 88);
        $this->write($disk, $path, $encoded);

        $width = $image->width();
        $height = $image->height();

        // Released before any rendition is made. Renditions are derived by
        // decoding the JPEG just written rather than by cloning what is in
        // memory: a clone per rendition means four full-size images live at
        // once, and four copies of a 2400-edge photo is most of a small
        // server's memory limit for one upload. Decoding again costs CPU we
        // have and saves memory we do not.
        //
        // From the encoded bytes, not from the disk. The disk may be a bucket,
        // and a bucket has no local path to read back — asking it for one
        // returns a key, and the banner fails after its original is stored.
        unset($image);

        $renditions = [];

        try {
            foreach (self::RENDITIONS[$kind] ?? [] as $name => $spec) {
                $source = $this->images->read($encoded);

                $variant = $spec['fit'] === 'cover'
                    // Cropped to fill. A banner slot is a fixed shape and letterboxing
                    // it with grey bars looks like a mistake rather than a decision.
                    ? $source->cover($spec['w'], $spec['h'])
                    // Fitted inside. A gallery photo is somebody's picture, and
                    // cropping it to a square cuts people out of their own night.
                    : $source->scaleDown($spec['w'], $spec['h']);

                $variantPath = "{$directory}/{$stem}-{$name}.jpg";
                $this->write($disk, $variantPath, (string) $variant->toJpeg(quality: 82));

                $renditions[$name] = $variantPath;

                unset($source, $variant);
            }
        } catch (\Throwable $e) {
            // Nothing will ever point at the files already written, so they go
            // now rather than sit in a bucket nobody reads the listing of.
            $disk->delete([$path, ...array_values($renditions)]);

            throw $e;
        }

        $byteSize = strlen($encoded);
        unset($encoded);

        return DB::transaction(function () use ($event, $kind, $path, $renditions, $width, $height, $byteSize, $uploader, $caption) {
            // Replacing the banner rather than adding a second one. The unique
            // index would refuse the insert; doing it here means the old files
            // go too, instead of being orphaned on disk forever.
            if ($kind === 'banner') {
                $this->deleteExisting($event->images()->where('kind', 'banner')->get());
            }

            return EventImage::create([
                'event_id' => $event->id,
                'kind' => $kind,
                'path' => $path,
                'renditions' => $renditions,
                'width' => $width,
                'height' => $height,
                // Counted from the bytes in hand. Asking the disk would be a
                // round trip to the bucket for a number already known.
                'byte_size' => $byteSize,
                'mime' => 'image/jpeg',
                'caption' => $caption,
                // Appended to the end. max()+1 rather than count(), so deleting
                // the third of five and adding another does not hand the new
                // one a position two others already hold.
                'position' => $kind === 'gallery'
                    ? $this->nextGalleryPosition($event)
                    : 0,
                'uploaded_by' => $uploader?->id,
            ]);
        });
    }

    /**
     * An organization's mark.
     *
     * One square image and no renditions: it is drawn at 48 pixels on an event
     * page and nowhere larger, so a second size would be a second file nobody
     * reads. Everything the event pictures get on the way in applies here too —
     * the bytes are checked for what they are, the dimensions before anything
     * is decoded, and the file is re-encoded so a logo exported from a phone
     * does not publish where it was made.
     *
     * Returns the stored path. Replacing one removes the old bytes: an
     * organization that changes its mark four times should not leave four
     * files on a disk nothing references.
     *
     * @throws ImageRejected when the file is not something we will publish
     */
    public function logo(Organization $organization, UploadedFile $file): string
    {
        $this->guardDimensions($file);

        $image = $this->decode($file);
        $image->orient();

        // Cropped to a square, because it is drawn in a circle. Fitting it
        // inside instead leaves a wide logo floating in a ring of background.
        $image = $image->cover(512, 512);

        $disk = Storage::disk('public');
        $path = "organizations/{$organization->id}/".Str::lower(Str::random(16)).'.jpg';

        $this->write($disk, $path, (string) $image->toJpeg(quality: 86));

        unset($image);

        $previous = $organization->logo_path;

        $organization->forceFill(['logo_path' => $path])->save();

        // After the new one is saved, never before: a delete that runs first
        // and a write that then fails leaves an organization with no mark and
        // no way to know what it was.
        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        return $path;
    }

    /** Take the mark away, bytes included. */
    public function removeLogo(Organization $organization): void
    {
        $path = $organization->logo_path;

        $organization->forceFill(['logo_path' => null])->save();

        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * A person's photo, for the greeting on the phone's home screen and the
     * top of its settings.
     *
     * The same care as a logo, for a picture that is more often a selfie than
     * anything else: the bytes are checked for what they are, the dimensions
     * before anything is decoded, and the file is re-encoded, which strips the
     * EXIF — a phone photo carries where it was taken, and that is usually
     * somebody's home.
     *
     * 256 pixels square. It is drawn at 64 at the largest, and a screen three
     * times as dense as a laptop's still has pixels to spare.
     *
     * Stored under a random name, so a new photo is a new address and no phone
     * goes on showing the old one from its cache. The old file goes once the
     * new one is saved.
     *
     * @throws ImageRejected when the file is not something we will keep
     */
    public function avatar(User $user, UploadedFile $file): string
    {
        $this->guardDimensions($file);

        $image = $this->decode($file);
        $image->orient();

        // Cropped to fill, because it is drawn in a circle. A face in a wide
        // photo stays in the middle, where the crop keeps it.
        $image = $image->cover(256, 256);

        $disk = Storage::disk('public');
        $path = "avatars/{$user->id}/".Str::lower(Str::random(16)).'.jpg';

        $this->write($disk, $path, (string) $image->toJpeg(quality: 86));

        unset($image);

        $previous = $this->swapAvatar($user, $path);

        // After the new one is saved, as with a logo: a delete that runs first
        // and a save that then fails leaves somebody with no photo at all.
        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }

        return $path;
    }

    /** Take the photo away, bytes included. Their initials stand in. */
    public function removeAvatar(User $user): void
    {
        $path = $this->swapAvatar($user, null);

        if ($path !== null) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Point the account at a new photo, or none, and say which file it
     * pointed at before.
     *
     * The previous path is read from the row under a lock rather than from
     * the model in hand. Two uploads that overlap — a double tap, or two
     * phones — would otherwise each see the same old path, and the first
     * one's file would stay on the disk with no column naming it. Erasure
     * deletes only the file the column names, so that photo of somebody's
     * face would outlive their account at an address that still works.
     */
    private function swapAvatar(User $user, ?string $path): ?string
    {
        $previous = DB::transaction(function () use ($user, $path): ?string {
            $previous = User::query()->whereKey($user->id)->lockForUpdate()->value('avatar_path');

            User::query()->whereKey($user->id)->update(['avatar_path' => $path]);

            return is_string($previous) ? $previous : null;
        });

        // The model in hand is the one the response is built from.
        $user->forceFill(['avatar_path' => $path])->syncOriginalAttribute('avatar_path');

        return $previous;
    }

    /**
     * Where the photo is served from, or null for none.
     *
     * The column holds a path, never an address, so moving the media disk to
     * a bucket changes every address at once and no row has to be rewritten.
     */
    public function avatarUrl(User $user): ?string
    {
        return $user->avatar_path !== null
            ? Storage::disk('public')->url($user->avatar_path)
            : null;
    }

    /**
     * Remove the row and the bytes.
     *
     * Deleting the row alone leaves files on a disk nothing references, which
     * is invisible until a storage bill or a migration to R2 makes it visible.
     */
    public function delete(EventImage $image): void
    {
        $this->deleteExisting(collect([$image]));
    }

    /** @param  Collection<int, EventImage>  $images */
    private function deleteExisting($images): void
    {
        $disk = Storage::disk('public');

        foreach ($images as $image) {
            foreach ($image->paths() as $path) {
                $disk->delete($path);
            }

            $image->delete();
        }
    }

    /**
     * Refuse a decompression bomb before decoding it.
     *
     * getimagesize reads the header only, so this costs nothing and happens
     * before anything large is allocated.
     */
    private function guardDimensions(UploadedFile $file): void
    {
        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            throw ImageRejected::because('That file is not an image we can read.');
        }

        [$width, $height] = $size;

        if ($width * $height > self::MAX_PIXELS) {
            throw ImageRejected::because(
                'That image is too large to process. Anything up to about 8000 by 6000 is fine.'
            );
        }
    }

    /**
     * Put the bytes on the disk, or say that it did not happen.
     *
     * The disk is configured not to throw, and put() answers false instead —
     * which, unchecked, is a row in the database for a picture that is not
     * there, and an event page with a broken image nobody is told about. With
     * a bucket in the way that is a network call that can fail like any other.
     */
    private function write(Filesystem $disk, string $path, string $bytes): void
    {
        if (! $disk->put($path, $bytes)) {
            throw new RuntimeException("The picture could not be written to the media disk at [{$path}].");
        }
    }

    /** Empty gallery starts at zero, not at one — max() of nothing is null. */
    private function nextGalleryPosition(Event $event): int
    {
        $highest = $event->images()->where('kind', 'gallery')->max('position');

        return $highest === null ? 0 : ((int) $highest) + 1;
    }

    private function decode(UploadedFile $file): ImageInterface
    {
        try {
            // Reads the actual bytes. A .jpg containing something else fails
            // here rather than being stored because its name looked right.
            return $this->images->read($file->getRealPath());
        } catch (\Throwable) {
            throw ImageRejected::because('That file is not an image we can read.');
        }
    }
}
