<?php

namespace App\Services\Connectors;

use App\Models\ApiSource;

class ConnectorFactory
{
    public function make(ApiSource $source): Connector
    {
        return match ($source->vendor) {
            'crowdstrike' => new CrowdstrikeConnector(),
            'wazuh' => new WazuhConnector(),
            default => new GenericConnector(),
        };
    }
}
