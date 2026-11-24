<?php

namespace App\Services;

use App\Enums\ConnectorLiveStatus;
use App\Enums\StationStatus;
use App\Models\Connector;
use App\Models\ConnectorStatusHistory;
use Illuminate\Support\Facades\DB;

/** The only place connector statuses change; every change is written to the append-only history. */
class ConnectorStatusService
{
    public function setLive(Connector $connector, ConnectorLiveStatus $to, string $source = 'admin', ?string $note = null, ?int $userId = null): void
    {
        DB::transaction(function () use ($connector, $to, $source, $note, $userId) {
            $from = $connector->live_status;
            if ($from === $to && $note === $connector->live_note) {
                return;
            }
            $connector->forceFill(['live_status' => $to, 'live_status_at' => now(), 'live_note' => $note])->save();
            ConnectorStatusHistory::create([
                'connector_id' => $connector->id, 'field' => 'live', 'from_status' => $from?->value, 'to_status' => $to->value,
                'source' => $source, 'note' => $note, 'changed_by' => $userId ?? ($source === 'admin' ? auth()->id() : null),
            ]);
        });
    }

    public function setAdmin(Connector $connector, StationStatus $to, ?string $note = null, ?int $userId = null): void
    {
        DB::transaction(function () use ($connector, $to, $note, $userId) {
            $from = $connector->status;
            if ($from === $to) {
                return;
            }
            $connector->forceFill(['status' => $to])->save();
            ConnectorStatusHistory::create([
                'connector_id' => $connector->id, 'field' => 'admin', 'from_status' => $from?->value, 'to_status' => $to->value,
                'source' => 'admin', 'note' => $note, 'changed_by' => $userId ?? auth()->id(),
            ]);
        });
    }
}
