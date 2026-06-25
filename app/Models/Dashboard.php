<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Dashboard extends Model
{
    protected $fillable = [
        'user_id', 'api_source_id', 'name', 'is_default', 'layout',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'layout' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ApiSource::class, 'api_source_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dashboard_user')
            ->withPivot(['assigned_by_user_id', 'created_at'])
            ->withTimestamps();
    }
}
