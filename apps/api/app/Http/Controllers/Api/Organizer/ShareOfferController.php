<?php

namespace App\Http\Controllers\Api\Organizer;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Events\EventReviews;
use App\Services\Settings\PlatformSettings;
use App\Services\Sharing\ShareLinks;
use App\Services\Sharing\ShareOffers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Friend buys, both save": a night's offer, set from its Overview.
 *
 * A discount like any code, so it is the codes permission that changes it,
 * and like a code it cannot change while staff review the night
 * (EventReviews): the review is of what buyers will be able to use. The
 * console asks the organizer to confirm they pay for both discounts before
 * this is called; nothing here can be taken back from people who already
 * hold a link or a reward, only stopped from here on.
 */
class ShareOfferController extends Controller
{
    public function update(Request $request, Event $event, ShareOffers $offers, PlatformSettings $settings): JsonResponse
    {
        $this->authorize('viewInConsole', $event);
        $this->authorize('manageCodes', $event);
        EventReviews::refuseWhileInReview($event);

        $max = $settings->shareMaxBps();

        if ($max <= 0 && $request->input('discount_bps') !== null) {
            return response()->json(['message' => 'Friend discounts are switched off on myFiesta at the moment.'], 422);
        }

        $data = $request->validate([
            // Basis points, as every percentage the API takes: 1500 is 15%.
            'discount_bps' => ['present', 'nullable', 'integer', 'min:100', 'max:'.max(100, $max)],
            'max_rewards' => ['required', 'integer', 'min:1', 'max:100'],
        ], [
            'discount_bps.min' => 'A friend discount is at least 1%.',
            'discount_bps.max' => 'A friend discount is at most '.ShareLinks::percent($max).'%.',
            'max_rewards.min' => 'Let each link earn at least one reward.',
            'max_rewards.max' => 'Each link can earn at most 100 rewards.',
        ]);

        $offers->set(
            $event,
            $data['discount_bps'] === null ? null : (int) $data['discount_bps'],
            (int) $data['max_rewards'],
            $request->user(),
        );

        return response()->json([
            'share_offer' => $offers->forOrganizer($event->refresh(), $max),
        ]);
    }
}
