<?php

namespace App\Services;

use App\Enums\StationStatus;
use App\Models\Charger;
use App\Models\ChargerWorkOrder;
use App\Models\User;
use App\Notifications\WorkOrderUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charger maintenance work orders. Opening one can take the charger out of service (status Maintenance, so it can't
 * be booked); finishing or cancelling the last such order puts it back online. Downtime is measured while it was
 * out of service. Every change is in the audit log; the assignee is emailed.
 */
class WorkOrderService
{
    public function __construct(private readonly StationStatusService $status) {}

    /** @param array{type: string, priority?: string, title: string, description?: ?string, connector_id?: ?int, assigned_to?: ?int, vendor?: ?string, due_at?: mixed, take_offline?: bool} $data */
    public function open(Charger $charger, array $data, ?User $by): ChargerWorkOrder
    {
        return DB::transaction(function () use ($charger, $data, $by) {
            $offline = (bool) ($data['take_offline'] ?? false);
            $wo = ChargerWorkOrder::create([
                'charger_id' => $charger->id, 'connector_id' => $data['connector_id'] ?? null,
                'type' => $data['type'], 'priority' => $data['priority'] ?? 'normal', 'status' => 'open',
                'title' => $data['title'], 'description' => $data['description'] ?? null,
                'took_offline' => $offline, 'reported_by' => $by?->id, 'assigned_to' => $data['assigned_to'] ?? null,
                'vendor' => $data['vendor'] ?? null, 'due_at' => $data['due_at'] ?? null, 'opened_at' => now(),
            ]);
            if ($offline && $charger->status !== StationStatus::Maintenance) {
                $this->status->change($charger, StationStatus::Maintenance, "Work order {$wo->fresh()->number}: {$wo->title}", null, $by?->id);
            }
            DB::afterCommit(fn () => $this->notifyAssignee($wo->fresh(), $by));

            return $wo->fresh();
        });
    }

    public function start(ChargerWorkOrder $wo, User $by): void
    {
        $this->guardActive($wo);
        $wo->forceFill(['status' => 'in_progress', 'started_at' => $wo->started_at ?? now()])->save();
    }

    public function hold(ChargerWorkOrder $wo, User $by, ?string $note = null): void
    {
        $this->guardActive($wo);
        $wo->forceFill(['status' => 'on_hold'] + ($note ? ['resolution' => trim(($wo->resolution ? $wo->resolution."\n" : '').'On hold: '.$note)] : []))->save();
    }

    public function assign(ChargerWorkOrder $wo, ?User $to, User $by): void
    {
        $wo->forceFill(['assigned_to' => $to?->id])->save();
        $this->notifyAssignee($wo->fresh(), $by);
    }

    /** @param array{resolution: string, labour_cost?: mixed, parts_cost?: mixed, parts_used?: ?string} $data */
    public function complete(ChargerWorkOrder $wo, array $data, User $by): void
    {
        $this->guardActive($wo);
        DB::transaction(function () use ($wo, $data, $by) {
            $now = now();
            $wo->forceFill([
                'status' => 'done', 'completed_at' => $now, 'started_at' => $wo->started_at ?? $wo->opened_at,
                'resolution' => $data['resolution'], 'labour_cost' => $data['labour_cost'] ?? null, 'parts_cost' => $data['parts_cost'] ?? null,
                'parts_used' => $data['parts_used'] ?? null,
                'downtime_minutes' => $wo->took_offline ? (int) round(($now->getTimestamp() - $wo->opened_at->getTimestamp()) / 60) : null,
            ])->save();
            $this->restoreCharger($wo, $by, 'Work order '.$wo->number.' done');
        });
    }

    public function cancel(ChargerWorkOrder $wo, User $by, string $reason): void
    {
        $this->guardActive($wo);
        DB::transaction(function () use ($wo, $by, $reason) {
            $now = now();
            $wo->forceFill(['status' => 'cancelled', 'completed_at' => $now, 'resolution' => 'Cancelled: '.$reason,
                'downtime_minutes' => $wo->took_offline ? (int) round(($now->getTimestamp() - $wo->opened_at->getTimestamp()) / 60) : null])->save();
            $this->restoreCharger($wo, $by, 'Work order '.$wo->number.' cancelled');
        });
    }

    /** Back online when this order took it out of service and no other open order still needs it out. */
    private function restoreCharger(ChargerWorkOrder $wo, User $by, string $reason): void
    {
        $charger = $wo->charger()->first();
        if (! $wo->took_offline || ! $charger || $charger->status !== StationStatus::Maintenance) {
            return;
        }
        $others = ChargerWorkOrder::query()->where('charger_id', $charger->id)->whereKeyNot($wo->id)
            ->where('took_offline', true)->whereIn('status', ChargerWorkOrder::ACTIVE)->exists();
        if (! $others) {
            $this->status->change($charger, StationStatus::Online, $reason, null, $by->id);
        }
    }

    private function guardActive(ChargerWorkOrder $wo): void
    {
        if (! $wo->isActive()) {
            throw ValidationException::withMessages(['status' => 'This work order is already '.strtolower(ChargerWorkOrder::STATUSES[$wo->status]).'.']);
        }
    }

    private function notifyAssignee(ChargerWorkOrder $wo, ?User $by): void
    {
        if ($wo->assignee && $wo->assignee->id !== $by?->id) {
            $wo->assignee->notify(new WorkOrderUpdate($wo, 'maintenance.assigned'));
        }
    }

    /** Uptime figures for a charger over the last N days (from completed work orders that took it offline). */
    public static function downtimeMinutes(Charger $charger, int $days = 30): int
    {
        return (int) ChargerWorkOrder::query()->where('charger_id', $charger->id)->where('took_offline', true)
            ->where('opened_at', '>=', now()->subDays($days))->sum('downtime_minutes');
    }
}
