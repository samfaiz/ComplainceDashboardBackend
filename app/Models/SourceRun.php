<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRun extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'api_source_id', 'status', 'trigger', 'started_at', 'finished_at',
        'duration_ms', 'records_ingested', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'records_ingested' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ApiSource::class, 'api_source_id');
    }
}
