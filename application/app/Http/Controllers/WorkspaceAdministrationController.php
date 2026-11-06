<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveBranch;
use App\Models\Device;
use App\Services\DocumentNumberService;
use App\Services\DeviceEnrollment;
use App\Services\ShopCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WorkspaceAdministrationController
{
    public function show(Request $request, string $section): Response
    {
        $this->authorize($request, $section);
        $actor = $request->user();
        $owner = $actor->hasRole('Owner');
        $branches = $actor->accessibleBranches()->orderBy('name')->get(['id', 'name', 'code']);
        $device = $request->attributes->get('device');
        $pairedBranches = $actor->accessibleBranches()->whereIn('id', $device->branches()->select('branches.id'))
            ->orderBy('name')->get(['id', 'name', 'code']);
        $branchId = $request->validate(['branch' => 'sometimes|required|integer|min:1'])['branch']
            ?? ($pairedBranches->firstWhere('id', session('workspace.branch'))?->id ?? $pairedBranches->first()?->id);
        $records = match ($section) {
            'branches' => app(BranchAdministrationController::class)->index($request)->getData(true),
            'staff' => app(StaffAdministrationController::class)->index($request)->getData(true),
            'devices' => ['data' => Device::where('tenant_id', $actor->tenant_id)->with('branches:id,name')->orderByDesc('id')->get()->toArray()],
            'settings' => app(ShopSettingsController::class)->show($request)->getData(true),
            'series' => $branchId
                ? $this->onBranch($request, $branchId, fn () => app(DocumentSeriesController::class)->index($request))->getData(true)
                : ['data' => []],
        };
        $pairing = $section === 'devices' ? $request->session()->pull('workspace.pairing') : null;

        return response()->view('administration', [
            'section' => $section, 'owner' => $owner, 'records' => $records['data'], 'pagination' => $records['meta'] ?? [],
            'branches' => $branches, 'pairedBranches' => $pairedBranches, 'branchId' => $branchId,
            'actor' => $actor, 'currentDeviceId' => $device->id, 'pairing' => $pairing ? Crypt::decryptString($pairing) : null,
            'states' => $section === 'branches' ? DB::table('gst_states')->orderBy('name')->pluck('name', 'code') : [],
            'documentTypes' => array_keys(DocumentNumberService::SOURCES), 'year' => ShopCalendar::financialYear(now()),
        ])->header('Cache-Control', 'no-store');
    }

    public function save(Request $request, string $section, ?int $record = null): RedirectResponse
    {
        $this->authorize($request, $section);
        abort_if($record !== null && in_array($section, ['series', 'settings'], true), 404);
        $branchId = null;
        try {
            $response = match ($section) {
                'branches' => $record !== null ? app(BranchAdministrationController::class)->update($request, $record)
                    : app(BranchAdministrationController::class)->store($request),
                'staff' => $this->saveStaff($request, $record),
                'settings' => app(ShopSettingsController::class)->update($request),
                'devices' => $record !== null ? app(DeviceController::class)->destroy($request, $record) : app(DeviceController::class)->store($request, app(DeviceEnrollment::class)),
                'series' => $this->saveSeries($request, $branchId),
            };
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }

            return back()->withErrors(['operation' => $exception->getMessage()]);
        }
        $body = $response->getData(true);
        if (! $response->isSuccessful()) {
            return back()->withErrors(['operation' => $body['message']]);
        }
        if ($section === 'devices' && $record === null) {
            $request->session()->flash('workspace.pairing', Crypt::encryptString($body['data']['pairing_code']));
        }

        return redirect()->route('workspace.admin', ['section' => $section] + ($branchId ? ['branch' => $branchId] : []))
            ->with('workspace.saved', $section === 'devices' ? ($record ? 'Device revoked.' : 'Device approved.') : 'Changes saved.');
    }

    private function saveStaff(Request $request, ?int $record): \Illuminate\Http\JsonResponse
    {
        $controller = app(StaffAdministrationController::class);
        if ($record === null) {
            return $controller->store($request);
        }
        if ($request->input('password') === null) {
            $request->request->remove('password');
        }

        return $controller->update($request, $record);
    }

    private function saveSeries(Request $request, ?int &$branchId): \Illuminate\Http\JsonResponse
    {
        $input = $request->validate(['branch_id' => 'required|integer|min:1', 'prefix_list' => 'nullable|string|max:100']);
        $branchId = (int) $input['branch_id'];
        if (isset($input['prefix_list'])) {
            $request->merge(['prefixes' => array_map('trim', explode(',', strtoupper($input['prefix_list'])))]);
        }

        return $this->onBranch($request, $branchId, fn () => app(DocumentSeriesController::class)->store($request));
    }

    private function onBranch(Request $request, int $branchId, \Closure $action): \Illuminate\Http\JsonResponse
    {
        $request->headers->set('X-Branch-Id', (string) $branchId);

        return app(ResolveBranch::class)->handle($request, $action);
    }

    private function authorize(Request $request, string $section): void
    {
        $actor = $request->user();
        abort_unless(match ($section) {
            'staff' => $actor->can('users.manage'),
            'branches' => $actor->can('branches.manage'),
            'devices' => $actor->hasRole('Owner'),
            'settings' => $actor->can('settings.manage'),
            'series' => $actor->hasRole('Owner') && $actor->can('branches.manage'),
        }, 403);
    }
}
