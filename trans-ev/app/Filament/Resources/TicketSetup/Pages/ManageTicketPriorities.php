<?php

namespace App\Filament\Resources\TicketSetup\Pages;

use App\Filament\Resources\TicketSetup\TicketPriorityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTicketPriorities extends ManageRecords
{
    protected static string $resource = TicketPriorityResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
