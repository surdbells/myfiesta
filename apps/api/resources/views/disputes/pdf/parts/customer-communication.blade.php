{{--
    Every email we sent the buyer about this order, as the mailer recorded it
    when it left: when, what, to which address, and the id the mail provider
    gave it — which is what its own logs are searched by.
--}}
@php
    /** @var \App\Services\Disputes\CaseFile $case */
    $emails = $case->emailsToBuyer();
    $others = $case->emailsToOthers();
    $names = [
        'TicketsIssued' => 'Order confirmation, with the tickets',
        'TicketsResent' => 'The tickets, sent again',
        'YourTicket' => 'A ticket',
        'TicketIssued' => 'Tickets from the organizer',
        'EventReminderMail' => 'Reminder before the night',
        'SoldOutWhilePaying' => 'Sold out while paying, and the refund',
        'AttendeeMessage' => 'A message from the organizer',
    ];
@endphp

<h1>Emails sent to the buyer — order {{ $case->order->reference }}</h1>
<p class="meta">To {{ $case->order->buyer_email }}. Recorded by our server as each one was handed to the mail provider.</p>

@if ($emails->isEmpty())
    <p class="none">No email to the buyer's address is on record for this order.</p>
@else
    <table>
        <tr><th style="width: 24%">Sent (UTC)</th><th style="width: 22%">Email</th><th style="width: 26%">Subject</th><th>Mail provider's id</th></tr>
        @foreach ($emails as $email)
            <tr>
                <td>{{ \App\Services\Disputes\CaseFile::at($email->occurred_at) }}</td>
                <td>{{ $names[$email->mailable] ?? \Illuminate\Support\Str::headline((string) $email->mailable) }}</td>
                <td>{{ $email->details['subject'] ?? '—' }}</td>
                <td class="small" style="word-break: break-all">{{ $email->message_id ?? '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($others > 0)
    <p class="small">{{ $others }} further email(s) about this order went to somebody the buyer passed a ticket to. They are not listed: the addresses are not the buyer's.</p>
@endif
