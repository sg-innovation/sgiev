<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Monthly franchise commission: net sales of its stations × commission %. Draft → approved → paid. */
class FranchisePayout extends Model
{
    use LogsActivity;

    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'void' => 'Void'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'energy_kwh' => 'decimal:3', 'net_sales' => 'decimal:2',
            'commission_percent' => 'decimal:2', 'commission_amount' => 'decimal:2', 'lines' => 'array',
            'approved_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (FranchisePayout $p) => $p->uuid ??= (string) Str::uuid());
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'commission_amount', 'payment_reference', 'paid_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
