<?php

namespace App\Filament\Resources\Disputes\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\AuditTrail;
use App\Filament\Support\Listing;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Disputes\CaseFile;
use App\Services\Disputes\DisputeDesk;
use App\Services\Disputes\EvidenceDraft;
use App\Services\Disputes\Reasons;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * What the dispute's page shows around the words that will be sent.
 *
 * Above them: the deadline, first and large; what the buyer's bank was told,
 * in plain words, with what wins it and when to accept instead; anything in
 * the records suggesting the buyer is right; and the checklist of what was
 * found. Below them: the documents, Visa's Compelling Evidence 3.0 when Stripe
 * says it could apply, what was sent, what the processor says, and the trail.
 */
final class DisputeInfolist
{
    /** Due within this many days, and the deadline is shown in red, here and in the list. */
    public const URGENT_DAYS = 3;

    /** @return list<Component> */
    public static function overview(): array
    {
        return [
            Grid::make(['default' => 1, 'lg' => 3])
                ->columnSpanFull()
                ->schema([
                    Section::make('Deadline')
                        ->columnSpan(['lg' => 1])
                        ->schema([
                            TextEntry::make('evidence_due_at')
                                ->label('Answer by')
                                ->dateTime('D j M Y, H:i \U\T\C')
                                ->size(TextSize::Large)
                                ->weight(FontWeight::Bold)
                                ->color(fn (Dispute $record) => self::urgency($record))
                                ->helperText(fn (Dispute $record) => self::timeLeft($record))
                                ->placeholder('The processor gave no deadline'),
                            TextEntry::make('answer')
                                ->label('Our answer')
                                ->state(fn (Dispute $record) => self::answer($record))
                                ->badge()
                                ->color(fn (Dispute $record) => match ($record->response) {
                                    Dispute::SUBMITTED => 'info',
                                    Dispute::ACCEPTED => 'gray',
                                    default => $record->isOpen() ? 'warning' : 'gray',
                                }),
                            TextEntry::make('outcome')
                                ->label('Outcome')
                                ->state(fn (Dispute $record) => self::outcome($record)),
                        ]),

                    Section::make('The dispute')
                        ->columnSpan(['lg' => 2])
                        ->columns(2)
                        ->schema([
                            TextEntry::make('amount')
                                ->label('Disputed')
                                ->state(fn (Dispute $record) => Listing::format((int) $record->amount, $record->currency))
                                ->weight(FontWeight::SemiBold),
                            TextEntry::make('order.reference')
                                ->label('Order')
                                ->fontFamily('mono')
                                ->copyable()
                                ->url(fn (Dispute $record) => OrderResource::canViewAny() ? OrderResource::getUrl('view', ['record' => $record->order_id]) : null)
                                ->helperText(fn (Dispute $record) => $record->order?->buyer_name),
                            TextEntry::make('event.title')
                                ->label('Event')
                                ->helperText(fn (Dispute $record) => $record->organization?->name)
                                ->placeholder('—'),
                            TextEntry::make('gateway_reference')
                                ->label('Processor reference')
                                ->fontFamily('mono')
                                ->copyable()
                                ->helperText(fn (Dispute $record) => ucfirst((string) $record->gateway)
                                    .($record->network_reason_code ? ' · network reason code '.$record->network_reason_code : '')),
                            TextEntry::make('opened_at')->label('Raised')->dateTime('j M Y, H:i \U\T\C'),
                            TextEntry::make('processor_status')
                                ->label('The processor says')
                                ->formatStateUsing(fn (?string $state) => $state ? str_replace(['_', '-'], ' ', $state) : null)
                                ->helperText(fn (Dispute $record) => $record->processor_checked_at ? 'Checked '.$record->processor_checked_at->diffForHumans() : null)
                                ->placeholder('Not asked yet'),
                        ]),
                ]),

            Section::make('What the buyer\'s bank was told')
                ->columns(2)
                ->schema([
                    TextEntry::make('reason')
                        ->label('Reason')
                        ->formatStateUsing(fn (?string $state) => Reasons::label($state))
                        ->placeholder(Reasons::label(null))
                        ->helperText(fn (Dispute $record) => Reasons::claim($record->reason))
                        ->columnSpanFull(),
                    TextEntry::make('wins')
                        ->label('What wins it')
                        ->state(fn (Dispute $record) => Reasons::wins(Reasons::kind($record->reason))),
                    TextEntry::make('fair')
                        ->label('When to accept instead')
                        ->state(fn (Dispute $record) => Reasons::fair(Reasons::kind($record->reason))),
                    TextEntry::make('their_words')
                        ->label('In the buyer\'s words, as Paystack has them')
                        ->state(fn (Dispute $record) => collect((array) ($record->evidence->processor['messages'] ?? []))
                            ->map(fn ($message) => trim((string) ($message['body'] ?? '')))
                            ->filter()
                            ->values()
                            ->all())
                        ->listWithLineBreaks()
                        ->visible(fn (Dispute $record) => $record->gateway === 'paystack')
                        ->placeholder('Paystack gave none')
                        ->columnSpanFull(),
                ]),

            Callout::make('Before you answer')
                ->description(fn (Dispute $record) => implode(' ', (array) ($record->evidence->cautions ?? [])))
                ->warning()
                ->visible(fn (Dispute $record) => ($record->evidence->cautions ?? []) !== [] && $record->isOpen() && ! $record->isAnswered()),

            Section::make('What the records hold')
                ->description(fn (Dispute $record) => $record->evidence === null
                    ? 'The evidence has not been put together yet. Use "Rebuild from the records" above.'
                    : $record->evidence->found().' of '.count($record->evidence->checklist ?? []).' found. Put together '
                        .CaseFile::at($record->evidence->built_at).($record->evidence->edited_at ? '; words last edited '.CaseFile::at($record->evidence->edited_at).' by '.($record->evidence->editor->name ?? 'staff') : '').'.')
                ->schema([
                    RepeatableEntry::make('checklist')
                        ->hiddenLabel()
                        ->state(fn (Dispute $record) => (array) ($record->evidence->checklist ?? []))
                        ->table([
                            TableColumn::make('Found')->hiddenHeaderLabel()->width('3rem'),
                            TableColumn::make('What'),
                            TableColumn::make('What the records say'),
                        ])
                        ->schema([
                            IconEntry::make('found')->boolean(),
                            TextEntry::make('label'),
                            TextEntry::make('detail')->placeholder('—'),
                        ])
                        ->placeholder('Nothing yet.'),
                ]),
        ];
    }

