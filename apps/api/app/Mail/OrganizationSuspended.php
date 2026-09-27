<?php

namespace App\Mail;

use App\Mail\Concerns\KeepsTypedTextPlain;
use App\Mail\Concerns\RepliesReachSupport;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "We have suspended your organization, and this is what it means."
 *
 * To the owners. It says what stopped and, as plainly, what did not: the
 * people who already bought tickets still get in, and refunds can still be
 * made — an owner reading this on a Friday afternoon needs to know tonight's
 * door still works before anything else. The reason is included only when
 * staff chose to share it. Replies reach support, because answering is the
 * one useful thing to do with this email.
 */
class OrganizationSuspended extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    public function __construct(
        public readonly Organization $organization,
        public readonly ?string $reason,
        public readonly int $eventsOffSale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->organization->name} has been suspended on ".config('app.name'),
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organization-suspended',
            with: [
                'organization' => $this->organization,
                'reason' => $this->reason,
                'eventsOffSale' => $this->eventsOffSale,
                'url' => rtrim((string) config('app.console_url'), '/').'/',
            ],
        );
    }
}
