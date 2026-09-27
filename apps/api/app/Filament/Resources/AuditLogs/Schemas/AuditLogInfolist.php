<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Filament\Resources\AuditLogs\AuditEntries;
use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

/** One audit entry, in full. Nothing on it can be changed. */
final class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('What happened')
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('action')->badge()->color('gray'),
                        TextEntry::make('created_at')->label('When (UTC)')->dateTime('j M Y, H:i:s'),
                        TextEntry::make('who')
                            ->label('Who')
                            ->state(fn (AuditLog $record): string => $record->actorName()
                                .($record->actor?->platform_role !== null ? ' (myFiesta staff, '.$record->actor->platform_role->label().')' : '')),
                        TextEntry::make('actor_label')
                            ->label('Name recorded at the time')
                            ->placeholder('—'),
                        TextEntry::make('subject')
                            ->label('About')
                            ->state(fn (AuditLog $record): string => $record->subject_type === null
                                ? '—'
                                : class_basename($record->subject_type).' '.$record->subject_id)
                            ->url(fn (AuditLog $record): ?string => AuditEntries::subjectUrl($record))
                            ->fontFamily(FontFamily::Mono),
                        TextEntry::make('organization.name')
                            ->label('Organization')
                            ->placeholder('None — a platform action'),
                        TextEntry::make('ip_address')
                            ->label('From address')
                            ->placeholder('Not recorded (scheduler or console)'),
                        TextEntry::make('id')
                            ->label('Entry id')
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                    ]),
                Section::make('Details')
                    ->description('The metadata as it was written. Anything named like a credential, a ticket code or an account number is not shown.')
                    ->schema([
                        // The figures in it as money: amounts are recorded in
                        // minor units, and "500000" beside "NGN" is ₦5,000.
                        TextEntry::make('amounts')
                            ->label('Amounts in it')
                            ->state(fn (AuditLog $record): array => AuditEntries::amounts($record->metadata))
                            ->listWithLineBreaks()
                            ->visible(fn (AuditLog $record): bool => AuditEntries::amounts($record->metadata) !== []),
                        TextEntry::make('metadata')
                            ->hiddenLabel()
                            ->state(fn (AuditLog $record): string => AuditEntries::json($record->metadata))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(['style' => 'white-space: pre-wrap; overflow-wrap: anywhere;'])
                            ->copyable(),
                    ]),
            ]);
    }
}
