<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Middleware\RequireDevice;
use Illuminate\Contracts\View\View;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DeviceAudit;
use App\Services\DeviceEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationController extends Controller
{
    public function signInPage(Request $request, RequireDevice $devices): View|RedirectResponse
    {
        if ($request->user()) {
            try {
                $devices->resolve($request);

                return redirect()->route('workspace');
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return view('welcome');
    }

    public function login(Request $request, DeviceEnrollment $enrollment): JsonResponse|RedirectResponse
    {
        $input = $request->validate([
            'email' => 'required|email', 'password' => 'required|string',
            'pairing_code' => 'nullable|string|max:128',
        ]);
        $form = $request->routeIs('session.sign-in');
        try {
            return $this->authenticate($request, $enrollment, $input, $form);
        } catch (HttpExceptionInterface $exception) {
            if (! $form || ! in_array($exception->getStatusCode(), [401, 403], true)) {
                throw $exception;
            }

            return back()->withInput($request->only('email'))->withErrors(['email' => $exception->getMessage()]);
        }
    }

    private function authenticate(Request $request, DeviceEnrollment $enrollment, array $input, bool $form): JsonResponse|RedirectResponse
    {
        $user = User::where('email', $input['email'])->first();
        abort_unless($user && Hash::check($input['password'], $user->password) && $user->is_active
            && Tenant::whereKey($user->tenant_id)->where('is_active', true)->exists(), 401, 'Authentication failed.');
        $browser = $form || $request->routeIs('session.login');

        return DB::transaction(function () use ($request, $enrollment, $user, $input, $browser, $form) {
            [$device,$credential] = $enrollment->authenticate($user, $input['pairing_code'] ?? null,
                $browser ? $request->cookie('shop_device') : null, $browser);
            if ($browser) {
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                $request->session()->put(['device_id' => $device->id, 'tenant_id' => $user->tenant_id]);
                $response = $form ? redirect()->route('workspace') : ApiResponse::data(['user' => $user->only('id', 'name'), 'device' => $device->only('id', 'name', 'channel')]);
                if ($credential) {
                    $response->withCookie(cookie('shop_device', $credential, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'strict'));
                }

                return $response;
            }
            $plainText = Str::random(64);
            $token = $user->tokens()->create([
                'name' => $device->name, 'token' => hash('sha256', $plainText), 'abilities' => ['*'],
                'expires_at' => now()->addDays(30), 'tenant_id' => $user->tenant_id, 'device_id' => $device->id,
            ]);
            DeviceAudit::record('token.issued', $user, $device);

            return ApiResponse::data(['token' => $token->id.'|'.$plainText, 'expires_at' => $token->expires_at,
                'device' => $device->only('id', 'name', 'channel')])->header('Cache-Control', 'no-store');
        });
    }

    public function me(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');

        return ApiResponse::data([
            'user' => $request->user()->only('id', 'name'),
            'permissions' => $request->user()->getAllPermissions()->pluck('name'),
            'device' => $device->only('id', 'name', 'channel'),
            'branches' => $request->user()->accessibleBranches()->whereIn('id', $device->branches()->select('branches.id'))->get(['id', 'name', 'code']),
        ]);
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            DB::transaction(function () use ($request, $token) {
                $token->delete();
                DeviceAudit::record('token.revoked', $request->user(), $request->attributes->get('device'));
            });
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->routeIs('session.sign-out') ? redirect()->route('login') : ApiResponse::data(null);
    }
}
