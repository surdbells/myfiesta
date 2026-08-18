<?php

namespace App\Filament\Resources\OrganizationIdentityDocuments\Pages;

use App\Filament\Resources\OrganizationIdentityDocuments\OrganizationIdentityDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListOrganizationIdentityDocuments extends ListRecords
{
    protected static string $resource = OrganizationIdentityDocumentResource::class;

    /** Nothing is created here. Documents arrive from organizers. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
