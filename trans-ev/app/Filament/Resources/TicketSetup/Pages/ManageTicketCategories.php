<?php

namespace App\Filament\Resources\TicketSetup\Pages;

use App\Filament\Resources\TicketSetup\TicketCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketCategories extends ManageRecords
{
    protected static string $resource = TicketCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
