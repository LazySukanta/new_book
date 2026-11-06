<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ShopSettingsController
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);

        return ApiResponse::data($this->settings($request->user()->tenant_id));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('settings.manage'), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'gst_registration_type' => ['sometimes', 'in:regular,composition,unregistered'],
            'price_inclusive_default' => ['sometimes', 'boolean'],
            'max_cashier_discount_bp' => ['sometimes', 'integer', 'between:0,10000'],
            'manager_cancel_window_days' => ['sometimes', 'integer', 'between:0,365'],
            'hsn_required' => ['sometimes', 'boolean'],
            'receipt_width_mm' => ['sometimes', 'integer', 'in:58,80'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'logo' => ['sometimes', 'required', 'image', 'mimes:png,jpg,jpeg', 'max:1024', 'dimensions:max_width=2000,max_height=2000'],
        ]);
        $path = null;
        if (isset($data['logo'])) {
            $path = $data['logo']->store('shop-logos/'.$user->tenant_id, 'local');
            if ($path === false) {
                throw new RuntimeException('Could not store the shop logo.');
            }
            unset($data['logo']);
            $data['logo_path'] = $path;
        }

        try {
            $settings = DB::transaction(function () use ($user, $data) {
                $this->settings($user->tenant_id);
                if ($data !== []) {
                    DB::table('shop_settings')->where('tenant_id', $user->tenant_id)->update($data);
                }

                return $this->settings($user->tenant_id);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return ApiResponse::data($settings);
    }

    public function logo(Request $request): StreamedResponse
    {
        $path = DB::table('shop_settings')->where('tenant_id', $request->user()->tenant_id)->value('logo_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function settings(int $tenantId): object
    {
        $query = DB::table('shop_settings')->where('tenant_id', $tenantId);
        if ($settings = $query->first()) {
            return $settings;
        }

        DB::table('shop_settings')->insertOrIgnore([
            'tenant_id' => $tenantId, 'name' => Tenant::findOrFail($tenantId)->name,
        ]);

        return $query->first();
    }
}
