<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Live connector status, aligned with OCPP 1.6 StatusNotification / 2.0.1 ConnectorStatus. */
enum ConnectorLiveStatus: string implements HasColor, HasLabel
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Preparing = 'preparing';
    case Charging = 'charging';
    case SuspendedEV = 'suspended_ev';
    case SuspendedEVSE = 'suspended_evse';
    case Finishing = 'finishing';
    case Occupied = 'occupied';
    case Faulted = 'faulted';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Reserved => 'Reserved',
            self::Preparing => 'Preparing',
            self::Charging => 'Charging',
            self::SuspendedEV => 'Paused by vehicle',
            self::SuspendedEVSE => 'Paused by charger',
            self::Finishing => 'Finishing',
            self::Occupied => 'Occupied',
            self::Faulted => 'Faulted',
            self::Unavailable => 'Offline',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Reserved => 'info',
            self::Preparing, self::Charging, self::SuspendedEV, self::SuspendedEVSE, self::Finishing, self::Occupied => 'warning',
            self::Faulted => 'danger',
            self::Unavailable => 'gray',
        };
    }

    /** In use right now (someone is plugged in). */
    public function isBusy(): bool
    {
        return in_array($this, [self::Preparing, self::Charging, self::SuspendedEV, self::SuspendedEVSE, self::Finishing, self::Occupied], true);
    }

    /** Cannot take any booking (hardware problem). */
    public function isDown(): bool
    {
        return in_array($this, [self::Faulted, self::Unavailable], true);
    }

    /** Map an OCPP status string (1.6 or 2.0.1) to ours. */
    public static function fromOcpp(string $status): self
    {
        return match (strtolower($status)) {
            'available' => self::Available,
            'reserved' => self::Reserved,
            'preparing' => self::Preparing,
            'charging' => self::Charging,
            'suspendedev' => self::SuspendedEV,
            'suspendedevse' => self::SuspendedEVSE,
            'finishing' => self::Finishing,
            'occupied' => self::Occupied,
            'faulted' => self::Faulted,
            default => self::Unavailable,
        };
    }
}