    /** @return list<Component> */
    public static function details(): array
    {
        return [
            Section::make('Documents')
                ->description(fn () => DisputeDesk::mayAnswer(self::staff())
                    ? 'Made from the records each time you open one, until they are sent; after that, these are the copies that went.'
                    : 'Admin and Finance can open these.')
                ->schema([
                    Actions::make(fn (Dispute $record) => self::documentActions($record))
                        ->visible(fn () => DisputeDesk::mayAnswer(self::staff())),
                    TextEntry::make('documents')
                        ->hiddenLabel()
                        ->state(fn (Dispute $record) => array_map(
                            fn (string $kind) => EvidenceDraft::DOCUMENTS[$kind] ?? $kind,
                            (array) ($record->evidence->files ?? []),
                        ))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('None yet.'),
                ]),

            Section::make('Visa Compelling Evidence 3.0')
                ->description('Stripe says this dispute could qualify. If our records establish it, Visa\'s own rules decide it for the organizer.')
                ->visible(fn (Dispute $record) => ($record->evidence->compelling_evidence ?? null) !== null)
                ->schema([
                    TextEntry::make('compelling_evidence')
                        ->label('Can our records establish it?')
                        ->state(fn (Dispute $record) => $record->evidence->compelling_evidence['why'] ?? null)
                        ->color(fn (Dispute $record) => ($record->evidence->compelling_evidence['eligible'] ?? false) ? 'success' : 'warning'),
                    RepeatableEntry::make('prior_payments')
                        ->label('The earlier payments that will be sent')
                        ->state(fn (Dispute $record) => (array) ($record->evidence->compelling_evidence['prior'] ?? []))
                        ->visible(fn (Dispute $record) => ($record->evidence->compelling_evidence['prior'] ?? []) !== [])
                        ->table([
                            TableColumn::make('Order'),
                            TableColumn::make('Paid'),
                            TableColumn::make('Stripe charge'),
                            TableColumn::make('Internet address'),
                        ])
                        ->schema([
                            TextEntry::make('reference')->fontFamily('mono'),
                            TextEntry::make('paid_at'),
                            TextEntry::make('charge')->fontFamily('mono'),
                            TextEntry::make('customer_purchase_ip'),
                        ]),
                ]),

            Section::make('What was sent')
                ->visible(fn (Dispute $record) => $record->evidence?->sent !== null)
                ->columns(2)
                ->schema([
                    TextEntry::make('sent_at')
                        ->label('Sent')
                        ->state(fn (Dispute $record) => CaseFile::at($record->evidence?->submitted_at).' by '.($record->responder->name ?? 'staff')),
                    TextEntry::make('sent_fields')
                        ->label('Fields')
                        ->state(fn (Dispute $record) => array_map(
                            fn (string $name) => EvidenceDraft::FIELDS[$name]['label'] ?? $name,
                            array_keys((array) ($record->evidence->sent['fields'] ?? [])),
                        ))
                        ->listWithLineBreaks(),
                    TextEntry::make('sent_files')
                        ->label('Documents, as the processor filed them')
                        ->state(fn (Dispute $record) => collect((array) ($record->evidence->sent['files'] ?? []))
                            ->map(fn ($file, $kind) => (EvidenceDraft::DOCUMENTS[$kind] ?? $kind).' — '.($file['handle'] ?? '?'))
                            ->values()
                            ->all())
                        ->listWithLineBreaks(),
                    TextEntry::make('sent_compelling')
                        ->label('Visa Compelling Evidence 3.0')
                        ->state(fn (Dispute $record) => ($record->evidence->sent['enhanced'] ?? []) !== [] ? 'Sent' : 'Not sent'),
                ]),

            Section::make('What the processor says')
                ->collapsed()
                ->columns(3)
                ->visible(fn (Dispute $record) => $record->gateway === 'stripe' && ($record->evidence->processor ?? null) !== null)
                ->schema([
                    TextEntry::make('has_evidence')
                        ->label('Has evidence')
                        ->state(fn (Dispute $record) => self::yesNo($record->evidence->processor['evidence_details']['has_evidence'] ?? null)),
                    TextEntry::make('submission_count')
                        ->label('Times answered')
                        ->state(fn (Dispute $record) => $record->evidence->processor['evidence_details']['submission_count'] ?? '—'),
                    TextEntry::make('is_charge_refundable')
                        ->label('Can still be refunded')
                        ->state(fn (Dispute $record) => self::yesNo($record->evidence->processor['is_charge_refundable'] ?? null))
                        ->helperText('An inquiry that is refunded now is closed without becoming a chargeback.'),
                    TextEntry::make('enhanced_eligibility_types')
                        ->label('Could also be answered with')
                        ->state(fn (Dispute $record) => array_map(
                            fn (string $type) => str_replace('_', ' ', $type),
                            (array) ($record->evidence->processor['enhanced_eligibility_types'] ?? []),
                        ))
                        ->listWithLineBreaks()
                        ->placeholder('Nothing more'),
                ]),

            AuditTrail::section(fn (Model $record) => AuditLog::query()->where('subject_type', $record::class)->where('subject_id', $record->getKey())),
        ];
    }

