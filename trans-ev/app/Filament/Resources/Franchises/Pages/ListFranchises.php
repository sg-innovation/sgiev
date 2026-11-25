<?php

namespace App\Filament\Resources\Franchises\Pages;

use App\Filament\Resources\Franchises\FranchiseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFranchises extends ListRecords
{
    protected static string $resource = FranchiseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add franchise')->visible(fn () => FranchiseResource::canManage())];
    }

    public function getSubheading(): ?string
    {
        return 'Regional partners over a group of stations. They earn a commission on net sales, paid monthly.';
    }
}
