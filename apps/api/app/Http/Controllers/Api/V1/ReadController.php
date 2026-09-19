<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Integrations\Payloads;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What another system can read with an organization's key.
 *
 * Read only, and only the key's own organization: an event that belongs to
 * somebody else is a 404, not a 403, so a key cannot even learn that it exists.
 * The shapes are the webhook payloads', so an integration that listens and one
 * that polls see the same thing.
 *
 * Paged by cursor rather than by page number: a sync that walks the orders
 * while new ones arrive must neither skip nor repeat one, and `since` lets it
 * ask only for what changed after its last run.
 */
class ReadController extends Controller
{
    public const PER_PAGE = 100;

    public function __construct(private readonly Payloads $payloads) {}

    public function events(Request $request): JsonResponse
    {
        $page = Event::query()
            ->where('organization_id', $this->key($request)->organization_id)
            ->orderByDesc('starts_at')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return $this->page($page, fn (Event $event) => $this->payloads->event($event));
    }

    public function orders(Request $request, string $event): JsonResponse
    {
        $event = $this->event($request, $event);

        $data = $request->validate(['since' => ['sometimes', 'date']]);

        $page = Order::query()
            ->where('event_id', $event->id)
            // What happened, not what was started: an abandoned basket is not
            // a sale anybody's CRM should hear about.
            ->whereIn('status', ['paid', 'refunded', 'partially_refunded'])
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>', $since))
            ->with(['event', 'lines', 'code'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return $this->page($page, fn (Order $order) => $this->payloads->order($order));
    }

    public function attendees(Request $request, string $event): JsonResponse
    {
        $event = $this->event($request, $event);

        $data = $request->validate(['since' => ['sometimes', 'date']]);

        $page = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->when($data['since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>', $since))
            ->with(['ticketType:id,name', 'order:id,reference'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(self::PER_PAGE);

        return $this->page($page, fn (Ticket $ticket) => $this->payloads->attendee($ticket));
    }

    private function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    private function event(Request $request, string $id): Event
    {
        abort_unless(Str::isUuid($id), 404, 'No such event for this key.');

        $event = Event::query()
            ->whereKey($id)
            ->where('organization_id', $this->key($request)->organization_id)
            ->first();

        abort_if($event === null, 404, 'No such event for this key.');

        return $event;
    }

    /**
     * @param  CursorPaginator<int, Model>  $page
     */
    private function page($page, callable $present): JsonResponse
    {
        return response()->json([
            'data' => collect($page->items())->map($present)->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
