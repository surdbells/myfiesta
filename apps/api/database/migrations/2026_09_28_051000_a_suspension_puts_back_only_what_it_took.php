<?php

use App\Models\Event;
use App\Services\Events\EventSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lifting a suspension puts back what it took, as it was when it took it.
 *
 * An event taken off sale by a suspension — or approved while one lasts and
 * held until it is lifted — can still be edited meanwhile: a suspension stops
 * the organization selling, not working. Lifting it used to put the event on
 * sale as it stood then, and record that as staff approving it, although
 * nobody at myFiesta had looked at what changed. Beside the mark now is a
 * fingerprint of what buyers saw (EventSnapshot), and only an event that
 * still matches it goes back on sale (Suspension::unsuspend); one that changed
 * stays a draft for the organizer to send for review.
 *
 * Events marked before this are given the fingerprint of what they say now,
 * the nearest thing on record to what they said when they came off sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->char('unpublished_by_suspension_fingerprint', 64)->nullable();
        });

        Event::withTrashed()
            ->whereNotNull('unpublished_by_suspension_at')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    DB::table('events')->where('id', $event->id)->update([
                        'unpublished_by_suspension_fingerprint' => EventSnapshot::fingerprint(EventSnapshot::of($event)),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('unpublished_by_suspension_fingerprint');
        });
    }
};
