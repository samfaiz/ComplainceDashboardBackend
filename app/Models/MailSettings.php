<?php

namespace App\Models;

use App\Services\Crypto\SecretBox;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class MailSettings extends Model
{
    protected $fillable = [
        'transport', 'host', 'port', 'encryption', 'username', 'password_encrypted',
        'from_address', 'from_name', 'reply_to', 'enabled',
        'last_test_at', 'last_test_status', 'last_test_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'last_test_at' => 'datetime',
        ];
    }

    /** Convenience singleton fetch — creates an empty row on first access. */
    public static function active(): self
    {
        return self::query()->firstOrCreate(['id' => 1], [
            'transport' => 'log',
            'enabled' => false,
        ]);
    }

    public function setPassword(?string $plain): void
    {
        if ($plain === null || $plain === '') {
            $this->password_encrypted = null;
            return;
        }
        $this->password_encrypted = app(SecretBox::class)->encrypt($plain);
    }

    public function getPassword(): ?string
    {
        if (! $this->password_encrypted) {
            return null;
        }
        try {
            return app(SecretBox::class)->decrypt($this->password_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasPassword(): bool
    {
        return ! empty($this->password_encrypted);
    }
}
