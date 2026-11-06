<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('device', $this->resolve($request));

        return $next($request);
    }

    public function resolve(Request $request): Device
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && Tenant::whereKey($user->tenant_id)->where('is_active', true)->exists(), 403);
        $token = $user->currentAccessToken();
        $isBearer = $token instanceof PersonalAccessToken;
        $deviceId = $isBearer ? $token->device_id : $request->session()->get('device_id');
        $tenantId = $isBearer ? $token->tenant_id : $request->session()->get('tenant_id');
        abort_unless((string) $tenantId === (string) $user->tenant_id, 403);
        if ($isBearer) {
            abort_unless($token->expires_at && $token->expires_at->isFuture(), 401);
        }
        $device = Device::where('tenant_id', $user->tenant_id)->where('status', 'active')->find($deviceId);
        abort_unless($device, 403, 'Device authorization failed.');

        return $device;
    }
}
