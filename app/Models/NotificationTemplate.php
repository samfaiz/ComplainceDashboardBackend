<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = [
        'event_key', 'display_name', 'category', 'default_severity',
        'subject', 'body_html', 'body_text', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function forEvent(string $eventKey): ?self
    {
        return self::query()->where('event_key', $eventKey)->first();
    }
}
