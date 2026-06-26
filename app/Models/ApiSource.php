<?php

namespace App\Models;

use App\Services\Crypto\SecretBox;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ApiSource extends Model
{
    use BelongsToOrganization;

    public const VENDORS = [
        'generic', 'crowdstrike', 'defender', 'sentinelone', 'wazuh',
        'trendmicro', 'cortex', 'cisco_amp', 'elastic', 'sophos',
    ];

    public const AUTH_BEARER = 'bearer';
    public const AUTH_API_KEY_HEADER = 'api_key_header';
    public const AUTH_BASIC = 'basic';
    public const AUTH_OAUTH2_CC = 'oauth2_client_credentials';

    public const SECRET_SAVED = 'saved';
    public const SECRET_PER_LOGIN = 'per_login';

    protected $fillable = [
        'organization_id', 'user_id', 'site_id', 'name', 'vendor', 'base_url', 'auth_type', 'auth_config',
        'secret_mode', 'secret_encrypted', 'secret_hint', 'request_config',
        'field_mappings', 'refresh_interval_minutes', 'is_enabled',
        'last_run_at', 'last_status', 'last_error', 'latest_snapshot_id',
    ];

    protected $hidden = [
        'secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'auth_config' => 'array',
            'request_config' => 'array',
            'field_mappings' => 'array',
            'refresh_interval_minutes' => 'integer',
            'is_enabled' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Secret handling                                                     */
    /* ------------------------------------------------------------------ */

    /** Encrypt and store a secret (only used in "saved" mode). */
    public function storeSecret(?string $plain): void
    {
        if ($plain === null || $plain === '') {
            $this->secret_encrypted = null;
            $this->secret_hint = null;

            return;
        }

        $this->secret_encrypted = app(SecretBox::class)->encrypt($plain);
        $this->secret_hint = '••••'.substr($plain, -4);
    }

    /** Decrypt the stored secret (saved mode only). Returns null if none. */
    public function revealSecret(): ?string
    {
        if (empty($this->secret_encrypted)) {
            return null;
        }

        return app(SecretBox::class)->decrypt($this->secret_encrypted);
    }

    public function usesSavedSecret(): bool
    {
        return $this->secret_mode === self::SECRET_SAVED;
    }

    public function requiresSecretEachLogin(): bool
    {
        return $this->secret_mode === self::SECRET_PER_LOGIN;
    }

    /** Background scheduler may only auto-refresh sources whose secret is saved. */
    public function isSchedulable(): bool
    {
        return $this->is_enabled && $this->usesSavedSecret();
    }

    /* ------------------------------------------------------------------ */
    /* Relationships                                                       */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SourceRun::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    public function latestSnapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class, 'latest_snapshot_id');
    }

    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class);
    }
}
