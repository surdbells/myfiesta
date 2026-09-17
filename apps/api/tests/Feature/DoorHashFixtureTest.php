<?php

namespace Tests\Feature;

use App\Services\Door\DoorList;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The server's half of a hash two languages have to agree on.
 *
 * The door's offline list carries hashed codes, never codes. A phone hashes
 * what somebody scans in WebCrypto and looks for it in that list, so if PHP
 * and WebCrypto ever disagreed, every offline scan would read as a ticket
 * nobody recognises — at a door, with a queue behind it, and no signal to
 * check with.
 *
 * Both sides are pinned to the same fixture rather than to each other, because
 * neither can run the other's runtime. This is the half that stops the server
 * drifting; door-rules.spec.ts is the half that stops the phone.
 */
class DoorHashFixtureTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../../packages/contract/fixtures/door-hash.json';

    #[Test]
    public function the_server_hashes_a_code_the_way_the_fixture_says(): void
    {
        $fixture = json_decode(file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            DoorList::ITERATIONS,
            $fixture['iterations'],
            'The iteration count changed. Regenerate the fixture and make sure the phone is updated with it.',
        );

        foreach ($fixture['cases'] as $case) {
            $this->assertSame(
                $case['hash'],
                hash_pbkdf2('sha256', strtoupper(trim($case['code'])), $fixture['salt'], $fixture['iterations'], 64),
                $case['why'],
            );
        }
    }
}
