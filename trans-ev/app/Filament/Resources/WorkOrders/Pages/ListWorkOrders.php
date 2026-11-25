<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New work order')];
    }

    public function getSubheading(): ?string
    {
        return 'Faults, repairs and service on each charger. Open work orders show first.';
    }
}
