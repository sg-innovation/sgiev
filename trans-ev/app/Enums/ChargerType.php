<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ChargerType: string implements HasColor, HasLabel
{
    case AC = 'AC';
    case DC = 'DC';

    public function getLabel(): string
    {
        return match ($this) {
            self::AC => 'AC (slow / normal)',
            self::DC => 'DC (fast)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AC => 'info',
            self::DC => 'success',
        };
    }
}
