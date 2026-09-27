<?php

namespace App\Filament\Resources\Organizations\Schemas;

use App\Enums\Role;
use App\Filament\Support\AuditTrail;
use App\Filament\Support\Listing;
use App\Models\Event;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\OrganizationPayoutDetail;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One organization: whether we are selling for it, who runs it, what it
 * sells, what it is owed, and what has been done to it.
 *
 * The suspension, when there is one, comes first and says everything an
 * operator is asked about it — since when, by whom, why, and whether the
 * organization was shown the reason. Payout details appear only to the roles
 * that see them elsewhere in the panel, and only masked: the last four digits
 * are all anybody here needs to tell one account from another.
 */
class OrganizationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Suspended')
                ->icon('heroicon-o-no-symbol')
                ->iconColor('danger')
                ->description('Nothing is on sale and payouts are frozen. Tickets already sold still work at the door, and refunds can still be made.')
                ->visible(fn (Organization $record) => $record->isSuspended())
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('suspension_reason')
                        ->label('Reason')
                        ->columnSpan(['default' => 1, 'md' => 2])
                        ->helperText(fn (Organization $record) => $record->suspension_reason_shared
                            ? 'Shown to the organization in its console and the email to its owners.'
                            : 'Kept to myFiesta. The organization is told it is suspended, not why.'),
                    TextEntry::make('suspended_at')
                        ->label('Since')
                        ->dateTime('j M Y, H:i')
                        ->helperText(fn (Organization $record) => 'By '.($record->suspender?->name ?? 'a former member of staff').' · UTC'),
                    TextEntry::make('held_off_sale')
                        ->label('Events it took off sale')
                        ->state(fn (Organization $record) => number_format(Event::query()
                            ->where('organization_id', $record->id)
                            ->whereNotNull('unpublished_by_suspension_at')
                            ->count()))
                        ->helperText('Back on sale when it is lifted, unless they have happened by then.'),
                    TextEntry::make('held_requests')
                        ->label('Payout requests held')
                        ->state(fn (Organization $record) => number_format(PayoutRequest::query()
                            ->where('organization_id', $record->id)
                            ->where('status', 'pending')
                            ->whereNotNull('held_at')
                            ->count())),
                ]),

            Section::make('Key numbers')
                ->columns(['default' => 2, 'md' => 4])
                ->schema([
                    TextEntry::make('members_total')
                        ->label('Members')
                        ->state(fn (Organization $record) => number_format($record->members()->count()))
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('events_total')
                        ->label('Events')
                        ->state(fn (Organization $record) => number_format($record->events()->count()))
                        ->helperText(fn (Organization $record) => number_format($record->events()->where('starts_at', '>=', now())->count()).' still to come')
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('on_sale_total')
                        ->label('On sale now')
                        ->state(fn (Organization $record) => number_format($record->events()->where('status', 'published')->count()))
                        ->size('lg')
                        ->weight('semibold'),
                    TextEntry::make('owed')
                        ->label('Owed')
                        ->state(fn (Organization $record) => self::owed($record))
                        ->helperText('Per currency. Never added across them.')
                        ->size('lg')
                        ->weight('semibold'),
                ]),

            Section::make('Organization')
                ->columns(['default' => 1, 'md' => 3])
                ->collapsible()
                ->schema([
                    TextEntry::make('name')->weight('medium'),
                    TextEntry::make('slug')->label('Public page')->prefix('/o/')->copyable(),
                    TextEntry::make('verification')
                        ->label('Verification')
                        ->badge()
                        ->state(fn (Organization $record) => self::verification($record))
                        ->color(fn (string $state) => match ($state) {
                            'Verified' => 'success',
                            'Renamed since verification', 'Documents waiting for review' => 'warning',
                            default => 'gray',
                        })
                        ->helperText(fn (Organization $record) => $record->awaitsRenameCheck()
                            ? 'Verified as “'.$record->verified_name.'”.'
                            : null),
                    TextEntry::make('owners')
                        ->label('Owners')
                        ->state(fn (Organization $record) => $record->members()
                            ->wherePivot('role', Role::Owner->value)
                            ->orderBy('users.name')
                            ->get(['users.id', 'users.name', 'users.email'])
                            ->map(fn (User $owner) => $owner->name.' <'.$owner->email.'>')
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('No owner'),
                    TextEntry::make('contact_email')->label('Contact email')->placeholder('—')->copyable(),
                    TextEntry::make('contact_phone')->label('Contact phone')->placeholder('—'),
                    TextEntry::make('markets')
                        ->label('Sells in')
                        ->state(fn (Organization $record) => self::markets($record))
                        ->placeholder('No events yet'),
                    TextEntry::make('created_at')->label('Joined')->dateTime('j M Y'),
                    TextEntry::make('deleted_at')
                        ->label('Deleted')
                        ->dateTime('j M Y, H:i')
                        ->visible(fn (Organization $record) => $record->trashed()),
                ]),

            // The same roles that open payout details, settlements and payout
            // requests elsewhere in the panel.
            Section::make('Payouts')
                ->visible(fn () => (bool) auth()->user()?->platform_role?->canSettle())
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('payout_details')
                        ->label('Where payouts go')
                        ->state(fn (Organization $record) => OrganizationPayoutDetail::query()
                            ->where('organization_id', $record->id)
                            ->orderBy('currency')
                            ->get()
                            ->map(fn (OrganizationPayoutDetail $detail) => [
                                'currency' => $detail->currency,
                                'destination' => $detail->maskedDestination(),
                                'verified' => $detail->isVerified() ? 'Verified' : 'Not verified',
                            ])
                            ->all())
                        ->placeholder('No payout details on file.')
                        ->table([
                            TableColumn::make('Currency'),
                            TableColumn::make('Pays to'),
                            TableColumn::make('Checked'),
                        ])
                        ->schema([
                            TextEntry::make('currency'),
                            TextEntry::make('destination'),
                            TextEntry::make('verified')->badge()->color(fn (string $state) => $state === 'Verified' ? 'success' : 'warning'),
                        ]),
                    TextEntry::make('waiting_requests')
                        ->label('Payout requests waiting')
                        ->state(fn (Organization $record) => PayoutRequest::query()
                            ->where('organization_id', $record->id)
                            ->where('status', 'pending')
                            ->get(['amount', 'currency', 'held_at'])
                            ->map(fn (PayoutRequest $request) => $request->money()->format().($request->held_at ? ' (held)' : ''))
                            ->all())
                        ->listWithLineBreaks()
                        ->placeholder('None'),
                ]),

            Section::make('Members')
                ->collapsible()
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('member_rows')
                        ->hiddenLabel()
                        ->state(fn (Organization $record) => $record->members()
                            ->orderBy('users.name')
                            ->get()
                            ->map(fn (User $member) => [
                                'name' => $member->name,
                                'email' => $member->email,
                                'role' => self::role($member->pivot->role),
                                'since' => $member->pivot->accepted_at ? Carbon::parse($member->pivot->accepted_at)->format('j M Y') : 'Invited',
                            ])
                            ->all())
                        ->placeholder('Nobody.')
                        ->table([
                            TableColumn::make('Name'),
                            TableColumn::make('Email'),
                            TableColumn::make('Role'),
                            TableColumn::make('Since'),
                        ])
                        ->schema([
                            TextEntry::make('name')->weight('medium'),
                            TextEntry::make('email'),
                            TextEntry::make('role')->badge()->color('gray'),
                            TextEntry::make('since'),
                        ]),
                ]),

            AuditTrail::section(fn (Organization $record) => AuditTrail::about($record)),
        ]);
    }

    /** Verified, renamed, waiting on documents, or none of those — the same states the list filters by. */
    public static function verification(Organization $organization): string
    {
        return match (true) {
            $organization->isVerified() => 'Verified',
            $organization->awaitsRenameCheck() => 'Renamed since verification',
            $organization->identityDocuments()->where('review_status', 'pending')->exists() => 'Documents waiting for review',
            default => 'Not verified',
        };
    }

    /** The custom pivot casts the role; a membership loaded another way hands back the string. */
    private static function role(Role|string|null $role): string
    {
        $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);

        return $role?->label() ?? '—';
    }

    /** The currencies and countries its events are in: an organization has none of its own. */
    private static function markets(Organization $organization): ?string
    {
        $rows = DB::table('events')
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->select('country', 'currency')
            ->distinct()
            ->orderBy('country')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(fn (object $row) => $row->country.' ('.$row->currency.')')->implode(' · ');
    }

    private static function owed(Organization $organization): string
    {
        $owed = collect(LedgerEntry::balancesFor($organization))
            ->reject(fn (Money $money) => $money->isZero())
            ->map(fn (Money $money) => Listing::format($money->amount, $money->currency))
            ->implode(' · ');

        return $owed !== '' ? $owed : 'Nothing';
    }
}
