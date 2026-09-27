<?php

namespace Tests\Feature;

use App\Enums\PlatformRole;
use App\Mail\DisputeDueSoon;
use App\Mail\DisputeOpened;
use App\Models\Dispute;
use App\Services\Disputes\DisputeDesk;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\OpensDisputes;
use Tests\Concerns\SellsTicketsForDisputes;
use Tests\TestCase;

/**
 * The deadline, said to the people who can meet it.
 *
 * An unanswered dispute is a lost one, and a deadline announced once weeks
 * earlier is one somebody forgets. So Admin and Finance are reminded with five
 * days left and again with two — each once, however often the sweep runs —
 * and never about a dispute that has already been answered or has closed.
 */
class DisputeRemindersTest extends TestCase
{
    use OpensDisputes, RefreshDatabase, SellsTicketsForDisputes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcessors();

        Mail::fake();
        Storage::fake(DisputeDesk::DISK);

        $this->staffMember(PlatformRole::Admin, 'Amara Admin');
        $this->staffMember(PlatformRole::Finance, 'Femi Finance');
        $this->staffMember(PlatformRole::Support, 'Sade Support');
    }

    public function test_reminders_go_once_at_five_days_and_once_at_two(): void
    {
        $dispute = $this->openDisputeDueIn(10);

        Mail::assertQueued(DisputeOpened::class, 2);

        $this->remind();
        Mail::assertNotQueued(DisputeDueSoon::class);

        // Five days left.
        $this->travelTo($dispute->evidence_due_at->copy()->subDays(5)->addMinutes(10));
        $this->remind();
        $this->remind();

        Mail::assertQueued(DisputeDueSoon::class, 2);
        Mail::assertQueued(DisputeDueSoon::class, fn (DisputeDueSoon $mail) => $mail->hasTo('amara-admin@myfiesta.test') && $mail->daysLeft === 5);
        Mail::assertQueued(DisputeDueSoon::class, fn (DisputeDueSoon $mail) => $mail->hasTo('femi-finance@myfiesta.test'));
        Mail::assertNotQueued(DisputeDueSoon::class, fn (DisputeDueSoon $mail) => $mail->hasTo('sade-support@myfiesta.test'));

        // A day on, still nothing new.
        $this->travel(1)->day();
        $this->remind();
        Mail::assertQueued(DisputeDueSoon::class, 2);

        // Two days left.
        $this->travelTo($dispute->evidence_due_at->copy()->subDays(2)->addMinutes(10));
        $this->remind();
        $this->remind();

        Mail::assertQueued(DisputeDueSoon::class, 4);
        Mail::assertQueued(DisputeDueSoon::class, fn (DisputeDueSoon $mail) => $mail->daysLeft === 2 && $mail->reference === $dispute->order->reference);

        // And after the deadline, nothing: the processor takes nothing then.
        $this->travelTo($dispute->evidence_due_at->copy()->addHour());
        $this->remind();
        Mail::assertQueued(DisputeDueSoon::class, 4);

        $dispute->refresh();
        $this->assertNotNull($dispute->reminded_five_days_at);
        $this->assertNotNull($dispute->reminded_two_days_at);
    }

    public function test_a_dispute_opened_close_to_its_deadline_is_announced_once_and_reminded_only_at_two_days(): void
    {
        $dispute = $this->openDisputeDueIn(4);

        // The opening email already said four days.
        Mail::assertQueued(DisputeOpened::class, 2);
        $this->remind();
        Mail::assertNotQueued(DisputeDueSoon::class);

        $this->travelTo($dispute->evidence_due_at->copy()->subDays(2)->addMinute());
        $this->remind();
        Mail::assertQueued(DisputeDueSoon::class, 2);
    }

    public function test_an_answered_or_closed_dispute_is_not_chased(): void
    {
        $answered = $this->openDisputeDueIn(10);
        $answered->forceFill(['response' => Dispute::SUBMITTED, 'responded_at' => now()])->save();

        $closed = $this->openDisputeDueIn(10);
        $closed->forceFill(['status' => 'won', 'closed_at' => now()])->save();

        $this->travel(9)->days();
        $this->remind();

        Mail::assertNotQueued(DisputeDueSoon::class);
    }

    public function test_the_sweep_is_on_the_schedule(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'disputes:remind'));

        $this->assertNotNull($scheduled, 'disputes:remind is not scheduled.');
        $this->assertSame('0 * * * *', $scheduled->expression);
    }

    private function openDisputeDueIn(int $days): Dispute
    {
        [$order, , $charge] = $this->paidOnStripe();

        return $this->disputeOnStripe($order, $charge, 'product_not_received', [
            'evidence_details' => ['due_by' => now()->addDays($days)->getTimestamp()],
        ]);
    }

    private function remind(): void
    {
        $this->artisan('disputes:remind')->assertSuccessful();
    }
}
