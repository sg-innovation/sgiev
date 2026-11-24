<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('resubmit')->label('Resubmit for review')->icon('heroicon-m-paper-airplane')->color('success')
                ->visible(fn () => $this->record->status === 'changes_requested' && auth()->user()->can('update', $this->record))
                ->requiresConfirmation()
                ->action(function () {
                    app(\App\Services\ApprovalService::class)->resubmitCompany($this->record, auth()->user());
                    \Filament\Notifications\Notification::make()->success()->title('Sent for review')->send();
                    $this->redirect(static::getResource()::getUrl('edit', ['record' => $this->record]));
                }),
        ];
    }
}
