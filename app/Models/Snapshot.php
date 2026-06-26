<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Snapshot extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'api_source_id', 'source_run_id', 'captured_at', 'endpoint_count', 'summary',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'endpoint_count' => 'integer',
            'summary' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ApiSource::class, 'api_source_id');
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(Endpoint::class);
    }
}
