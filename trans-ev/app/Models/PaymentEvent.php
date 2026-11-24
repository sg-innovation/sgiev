<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only gateway event log (also enforced by a database trigger). */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_valid' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payment events are append-only.'));
        static::deleting(fn () => throw new LogicException('Payment events are append-only.'));
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Remove secrets before storing a gateway payload. */
    public static function scrub(array $payload): array
    {
        foreach (['clientSecret', 'client_secret', 'checksum', 'signature', 'apiKey', 'secretKey'] as $k) {
            if (array_key_exists($k, $payload)) {
                $payload[$k] = '[removed]';
            }
        }

        return $payload;
    }
}
