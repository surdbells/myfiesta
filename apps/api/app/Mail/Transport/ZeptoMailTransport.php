<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * ZeptoMail over its HTTP API rather than SMTP.
 *
 * The API surfaces per-recipient failures in the response, where SMTP reports
 * one status for the whole envelope. It also opens the door to their delivery
 * and bounce webhooks, which is what turns "we sent it" into "they got it" —
 * the previous platform logged failures to error_log and nowhere else, so a
 * bounced ticket was invisible until the guest arrived without one.
 *
 * Registered as a Laravel mailer, so everything goes through the normal queued
 * Mail facade and nothing has to know this exists.
 */
class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://api.zeptomail.com/v1.1/email',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = [
            'from' => $this->address($email->getFrom()[0] ?? null),
            'to' => $this->recipients($email->getTo()),
            'subject' => $email->getSubject(),
            'htmlbody' => $email->getHtmlBody(),
            'textbody' => $email->getTextBody(),
        ];

        if ($cc = $this->recipients($email->getCc())) {
            $payload['cc'] = $cc;
        }

        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = array_map(fn (Address $a) => $this->address($a), $replyTo);
        }

        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== []);

        $response = Http::withHeaders([
            'Authorization' => 'Zoho-enczapikey '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(30)->post($this->endpoint, $payload);

        // Throwing lets the queue retry with backoff. Swallowing it, which is
        // what the previous implementation did, means a ticket that never
        // arrived leaves no trace anyone will look at.
        $response->throw();
    }

    /** @param  array<Address>  $addresses */
    private function recipients(array $addresses): array
    {
        return array_map(fn (Address $a) => ['email_address' => $this->address($a)], $addresses);
    }

    private function address(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return array_filter([
            'address' => $address->getAddress(),
            'name' => $address->getName() ?: null,
        ]);
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}
