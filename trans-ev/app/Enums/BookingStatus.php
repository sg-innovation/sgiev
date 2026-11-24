<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasLabel
{
    case Held = 'held';              // slot reserved, awaiting payment (expires)
    case Confirmed = 'confirmed';    // paid
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';        // hold ran out before payment
    case NoShow = 'no_show';

    /** Statuses that occupy the charger. */
    public static function active(): array
    {
        return [self::Held, self::Confirmed, self::CheckedIn];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Held => 'Awaiting payment',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Charging',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::NoShow => 'No-show',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Held => 'warning',
            self::Confirmed, self::CheckedIn => 'success',
            self::Completed => 'info',
            self::Cancelled, self::Expired, self::NoShow => 'gray',
        };
    }
}
