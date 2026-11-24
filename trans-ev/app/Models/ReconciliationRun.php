<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reconciliation pass (daily schedule or "Run now"). */
class ReconciliationRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stats' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
