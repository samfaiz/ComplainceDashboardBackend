<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnownIp extends Model
{
    protected $fillable = [
        'user_id', 'ip_address', 'trusted', 'login_count',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'trusted' => 'boolean',
            'login_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
