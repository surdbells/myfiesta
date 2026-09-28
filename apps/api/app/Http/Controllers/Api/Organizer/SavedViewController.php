<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The views somebody has kept of a list in the console.
 *
 * Every member can keep views of any list: a view is a way of asking, not an
 * answer. Applying one asks the list's own endpoint, which decides what this
 * person may see, so a view of the orders kept by somebody who later loses
 * the right to see money shows them nothing more than the orders screen does.
 *
 * The state is the console's shape, checked here for size and for being flat
 * data — a filter's key and its value, a sort, a list of columns — never for
 * meaning. That is what keeps a stored view from being a place to park
 * anything else, without the server needing to know every list's filters.
 */
class SavedViewController extends Controller
{
    /** The lists that can have views. A new one is added here and in the console. */
    public const LISTS = ['orders', 'event-orders', 'codes', 'event-codes', 'guests', 'campaigns', 'payouts', 'events'];

    /** Per person, per organization, per list. Past this a list of views is its own filter problem. */
    public const MAX_PER_LIST = 30;

    /** The whole state, encoded. A dozen filters, a sort and the columns fit in a fraction of it. */
    public const MAX_STATE_BYTES = 4096;

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'list' => ['required', 'string', Rule::in(self::LISTS)],
        ]);

        $views = SavedView::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization->id)
            ->where('list', $data['list'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $views->map($this->present(...))->values()]);
    }

    /**
     * Keep a view, or replace the one with the same name.
     *
     * Saving "This week" again with different filters means "this week is
     * now this", not a second view of the same name beside the first.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $data = $request->validate([
            'list' => ['required', 'string', Rule::in(self::LISTS)],
            'name' => ['required', 'string', 'max:60'],
            'state' => ['present', 'array'],
        ]);

        $name = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? '');

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the view a name.']);
        }

        $state = $this->state($data['state']);

        $owned = SavedView::query()
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization->id)
            ->where('list', $data['list']);

        $existing = (clone $owned)->where('name', $name)->first();

        if ($existing === null && $owned->count() >= self::MAX_PER_LIST) {
            throw ValidationException::withMessages([
                'name' => 'You have '.self::MAX_PER_LIST.' views of this list already. Delete one to keep another.',
            ]);
        }

        $view = $existing ?? new SavedView([
            'user_id' => $request->user()->id,
            'organization_id' => $organization->id,
            'list' => $data['list'],
            'name' => $name,
        ]);

        $view->state = $state;
        $view->save();

        return response()->json(['data' => $this->present($view)], $existing === null ? 201 : 200);
    }

    public function destroy(Request $request, string $view): Response
    {
        $organization = $this->organization($request);

        // Somebody else's view, or another organization's, is as missing as
        // one that never existed: its existence is nobody else's business.
        $found = SavedView::query()
            ->whereKey($view)
            ->where('user_id', $request->user()->id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $found->delete();

        return response()->noContent();
    }

    /**
     * Flat data only: a key, and a scalar or a short list of scalars.
     *
     * @param  array<mixed>  $state
     * @return array<string, mixed>
     */
    private function state(array $state): array
    {
        $refuse = fn (string $why) => throw ValidationException::withMessages(['state' => $why]);

        if ($state !== [] && array_is_list($state)) {
            $refuse('The view is not in a shape the console saves.');
        }

        if (count($state) > 40) {
            $refuse('The view has more settings than any list has.');
        }

        foreach ($state as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                $refuse('The view is not in a shape the console saves.');
            }

            $scalar = fn (mixed $v) => $v === null || is_bool($v) || is_int($v) || (is_string($v) && mb_strlen($v) <= 200);

            if (is_array($value)) {
                if (! array_is_list($value) || count($value) > 50 || ! collect($value)->every(fn ($v) => $v !== null && $scalar($v))) {
                    $refuse('The view is not in a shape the console saves.');
                }
            } elseif (! $scalar($value)) {
                $refuse('The view is not in a shape the console saves.');
            }
        }

        if (strlen((string) json_encode($state)) > self::MAX_STATE_BYTES) {
            $refuse('The view is too large to keep.');
        }

        return $state;
    }

    /** @return array<string, mixed> */
    private function present(SavedView $view): array
    {
        return [
            'id' => $view->id,
            'list' => $view->list,
            'name' => $view->name,
            // An empty state is an object to the console, never a list.
            'state' => (object) $view->state,
            'updated_at' => $view->updated_at,
        ];
    }

    private function organization(Request $request): Organization
    {
        $memberships = $request->user()->organizations;
        $asked = $request->header('X-Organization');

        $membership = filled($asked) ? $memberships->firstWhere('id', $asked) : $memberships->first();

        abort_unless($membership instanceof Organization, 403, 'You are not a member of that organization.');

        return $membership;
    }
}
