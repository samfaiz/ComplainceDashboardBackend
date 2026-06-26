<?php

namespace App\Models;

use App\Services\Crypto\SecretBox;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class MailSettings extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'transport', 'host', 'port', 'encryption', 'username', 'password_encrypted',
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

    /** The mail settings row for one organization — created empty on first access. */
    public static function forOrganization(int $organizationId): self
    {
        return self::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $organizationId],
            ['transport' => 'log', 'enabled' => false],
        );
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
