<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Device;
use App\Services\DeviceAudit;
use App\Services\DeviceEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class DeviceController extends Controller
{
    public function store(Request $request, DeviceEnrollment $enrollment): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner'), 403);
        $input = $request->validate(['name' => 'required|string|max:255',
            'branch_ids' => 'required|array|min:1', 'branch_ids.*' => 'required|integer|distinct']);

        return ApiResponse::data($enrollment->register($request->user(), $input['name'], $input['branch_ids']), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        abort_unless($request->user()->hasRole('Owner'), 403);
        DB::transaction(function () use ($request, $device) {
            $record = Device::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($device);
            $record->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $request->user()->id,
                'pairing_hash' => null, 'credential_hash' => null])->save();
            PersonalAccessToken::where('device_id', $record->id)->delete();
            DB::table('sessions')->where('device_id', $record->id)->delete();
            DeviceAudit::record('device.revoked', $request->user(), $record);
        });

        return ApiResponse::data(null);
    }
}
