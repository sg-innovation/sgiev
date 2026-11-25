<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A remote command for a charge point, queued until the OCPP server sends it, then the charger's answer. */
class OcppCommand extends Model
{
    public const COMMANDS = [
        'remote_start' => 'Start charging', 'remote_stop' => 'Stop charging', 'reset' => 'Restart charger', 'unlock' => 'Unlock connector',
        'availability' => 'Change availability', 'trigger' => 'Ask for status', 'update_firmware' => 'Update firmware', 'get_diagnostics' => 'Upload diagnostics',
    ];

    public const STATUSES = ['queued' => 'Waiting to send', 'sent' => 'Sent', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'failed' => 'Failed', 'timeout' => 'No answer'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['params' => 'array', 'response' => 'array', 'sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
