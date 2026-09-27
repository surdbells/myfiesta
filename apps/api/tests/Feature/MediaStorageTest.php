<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\Organization;
use App\Services\Images\ImageStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Posters, logos and gallery pictures, on this server's disk or in a bucket.
 *
 * Production had neither working: the local disk was never reachable through
 * the API's nginx, and pointing the disk at a bucket failed every banner after
 * its original was stored, because renditions were read back from a local
 * path a bucket does not have. These pin both arrangements.
 */
class MediaStorageTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private ?string $mediaRoot = null;

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
    }

    // --- which disk --------------------------------------------------------------

    public function test_pictures_stay_on_this_server_unless_told_otherwise(): void
    {
        $disk = $this->mediaDiskWith(['MEDIA_DISK' => null, 'APP_URL' => 'https://api.myfiesta.ca/']);

        $this->assertSame('local', $disk['driver']);
        $this->assertSame(storage_path('app/public'), $disk['root']);
        // Where nginx serves them, and where storage:link points.
        $this->assertSame('https://api.myfiesta.ca/storage', $disk['url']);
    }

    public function test_empty_lines_in_the_environment_mean_the_defaults(): void
    {
        // .env.production.example ships every one of these lines empty, and
        // an empty line reads as "" — not as unset.
        $this->assertSame('local', $this->mediaDiskWith(['MEDIA_DISK' => ''])['driver']);

        $bucket = $this->mediaDiskWith([...$this->r2(), 'AWS_DEFAULT_REGION' => '', 'MEDIA_VISIBILITY' => '']);

        $this->assertSame('us-east-1', $bucket['region']);
        $this->assertSame('private', $bucket['visibility']);
    }

    public function test_a_bucket_is_chosen_by_the_environment(): void
    {
        $disk = $this->mediaDiskWith($this->r2());

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('myfiesta-media', $disk['bucket']);
        $this->assertSame('https://abc123.r2.cloudflarestorage.com', $disk['endpoint']);
        $this->assertSame('https://media.myfiesta.ca', $disk['url']);
        // No ACL by default: new AWS buckets and R2 both refuse public-read.
        $this->assertSame('private', $disk['visibility']);
        $this->assertStringContainsString('immutable', $disk['options']['CacheControl']);
    }

    public function test_a_bucket_address_is_the_public_one_not_the_api_endpoint(): void
    {
        // R2's endpoint is where files are written, and nobody can read from
        // it. The public bucket URL is what goes on an event page.
        $url = Storage::build($this->mediaDiskWith($this->r2()))->url('events/1/poster-display.jpg');

        $this->assertSame('https://media.myfiesta.ca/events/1/poster-display.jpg', $url);
    }

    public function test_an_empty_public_address_falls_back_to_the_bucket_rather_than_to_nothing(): void
    {
        // An `AWS_URL=` line left empty reads as "", which Laravel would take
        // as a base URL and turn every picture into a relative path.
        $url = Storage::build($this->mediaDiskWith([
            ...$this->r2(),
            'AWS_URL' => '',
            'AWS_ENDPOINT' => '',
            'AWS_DEFAULT_REGION' => 'ca-central-1',
        ]))->url('events/1/poster.jpg');

        $this->assertSame('https://myfiesta-media.s3.ca-central-1.amazonaws.com/events/1/poster.jpg', $url);
    }

    public function test_a_path_style_store_puts_the_bucket_in_the_path(): void
    {
        $url = Storage::build($this->mediaDiskWith([
            ...$this->r2(),
            'AWS_URL' => '',
            'AWS_ENDPOINT' => 'http://minio:9000',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        ]))->url('events/1/poster.jpg');

        $this->assertSame('http://minio:9000/myfiesta-media/events/1/poster.jpg', $url);
    }

    // --- writing to it -----------------------------------------------------------

    public function test_a_banner_is_made_on_a_disk_with_no_local_path(): void
    {
        $this->bucketLikeDisk();

        $image = app(ImageStore::class)->store($this->event, $this->photo(), 'banner');

        $disk = Storage::disk('public');

        foreach ($image->paths() as $path) {
            $disk->assertExists($path);
        }

        $this->assertSame(['display', 'thumb', 'og'], array_keys($image->renditions));
        [$width, $height] = getimagesizefromstring($disk->get($image->renditions['og']));
        $this->assertSame([1200, 630], [$width, $height]);
        $this->assertSame($disk->size($image->path), $image->byte_size);
    }

    public function test_a_logo_is_made_on_a_disk_with_no_local_path(): void
    {
        $this->bucketLikeDisk();

        $path = app(ImageStore::class)->logo($this->event->organization, $this->photo(900, 600));

        Storage::disk('public')->assertExists($path);
    }

    public function test_a_write_the_disk_refuses_is_not_recorded_as_a_picture(): void
    {
        $fake = $this->fakeMedia();

        // The disk does not throw — it answers false, which unchecked is a row
        // for a picture that is not there.
        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            private int $writes = 0;

            public function put($path, $contents, $options = [])
            {
                // The original lands; the first rendition does not.
                return ++$this->writes === 1 ? parent::put($path, $contents, $options) : false;
            }
        });

        try {
            app(ImageStore::class)->store($this->event, $this->photo(), 'banner');
            $this->fail('A refused write was treated as stored.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be written', $e->getMessage());
        }

        $this->assertSame(0, EventImage::count());
        // And the original that did land does not stay behind, unreferenced.
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- reading it back ---------------------------------------------------------

    public function test_poster_addresses_come_from_the_disk_on_this_server(): void
    {
        $this->fakeMedia(['url' => 'https://api.myfiesta.test/storage']);
        app(ImageStore::class)->store($this->event, $this->photo(), 'banner');

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.og_image_url', fn (string $url) => str_starts_with($url, 'https://api.myfiesta.test/storage/events/'));
    }

    public function test_poster_addresses_come_from_the_disk_in_a_bucket(): void
    {
        $this->fakeMedia(['url' => 'https://media.myfiesta.test']);
        app(ImageStore::class)->store($this->event, $this->photo(), 'banner');

        $this->getJson('/api/events/afro-fest')
            ->assertOk()
            ->assertJsonPath('data.og_image_url', fn (string $url) => str_starts_with($url, 'https://media.myfiesta.test/events/'));
    }

    // --- helpers -----------------------------------------------------------------

    /** A real JPEG, not a text file wearing the extension. */
    private function photo(int $width = 2000, int $height = 1200): UploadedFile
    {
        return UploadedFile::fake()->image('poster.jpg', $width, $height);
    }

    /**
     * A fake media disk in a directory of this test's own.
     *
     * Storage::fake('public') always uses the same directory and empties it
     * first, so any other test run on the machine at the same moment deletes
     * this one's files from under it. The disk is set as "public", which is
     * what every caller asks for.
     *
     * @param  array<string, mixed>  $config
     */
    private function fakeMedia(array $config = []): FilesystemAdapter
    {
        $this->mediaRoot = storage_path('framework/testing/disks/media-'.bin2hex(random_bytes(6)));

        /** @var FilesystemAdapter $disk */
        $disk = Storage::build(['driver' => 'local', 'root' => $this->mediaRoot, 'throw' => false, ...$config]);
        Storage::set('public', $disk);

        return $disk;
    }

    protected function tearDown(): void
    {
        if ($this->mediaRoot !== null) {
            (new Filesystem)->deleteDirectory($this->mediaRoot);
        }

        parent::tearDown();
    }

    /**
     * The fake disk, made to behave like a bucket where it matters.
     *
     * A fake is always a local directory, so it would happily answer path()
     * and hide exactly the bug a bucket exposes. This one refuses.
     */
    private function bucketLikeDisk(): void
    {
        $fake = $this->fakeMedia();

        Storage::set('public', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function path($path)
            {
                throw new LogicException('Object storage has no local path.');
            }
        });
    }

    /** @return array<string, string> Cloudflare R2, as documented. */
    private function r2(): array
    {
        return [
            'MEDIA_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => 'test-key',
            'AWS_SECRET_ACCESS_KEY' => 'test-secret',
            'AWS_DEFAULT_REGION' => 'auto',
            'AWS_BUCKET' => 'myfiesta-media',
            'AWS_ENDPOINT' => 'https://abc123.r2.cloudflarestorage.com',
            'AWS_URL' => 'https://media.myfiesta.ca',
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'false',
            'MEDIA_VISIBILITY' => null,
        ];
    }

    /**
     * The media disk's configuration as config/filesystems.php would build it
     * under these environment variables. Null unsets one.
     *
     * @param  array<string, string|null>  $env
     * @return array<string, mixed>
     */
    private function mediaDiskWith(array $env): array
    {
        $saved = [];

        foreach ($env as $key => $value) {
            $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];

            if ($value === null) {
                unset($_SERVER[$key], $_ENV[$key]);
                putenv($key);
            } else {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try {
            return (require config_path('filesystems.php'))['disks']['public'];
        } finally {
            foreach ($saved as $key => [$server, $environment, $process]) {
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }

                if ($environment === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $environment;
                }

                $process === false ? putenv($key) : putenv("{$key}={$process}");
            }
        }
    }
}
