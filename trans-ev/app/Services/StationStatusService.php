<?php

namespace App\Services;

use App\Enums\StationStatus;
use App\Models\Charger;
use App\Models\Station;
use App\Models\StatusLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place station / charger status changes. Every change writes a StatusLog row.
 * Phase 2 will also notify and re-schedule bookings affected by Offline / Maintenance windows.
 */
class StationStatusService
{
    public function change(
        Station|Charger $target,
        StationStatus $to,
        ?string $reason = null,
        ?CarbonInterface $until = null,
        ?int $userId = null,
        ?string $ip = null,
    ): void {
        if ($to !== StationStatus::Online && blank($reason)) {
            throw new InvalidArgumentException('A reason is required when taking a station or charger out of service.');
        }
        if ($until !== null && $until->isPast()) {
            throw new InvalidArgumentException('The "until" time must be in the future.');
        }

        DB::transaction(function () use ($target, $to, $reason, $until, $userId, $ip) {
            $from = $target->status;

            $attributes = [
                'status' => $to,
                'status_reason' => $to === StationStatus::Online ? null : $reason,
            ];
            if ($target instanceof Station) {
                $attributes['status_until'] = $to === StationStatus::Online ? null : $until;
            }
            $target->forceFill($attributes)->save();

            StatusLog::create([
                'loggable_type' => $target->getMorphClass(),
                'loggable_id' => $target->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'until' => $until,
                'changed_by' => $userId ?? auth()->id(),
                'ip' => $ip ?? request()?->ip(),
            ]);
        });
    }

    /** Scheduled: bring stations back online when their Offline / Maintenance window has ended. */
    public function revertExpired(): int
    {
        $count = 0;
        Station::query()
            ->whereIn('status', [StationStatus::Offline, StationStatus::Maintenance])
            ->whereNotNull('status_until')
            ->where('status_until', '<=', now())
            ->each(function (Station $station) use (&$count) {
                $this->change($station, StationStatus::Online, 'Scheduled window ended (automatic)', null, null, 'system');
                $count++;
            });

        return $count;
    }
}
