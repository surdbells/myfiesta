<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\PlatformRole;
use App\Enums\Role;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Support\AuditTrail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * One person, as support needs to see them.
 *
 * Their details, the organizations they belong to and in what role, and what
 * has happened to the account. Orders and tickets are the tables beneath,
 * paginated, because a regular can have hundreds.
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->columns(3)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('phone')->placeholder('—')->copyable(),

                    TextEntry::make('account_status')
                        ->label('Account')
                        ->badge()
                        ->state(fn (User $record) => match (true) {
                            str_ends_with(strtolower($record->email), '@erased.invalid') => 'Erased at their request',
                            $record->trashed() => 'Deactivated '.$record->deleted_at->format('j M Y'),
                            $record->isUnclaimed() => 'Guest checkout only — no password',
                            default => 'Active',
                        })
                        ->color(fn (User $record) => $record->trashed() ? 'danger' : ($record->isUnclaimed() ? 'gray' : 'success')),

                    TextEntry::make('platform_role')
                        ->label('Platform staff')
                        ->badge()
                        ->formatStateUsing(fn (?PlatformRole $state) => $state?->label())
                        ->placeholder('Not staff'),

                    TextEntry::make('email_verified_at')
                        ->label('Email verified')
                        ->dateTime('j M Y, H:i')
                        ->placeholder('Not verified'),

                    TextEntry::make('created_at')->label('Joined')->dateTime('j M Y, H:i'),
                    TextEntry::make('last_login_at')->label('Last signed in')->since()->placeholder('Never'),

                    TextEntry::make('last_seen')
                        ->label('Last seen in an app')
                        ->state(fn (User $record) => UsersTable::lastSeen($record->loadMax('tokens', 'last_used_at')))
                        ->since()
                        ->placeholder('Never'),

                    TextEntry::make('locale')->placeholder('—'),
                    TextEntry::make('timezone')->placeholder('—'),
                ]),

            Section::make('Organizations and roles')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('memberships')
                        ->hiddenLabel()
                        ->state(fn (User $record) => $record->organizations()
                            ->withTrashed()
                            ->orderBy('organizations.name')
                            ->get()
                            ->map(fn (Organization $organization) => [
                                'name' => $organization->name,
                                'role' => self::roleLabel($organization->pivot->role),
                                'joined' => $organization->pivot->accepted_at
                                    ? 'Joined '.Carbon::parse($organization->pivot->accepted_at)->format('j M Y')
                                    : 'Invited, not accepted',
                                'state' => $organization->trashed() ? 'Organization closed' : ($organization->isVerified() ? 'Verified' : 'Not verified'),
                            ])
                            ->all())
                        ->placeholder('Not a member of any organization.')
                        ->table([
                            TableColumn::make('Organization'),
                            TableColumn::make('Role'),
                            TableColumn::make('Membership'),
                            TableColumn::make('Organization status'),
                        ])
                        ->schema([
                            TextEntry::make('name')->weight('medium'),
                            TextEntry::make('role')->badge(),
                            TextEntry::make('joined'),
                            TextEntry::make('state')->color('gray'),
                        ]),
                ]),

            AuditTrail::section(fn (User $record) => self::history($record), 'Account history'),
        ]);
    }

    /**
     * What was done to the account, and, for those who may read the whole
     * trail, what the account did.
     *
     * An account's own actions reach across the platform: a finance
     * colleague's reveals of bank details, payouts marked paid, the reasons
     * given for an impersonation. That is the platform-wide audit log, which
     * support may not open (AuditLogResource::ROLES), so it is not shown to
     * them here either. Support see this record's own trail, as on every
     * other page.
     *
     * @return Builder<AuditLog>
     */
    public static function history(User $record, ?User $viewer = null): Builder
    {
        $viewer ??= auth()->user();

        if (! $viewer instanceof User || ! $viewer->hasPlatformRole(...AuditLogResource::ROLES)) {
            return AuditTrail::about($record);
        }

        return AuditLog::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $about) => $about->where('subject_type', User::class)->where('subject_id', $record->id))
            ->orWhere('actor_id', $record->id));
    }

    private static function roleLabel(mixed $role): string
    {
        if ($role instanceof Role) {
            return $role->label();
        }

        return Role::tryFrom((string) $role)?->label() ?? (string) $role;
    }
}
