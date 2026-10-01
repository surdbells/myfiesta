<?php

namespace App\Filament\Resources\HelpVideos;

use App\Enums\PlatformRole;
use App\Filament\Resources\HelpVideos\Pages\CreateHelpVideo;
use App\Filament\Resources\HelpVideos\Pages\EditHelpVideo;
use App\Filament\Resources\HelpVideos\Pages\ListHelpVideos;
use App\Filament\Resources\HelpVideos\Schemas\HelpVideoForm;
use App\Filament\Resources\HelpVideos\Tables\HelpVideosTable;
use App\Models\HelpVideo;
use App\Models\User;
use App\Services\Audit\Auditor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The how-to videos on help/videos.
 *
 * Staff add a video by pasting its YouTube address or id, check it, and
 * publish it; a video is a draft until then. Publishing, taking one down and
 * deleting each ask first and are written to the audit trail, because what
 * is published here is on a public page under the platform's name.
 */
class HelpVideoResource extends Resource
{
    protected static ?string $model = HelpVideo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'How-to videos';

    protected static ?string $modelLabel = 'how-to video';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return HelpVideoForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HelpVideosTable::configure($table);
    }

    /** Help content is support's to write, with admins. */
    public static function canBeManagedBy(?User $user): bool
    {
        return $user?->hasPlatformRole(PlatformRole::Admin, PlatformRole::Support) ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canBeManagedBy(auth()->user());
    }

    public static function canEdit($record): bool
    {
        return self::canBeManagedBy(auth()->user());
    }

    public static function canDelete($record): bool
    {
        return self::canBeManagedBy(auth()->user());
    }

    /** One at a time, each asked about by name. */
    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * The same answers for the buttons as for the pages.
     *
     * Filament asks this, not the can* methods above, when it decides whether
     * to show New, Edit or Delete — and with no policy for videos it would
     * otherwise say yes to anybody on staff.
     */
    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        $allowed = match ($action) {
            'viewAny', 'view' => $user instanceof User && $user->isPlatformStaff(),
            'create', 'update', 'delete' => self::canBeManagedBy($user instanceof User ? $user : null),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    /**
     * What happened to a video, and who did it, in the audit trail.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, HelpVideo $video, array $metadata = []): void
    {
        $staff = auth()->user();

        app(Auditor::class)->record($action, $video, $staff instanceof User ? $staff : null, metadata: [
            'title' => $video->title,
            'youtube_id' => $video->youtube_id,
            ...$metadata,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHelpVideos::route('/'),
            'create' => CreateHelpVideo::route('/create'),
            'edit' => EditHelpVideo::route('/{record}/edit'),
        ];
    }
}
