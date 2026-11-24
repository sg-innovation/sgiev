<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Refund extends Model
{
    use LogsActivity;

    public const INITIATED = 'initiated';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_retained' => 'decimal:2',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Refund $r) => $r->uuid ??= (string) Str::uuid());
        static::saved(function (Refund $r) {
            if ($r->status === self::SUCCESS && ($r->wasRecentlyCreated || $r->wasChanged('status'))) {
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($r) {
                    try {
                        app(\App\Services\InvoiceService::class)->creditNoteForRefund($r->fresh());
                        app(\App\Services\WalletService::class)->reverseForRefund($r->fresh());
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Credit note issue failed', ['refund' => $r->uuid, 'error' => $e->getMessage()]);
                    }
                });
            }
        });
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'amount', 'gateway_refund_id', 'failure_reason'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