    /** Preview and download, per document, on links that last five minutes. */
    private static function documentActions(Dispute $record): array
    {
        $actions = [];

        foreach ((array) ($record->evidence->files ?? []) as $kind) {
            $label = EvidenceDraft::DOCUMENTS[$kind] ?? $kind;

            $actions[] = Action::make('preview_'.$kind)
                ->label($label)
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn () => URL::temporarySignedRoute('disputes.document', now()->addMinutes(5), ['dispute' => $record->id, 'file' => $kind]), shouldOpenInNewTab: true);

            $actions[] = Action::make('download_'.$kind)
                ->label('Download')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->iconButton()
                ->tooltip('Download '.$label)
                ->color('gray')
                ->url(fn () => URL::temporarySignedRoute('disputes.document', now()->addMinutes(5), ['dispute' => $record->id, 'file' => $kind, 'download' => 1]));
        }

        return $actions;
    }

    /** Red inside three days (or gone), amber inside a week. */
    public static function urgency(Dispute $record): string
    {
        $due = $record->evidence_due_at;

        if (! $record->isOpen() || $record->isAnswered() || $due === null) {
            return 'gray';
        }

        return match (true) {
            $due->lessThanOrEqualTo(now()->addDays(self::URGENT_DAYS)) => 'danger',
            $due->lessThanOrEqualTo(now()->addDays(7)) => 'warning',
            default => 'gray',
        };
    }

    public static function timeLeft(Dispute $record): ?string
    {
        $due = $record->evidence_due_at;

        if ($due === null || ! $record->isOpen() || $record->isAnswered()) {
            return null;
        }

        return $due->isPast()
            ? 'The deadline has passed.'
            : $due->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).' left';
    }

    public static function answer(Dispute $record): string
    {
        return match ($record->response) {
            Dispute::SUBMITTED => 'Evidence sent '.CaseFile::at($record->responded_at).' by '.($record->responder->name ?? 'staff'),
            Dispute::ACCEPTED => 'Accepted '.CaseFile::at($record->responded_at).' by '.($record->responder->name ?? 'staff'),
            default => $record->isOpen() ? 'Not answered yet' : 'Not answered',
        };
    }

    public static function outcome(Dispute $record): string
    {
        if ($record->isOpen()) {
            return $record->response === Dispute::SUBMITTED ? 'With the bank' : 'Open';
        }

        $said = $record->processor_status ? ' ('.ucfirst((string) $record->gateway).': '.str_replace(['_', '-'], ' ', $record->processor_status).')' : '';

        return match ($record->status) {
            'won' => 'Kept the money, '.CaseFile::at($record->closed_at).$said,
            'lost' => 'Money taken back, '.CaseFile::at($record->closed_at).$said,
            default => ucfirst((string) $record->status).$said,
        };
    }

    private static function yesNo(mixed $value): string
    {
        return match ($value) {
            true => 'Yes',
            false => 'No',
            default => '—',
        };
    }

    private static function staff(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
