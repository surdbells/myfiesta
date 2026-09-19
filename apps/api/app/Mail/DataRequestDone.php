<?php

namespace App\Mail;

use App\Models\DataRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** What was done: the file to download, or what erasure actually did. */
class DataRequestDone extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DataRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match (true) {
            $this->request->status === 'refused' => 'We could not erase your account yet',
            $this->request->kind === 'export' => 'Your data is ready',
            default => 'You have been erased from myFiesta',
        });
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.data-request-done',
            with: [
                'request' => $this->request,
                'refused' => $this->request->outcome['refused'] ?? null,
                'erasure' => $this->request->kind === 'erasure',
                'url' => $this->request->isDownloadable()
                    ? route('privacy.download', $this->request->token)
                    : null,
                'days' => DataRequest::DOWNLOAD_DAYS,
                // Said in the email as well as on the page: an erasure that
                // leaves anonymised orders behind has to say so where the
                // person will actually read it.
                'kept' => collect($this->request->outcome['erased'] ?? [])
                    ->filter(fn ($row) => in_array($row['action'] ?? '', ['anonymised', 'kept'], true))
                    ->isNotEmpty(),
            ],
        );
    }
}
