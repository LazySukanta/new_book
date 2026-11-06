<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\Branch;
use App\Services\CatalogueBalances;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BranchAdministrationController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branches = $user->can('branches.manage')
            ? Branch::query()->where('tenant_id', $user->tenant_id)
            : $user->accessibleBranches()->whereIn('id', $request->attributes->get('device')->branches()->select('branches.id'));

        return ApiResponse::page($request, $branches, ['code', 'name']);
    }

    public function states(): JsonResponse
    {
        return ApiResponse::data(DB::table('gst_states')->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('branches.manage'), 403);
        $branch = new Branch;
        $branch->tenant_id = $request->user()->tenant_id;

        return DB::transaction(function () use ($request, $branch) {
            DB::table('tenants')->where('id', $branch->tenant_id)->lockForUpdate()->first();

            return $this->save($request, $branch, 201);
        }, 3);
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        abort_unless($request->user()->can('branches.manage'), 403);

        return DB::transaction(function () use ($request, $branch) {
            DB::table('tenants')->where('id', $request->user()->tenant_id)->lockForUpdate()->first();
            $record = Branch::where('tenant_id', $request->user()->tenant_id)->lockForUpdate()->findOrFail($branch);

            return $this->save($request, $record, 200);
        });
    }

    private function save(Request $request, Branch $branch, int $status): JsonResponse
    {
        $unique = Rule::unique('branches', 'code')->where('tenant_id', $branch->tenant_id);
        if ($branch->exists) {
            $unique->ignore($branch->id);
        }
        $presence = $branch->exists ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$presence, 'required', 'string', 'max:255'],
            'code' => [$presence, 'required', 'regex:/\A[A-Z0-9]{2,3}\z/', $unique],
            'state_code' => ['sometimes', 'nullable', 'string', 'exists:gst_states,code'],
            'gstin' => ['sometimes', 'nullable', 'regex:/\A[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]Z[A-Z0-9]\z/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branch->forceFill($data);
        if ($branch->gstin && substr($branch->gstin, 0, 2) !== $branch->state_code) {
            throw ValidationException::withMessages(['gstin' => 'GSTIN must match the branch state.']);
        }
        if ($branch->exists && $branch->isDirty('code') && Schema::hasTable('issued_documents')
            && DB::table('issued_documents')->join('document_series', 'document_series.id', '=', 'issued_documents.document_series_id')
                ->where('document_series.branch_id', $branch->id)->exists()) {
            return ApiResponse::conflict('BRANCH_CODE_LOCKED', 'The branch code is fixed after its first document is issued.');
        }
        $initializeBalances = (bool) ($branch->is_active ?? true) && (! $branch->exists || $branch->isDirty('is_active'));
        $branch->save();
        if ($initializeBalances) {
            CatalogueBalances::forBranch($branch);
        }

        return ApiResponse::data($branch->refresh(), $status);
    }
}
