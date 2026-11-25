<?php

namespace App\Services\Ocpp;

/**
 * Turns OCPP MeterValues (1.6 sampledValue / 2.0.1 sampledValue with unitOfMeasure) into one reading:
 * energy register (kWh), power (kW), SoC (%), voltage (V), current (A) and any signed meter data.
 */
final class MeterReading
{
    public function __construct(
        public ?string $registerKwh = null,
        public ?string $powerKw = null,
        public ?int $soc = null,
        public ?string $voltage = null,
        public ?string $current = null,
        public ?string $signed = null,
        public ?string $context = null,
        public ?string $timestamp = null,
    ) {}

    /** @param list<array> $meterValues */
    public static function from(array $meterValues): self
    {
        $r = new self;
        foreach ($meterValues as $mv) {
            $r->timestamp = $mv['timestamp'] ?? $r->timestamp;
            foreach ((array) ($mv['sampledValue'] ?? []) as $sv) {
                $measurand = $sv['measurand'] ?? 'Energy.Active.Import.Register';
                $r->context = $sv['context'] ?? $r->context;
                $phase = $sv['phase'] ?? null;
                // 1.6: "format": "SignedData" puts the signed blob in "value"; 2.0.1 has "signedMeterValue".
                if (($sv['format'] ?? null) === 'SignedData') {
                    $r->signed = (string) ($sv['value'] ?? '');

                    continue;
                }
                if (isset($sv['signedMeterValue'])) {
                    $r->signed = json_encode($sv['signedMeterValue']);
                }
                $unit = $sv['unit'] ?? ($sv['unitOfMeasure']['unit'] ?? null);
                $multiplier = (int) ($sv['unitOfMeasure']['multiplier'] ?? 0);
                if (! is_numeric($sv['value'] ?? null)) {
                    continue;
                }
                $value = (float) $sv['value'] * (10 ** $multiplier);
                // Per-phase voltage/current: keep the first phase-less or L1 value.
                if ($phase !== null && ! in_array($phase, ['L1', 'L1-N'], true) && in_array($measurand, ['Voltage', 'Current.Import'], true)) {
                    continue;
                }
                match ($measurand) {
                    'Energy.Active.Import.Register' => $r->registerKwh = self::fmt(in_array(strtolower((string) $unit), ['kwh'], true) ? $value : $value / 1000, 3),
                    'Power.Active.Import' => $r->powerKw = self::fmt(in_array(strtolower((string) $unit), ['kw'], true) ? $value : $value / 1000, 2),
                    'SoC' => $r->soc = (int) round(max(0, min(100, $value))),
                    'Voltage' => $r->voltage = self::fmt($value, 2),
                    'Current.Import' => $r->current = self::fmt($value, 2),
                    default => null,
                };
            }
        }

        return $r;
    }

    public function isEmpty(): bool
    {
        return $this->registerKwh === null && $this->powerKw === null && $this->soc === null && $this->voltage === null && $this->current === null;
    }

    private static function fmt(float $v, int $dp): string
    {
        return number_format($v, $dp, '.', '');
    }
}
