<?php

namespace Tests\Feature;

use App\Enums\Permission;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console's copy of the permission list, checked against the authority.
 *
 * The console types permissions as a union so a typo at a call site is a
 * compile error rather than a silently false check. That only works while the
 * union says what the enum says, and nothing was checking: `payouts.request`
 * was granted to Finance months ago and never reached the union, so the one
 * screen that should have asked for it had to gate on seeing money instead.
 *
 * A test rather than a generator, because the union is three lines and a
 * generator is a build step; what was missing was somebody being told.
 */
class PermissionMirrorTest extends TestCase
{
    private const CONSOLE_TYPES = __DIR__.'/../../../organizer-web/src/app/core/api.types.ts';

    #[Test]
    public function the_console_union_lists_exactly_what_the_platform_recognises(): void
    {
        if (! file_exists(self::CONSOLE_TYPES)) {
            $this->markTestSkipped('The console is not checked out beside the API.');
        }

        $source = file_get_contents(self::CONSOLE_TYPES);

        $union = str($source)
            ->after('export type Permission =')
            ->before(';')
            ->matchAll('/"([^"]+)"/')
            ->sort()
            ->values()
            ->all();

        $enum = collect(Permission::cases())->map(fn (Permission $p) => $p->value)->sort()->values()->all();

        $this->assertSame(
            $enum,
            $union,
            "The console's Permission union has drifted from App\Enums\Permission. "
            .'Update apps/organizer-web/src/app/core/api.types.ts.',
        );
    }
}
