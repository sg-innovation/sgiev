<?php

namespace App\Filament\Resources\Stations\Pages;

use App\Filament\Resources\Stations\Actions\ChangeStatusAction;
use App\Filament\Resources\Stations\StationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditStation extends EditRecord
{
    protected static string $resource = StationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChangeStatusAction::make()->after(fn () => $this->refreshFormData(['status'])),
            Action::make('viewOnSite')->label('View on website')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                ->url(fn () => route('stations.show', $this->record), shouldOpenInNewTab: true),
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }
}
