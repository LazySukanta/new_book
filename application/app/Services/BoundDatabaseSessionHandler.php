<?php

namespace App\Services;

use Illuminate\Session\DatabaseSessionHandler;

class BoundDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function getDefaultPayload($data): array
    {
        $payload = parent::getDefaultPayload($data);
        $request = $this->container->make('request');
        $session = $request->hasSession() ? $request->session() : null;
        $payload['tenant_id'] = $session?->get('tenant_id');
        $payload['device_id'] = $session?->get('device_id');

        return $payload;
    }
}
