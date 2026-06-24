<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Session;

/**
 * Holds "per-login" connector secrets for the duration of a session only.
 *
 * Because SESSION_ENCRYPT is enabled and sessions are stored server-side, the
 * secret lives encrypted in the session row and is wiped on logout — it is
 * never persisted to the api_sources table. This backs the "require API key on
 * every login" option.
 */
class SessionSecretVault
{
    private const KEY = 'connector_secrets';

    public function put(int $sourceId, string $secret): void
    {
        $all = (array) Session::get(self::KEY, []);
        $all[$sourceId] = $secret;
        Session::put(self::KEY, $all);
    }

    public function get(int $sourceId): ?string
    {
        return Session::get(self::KEY.'.'.$sourceId);
    }

    public function has(int $sourceId): bool
    {
        return $this->get($sourceId) !== null;
    }

    public function forget(int $sourceId): void
    {
        $all = (array) Session::get(self::KEY, []);
        unset($all[$sourceId]);
        Session::put(self::KEY, $all);
    }
}
