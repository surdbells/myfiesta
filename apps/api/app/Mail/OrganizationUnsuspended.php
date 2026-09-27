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
 * The suspension is lifted, and which events went back on sale.
 *
 * Named, both lists: an owner who had six nights on sale needs to know which
 * four are selling again and which two are waiting for them — without that,
 * the two they have to act on look the same as the four they do not.
 */
class OrganizationUnsuspended extends Mailable implements ShouldQueue
{
    use KeepsTypedTextPlain, Queueable, RepliesReachSupport, SerializesModels;

    /**
     * @param  list<string>  $backOnSale  titles of the events on sale again
     * @param  list<string>  $leftAsDrafts  titles of the events it took off sale that stay off
     */
    public function __construct(
        public readonly Organization $organization,
        public readonly array $backOnSale,
        public readonly array $leftAsDrafts,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->organization->name} is no longer suspended",
            replyTo: $this->supportReplyTo(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.organization-unsuspended',
            with: [
                'organization' => $this->organization,
                'backOnSale' => $this->backOnSale,
                'leftAsDrafts' => $this->leftAsDrafts,
                'url' => rtrim((string) config('app.console_url'), '/').'/events',
            ],
        );
    }
}
