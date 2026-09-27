<?php

namespace App\Filament\Support;

use App\Models\AuditLog;
use Closure;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The latest entries in the audit trail about one record, as a section of its
 * page.
 *
 * Read-only by construction: the trail is append-only in the database, and
 * nothing here offers to change it. Details are the entry's own metadata with
 * anything that looks like a credential left out, so a note written into the
 * trail by some future caller cannot put a token on a support screen.
 */
final class AuditTrail
{
    private const LIMIT = 25;

    private const NEVER_SHOWN = '/token|password|secret|ticket_code|account_number/i';

    /**
     * @param  Closure(Model): Builder<AuditLog>  $entries  the entries that belong on this page
     */
    public static function section(Closure $entries, string $heading = 'Audit trail'): Section
    {
        return Section::make($heading)
            ->description('Newest first, the last '.self::LIMIT.'. Times are UTC. The trail cannot be edited.')
            ->collapsible()
            ->schema([
                RepeatableEntry::make('audit_trail')
                    ->hiddenLabel()
                    ->state(fn (Model $record): array => self::rows($entries($record)))
                    ->placeholder('Nothing recorded yet.')
                    ->table([
                        TableColumn::make('When'),
                        TableColumn::make('Who'),
                        TableColumn::make('What'),
                        TableColumn::make('Details'),
                    ])
                    ->schema([
                        TextEntry::make('when'),
                        TextEntry::make('who'),
                        TextEntry::make('what')->badge()->color('gray'),
                        TextEntry::make('details')->placeholder('—'),
                    ]),
            ]);
    }

    /** @return list<array{when: string, who: string, what: string, details: string}> */
    public static function rows(Builder $query): array
    {
        return $query
            ->with('actor:id,name')
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (AuditLog $log) => [
                'when' => $log->created_at?->format('j M Y, H:i') ?? '—',
                'who' => $log->actorName(),
                'what' => $log->action,
                'details' => self::describe($log->metadata ?? []),
            ])
            ->values()
            ->all();
    }

    /** Entries whose subject is this record. */
    public static function about(Model $record): Builder
    {
        return AuditLog::query()
            ->where('subject_type', $record::class)
            ->where('subject_id', $record->getKey());
    }

    private static function describe(array $metadata): string
    {
        return collect($metadata)
            ->reject(fn ($value, $key) => preg_match(self::NEVER_SHOWN, (string) $key) === 1)
            ->map(fn ($value, $key) => str_replace('_', ' ', (string) $key).': '.match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'yes' : 'no',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            })
            ->implode(' · ');
    }
}
