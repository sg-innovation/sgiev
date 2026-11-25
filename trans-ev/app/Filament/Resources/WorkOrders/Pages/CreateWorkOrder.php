<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Charger;
use App\Services\ChargingSessionService;
use App\Services\WorkOrderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $charger = Charger::query()->findOrFail($data['charger_id']);
        abort_unless(ChargingSessionService::worksAt(auth()->user(), $charger->station_id), 403);

        return app(WorkOrderService::class)->open($charger, $data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return WorkOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
