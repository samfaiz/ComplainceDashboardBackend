<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;

interface Connector
{
    /**
     * Fetch raw device/agent records from the source.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(ApiSource $source, string $secret): array;
}
