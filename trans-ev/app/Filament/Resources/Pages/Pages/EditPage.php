<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label('View on website')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                ->url(fn () => PageResource::url($this->record), shouldOpenInNewTab: true),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->changeNote = $this->data['change_note'] ?? null;
        $record->update($data);

        return $record;
    }
}
