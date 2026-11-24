<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConnectorType: string implements HasLabel
{
    case CCS2 = 'ccs2';
    case Type2 = 'type2';
    case CHAdeMO = 'chademo';
    case BharatAC001 = 'bharat_ac001';
    case BharatDC001 = 'bharat_dc001';
    case GBT = 'gbt';
    case Type6 = 'type6'; // LEV / 2-wheeler socket (IS 17017-2-6)

    public function getLabel(): string
    {
        return match ($this) {
            self::CCS2 => 'CCS2',
            self::Type2 => 'Type 2 (AC)',
            self::CHAdeMO => 'CHAdeMO',
            self::BharatAC001 => 'Bharat AC-001',
            self::BharatDC001 => 'Bharat DC-001',
            self::GBT => 'GB/T',
            self::Type6 => 'Type 6 (LEV)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->getLabel()])->all();
    }
}
