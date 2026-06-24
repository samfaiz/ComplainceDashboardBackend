<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Endpoint extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'snapshot_id', 'api_source_id', 'external_id', 'hostname',
        'os_platform', 'os_version', 'agent_version', 'health_status',
        'last_seen_at', 'ip_address', 'mac_address', 'is_isolated',
        'compliance_status', 'extra', 'raw', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'captured_at' => 'datetime',
            'is_isolated' => 'boolean',
            'extra' => 'array',
            'raw' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ApiSource::class, 'api_source_id');
    }
}
