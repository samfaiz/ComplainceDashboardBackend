<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginEvent extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'user_id', 'email', 'ip_address', 'user_agent',
        'successful', 'is_new_ip', 'failure_reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'is_new_ip' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
