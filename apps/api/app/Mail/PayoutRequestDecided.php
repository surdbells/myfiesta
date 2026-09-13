<?php

namespace App\Mail;

use App\Models\PayoutRequest;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your payout was sent", or "it was not, and here is why".
 *
 * No banking details in it: the organizer knows where their money goes, and
 * an email is the wrong place to repeat it.
 */
class PayoutRequestDecided extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PayoutRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->request->status === 'paid'
                ? 'Your payout of '.$this->paid()->format().' was sent'
                : 'About your payout request',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payout-request-decided',
            with: [
                'request' => $this->request,
                'organization' => $this->request->organization,
                'asked' => $this->request->money()->format(),
                'paid' => $this->request->status === 'paid' ? $this->paid()->format() : null,
                'url' => rtrim((string) config('app.console_url'), '/').'/payouts',
            ],
        );
    }

    private function paid(): Money
    {
        return new Money((int) $this->request->paid_amount, $this->request->currency);
    }
}
