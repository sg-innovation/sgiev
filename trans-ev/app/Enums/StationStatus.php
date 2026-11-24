<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum StationStatus: string implements HasColor, HasIcon, HasLabel
{
    case Online = 'online';
    case Offline = 'offline';
    case Maintenance = 'maintenance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Maintenance => 'Maintenance',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'danger',
            self::Maintenance => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Online => 'heroicon-m-bolt',
            self::Offline => 'heroicon-m-no-symbol',
            self::Maintenance => 'heroicon-m-wrench-screwdriver',
        };
    }

    public function isBookable(): bool
    {
        return $this === self::Online;
    }
}
