<?php

namespace App\Mail;

use App\Models\DataRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The link that proves a privacy request belongs to the person who made it.
 *
 * Also the only warning somebody gets if a stranger typed their address into
 * the form, which is why it says plainly that nothing has happened yet and
 * that ignoring it is a complete answer.
 */
class DataRequestVerify extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DataRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->request->kind === 'export'
                ? 'Confirm your request for your data'
                : 'Confirm that you want to be erased',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.data-request-verify',
            with: [
                'erasure' => $this->request->kind === 'erasure',
                'url' => route('privacy.request', $this->request->token),
                'hours' => DataRequest::VERIFY_HOURS,
            ],
        );
    }
}
