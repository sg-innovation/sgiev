<?php

namespace App\Models;

use App\Enums\ConnectorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $fillable = ['make', 'model', 'registration_no', 'battery_kwh', 'connector', 'is_default'];

    protected function casts(): array
    {
        return [
            'battery_kwh' => 'decimal:2',
            'connector' => ConnectorType::class,
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return "{$this->make} {$this->model} · {$this->registration_no}";
    }
}
