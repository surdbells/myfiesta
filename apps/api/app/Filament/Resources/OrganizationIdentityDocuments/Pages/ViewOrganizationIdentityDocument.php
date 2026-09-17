<?php

namespace App\Filament\Resources\OrganizationIdentityDocuments\Pages;

use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use App\Models\OrganizationIdentityDocument;
use App\Models\SensitiveDataAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\URL;

/**
 * The one place identity data is decrypted, and therefore the one place it is
 * logged.
 *
 * Opening this page reads legal names, a date of birth, and a government
 * identifier. That is a legitimate action for someone in the review queue and
 * an illegitimate one for someone browsing, and the two are indistinguishable
 * without a record — so every view writes to SensitiveDataAccess before
 * anything is rendered.
 */
class ViewOrganizationIdentityDocument extends ViewRecord
{
    protected static string $resource = OrganizationIdentityDocumentResource::class;

    /**
     * Record the access first.
     *
     * Deliberately before render rather than after: a page that errors partway
     * through has still put the data in front of someone.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        SensitiveDataAccess::record(
            user: auth()->user(),
            subjectType: 'identity_document',
            subjectId: $this->record->getKey(),
            action: 'decrypted',
            ip: request()->ip(),
        );
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Organization')
                ->schema([
                    TextEntry::make('organization.name')->label('Name'),
                    TextEntry::make('document_type')->label('Document type')->badge(),
                    TextEntry::make('created_at')->label('Submitted')->dateTime(),
                ])
                ->columns(3),

            Section::make('Identity')
                ->description('Decrypted for this view. The access has been logged.')
                ->schema([
                    TextEntry::make('legal_first_name')->label('Legal first name')->placeholder('Not provided'),
                    TextEntry::make('legal_last_name')->label('Legal last name')->placeholder('Not provided'),
                    TextEntry::make('date_of_birth')->label('Date of birth')->placeholder('Not provided'),
                    TextEntry::make('document_number')->label('Document number')->placeholder('Not provided'),
                    TextEntry::make('expires_on')->label('Expires')->placeholder('Not provided'),
                ])
                ->columns(2),

            Section::make('Review')
                ->schema([
                    TextEntry::make('review_status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default => 'warning',
                        }),
                    TextEntry::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                    TextEntry::make('reviewed_at')->label('Reviewed at')->dateTime()->placeholder('—'),
                    TextEntry::make('review_note')->label('Note')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            // The document image lives on the private disk and is never
            // web-reachable. A short-lived signed URL is the only way to it,
            // and fetching one is logged separately from viewing the record.
            Action::make('viewDocument')
                ->label('Open document image')
                ->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (OrganizationIdentityDocument $record) => filled($record->document_path))
                ->action(function (OrganizationIdentityDocument $record) {
                    SensitiveDataAccess::record(
                        user: auth()->user(),
                        subjectType: 'identity_document',
                        subjectId: $record->getKey(),
                        action: 'viewed',
                        ip: request()->ip(),
                    );

                    $url = URL::temporarySignedRoute(
                        'identity-documents.show',
                        now()->addMinutes(5),
                        ['document' => $record->getKey()],
                    );

                    $this->redirect($url);
                }),

            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (OrganizationIdentityDocument $record) => $record->review_status === 'pending')
                ->requiresConfirmation()
                ->modalDescription('The organization will be marked verified.')
                ->action(function (OrganizationIdentityDocument $record) {
                    $record->update([
                        'review_status' => 'approved',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);

                    // The name is recorded with the moment, because the tick
                    // is only shown while the organization is still called
                    // what the documents said it was called.
                    $record->organization()->update([
                        'verified_at' => now(),
                        'verified_name' => $record->organization->name,
                    ]);

                    Notification::make()->title('Identity approved')->success()->send();
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (OrganizationIdentityDocument $record) => $record->review_status === 'pending')
                // A rejection the organizer cannot act on is a dead end, so the
                // reason is required rather than optional.
                ->schema([
                    Textarea::make('review_note')
                        ->label('Why is this being rejected?')
                        ->required()
                        ->minLength(10)
                        ->helperText('Sent to the organizer, so write it for them.'),
                ])
                ->action(function (OrganizationIdentityDocument $record, array $data) {
                    $record->update([
                        'review_status' => 'rejected',
                        'review_note' => $data['review_note'],
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ]);

                    Notification::make()->title('Identity rejected')->warning()->send();
                }),
        ];
    }
}
