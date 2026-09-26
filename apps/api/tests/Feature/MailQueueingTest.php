<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

/**
 * Which emails wait for the queue worker, and which go out while the request
 * is answered.
 *
 * docs/DEPLOYMENT.md and the API's README both say it in words: nearly every
 * email is queued, and the exceptions carry a link whose token exists nowhere
 * else, so they are sent at once and the token never sits in the jobs table.
 * The README went on saying "every email", password resets included, long
 * after that stopped being true, and nothing noticed. This is what notices: an
 * email that changes sides fails here, and the message names the two files
 * that describe it.
 */
class MailQueueingTest extends TestCase
{
    use RefreshDatabase;

    /** Sent during the request. Everything else in app/Mail waits for the worker. */
    private const SENT_AT_ONCE = [
        'EmailChangeAddressInUse',
        'EmailChangeConfirm',
        'EmailChangeRequested',
        'EmailChanged',
        'TeamInvitationMail',
    ];

    private const TELL = 'Which emails wait for the queue worker has changed. Update the list here, '
        .'and what docs/DEPLOYMENT.md and apps/api/README.md ("The worker") say about it.';

    public function test_only_the_emails_the_docs_name_skip_the_queue(): void
    {
        $atOnce = collect(glob(app_path('Mail/*.php')))
            ->map(fn (string $file) => 'App\\Mail\\'.basename($file, '.php'))
            ->filter(fn (string $class) => is_a($class, Mailable::class, true)
                && ! (new ReflectionClass($class))->isAbstract())
            ->reject(fn (string $class) => is_a($class, ShouldQueue::class, true))
            ->map(fn (string $class) => class_basename($class))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(self::SENT_AT_ONCE, $atOnce, self::TELL);
    }

    /**
     * Laravel's own notification, not a mailable, so the list above cannot see
     * it. It is the exception people are likeliest to test by hand, and in
     * development it lands in the log whether or not a worker is running.
     */
    public function test_a_password_reset_goes_out_during_the_request(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'ada@example.com'])->assertOk();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification) => ! $notification instanceof ShouldQueue,
        );
    }
}
