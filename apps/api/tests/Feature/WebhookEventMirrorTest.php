<?php

namespace Tests\Feature;

use App\Models\WebhookEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The apps' list of webhook events, checked against the one the API offers.
 *
 * `order.disputed` was added to WebhookEndpoint::EVENTS and never reached the
 * apps' union. The console labels each event from a map keyed by that union,
 * so the new one had no label: the integrations screen threw while drawing an
 * address that asked for it, and the form showed a checkbox with nothing
 * beside it. With the union right, a map missing a label does not compile.
 */
class WebhookEventMirrorTest extends TestCase
{
    private const SHARED_TYPES = __DIR__.'/../../../../packages/api-types/src/index.ts';

    #[Test]
    public function the_apps_union_lists_exactly_the_events_an_endpoint_can_ask_for(): void
    {
        if (! file_exists(self::SHARED_TYPES)) {
            $this->markTestSkipped('The shared types are not checked out beside the API.');
        }

        $union = str((string) file_get_contents(self::SHARED_TYPES))
            ->after('export type WebhookEventName =')
            ->before(';')
            ->matchAll("/'([^']+)'/")
            ->sort()
            ->values()
            ->all();

        $events = collect(WebhookEndpoint::EVENTS)->sort()->values()->all();

        $this->assertNotSame([], $union, 'No WebhookEventName union was found in packages/api-types/src/index.ts.');
        $this->assertSame(
            $events,
            $union,
            "The apps' WebhookEventName union has drifted from WebhookEndpoint::EVENTS. "
            .'Update packages/api-types/src/index.ts, and give the new event a label in the console and the phone app.',
        );
    }
}
