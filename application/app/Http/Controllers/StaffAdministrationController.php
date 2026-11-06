<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffAdministrationController
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::page($request, $this->staff($request->user())->with(['roles:id,name', 'branches:id,name,code']), ['name', 'email']);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $staff): JsonResponse
    {
        return $this->save($request, $staff);
    }

    private function staff(User $actor): Builder
    {
        abort_unless($actor->can('users.manage'), 403);
        $query = User::where('tenant_id', $actor->tenant_id);
        if (! $actor->hasRole('Owner')) {
            $query->role('Cashier')->where('has_all_branches', false)
                ->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', '!=', 'Cashier'))
                ->whereDoesntHave('branches', fn (Builder $branches) => $branches->whereNotIn('branches.id', $actor->branches()->select('branches.id')));
        }

        return $query;
    }

    private function save(Request $request, ?int $id): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('users.manage'), 403);

        return DB::transaction(function () use ($request, $actor, $id) {
            DB::table('tenants')->where('id', $actor->tenant_id)->lockForUpdate()->first();
            $actor->refresh();
            abort_unless($actor->is_active && $actor->can('users.manage'), 403);
            $target = $id === null ? new User : $this->staff($actor)->lockForUpdate()->findOrFail($id);
            abort_if($target->exists && ($target->is($actor) || $target->hasRole('Owner')), 403);
            $owner = $actor->hasRole('Owner');
            $presence = $id === null ? 'required' : 'sometimes';
            $unique = Rule::unique('users', 'email');
            if ($id !== null) {
                $unique->ignore($id);
            }
            $data = $request->validate([
                'name' => [$presence, 'required', 'string', 'max:255'],
                'email' => [$presence, 'required', 'email', 'max:255', $unique],
                'password' => [$presence, 'required', 'string', 'min:8', 'max:72'],
                'role' => [$presence, 'required', Rule::in($owner ? ['Manager', 'Cashier'] : ['Cashier'])],
                'branch_ids' => [$presence, 'required', 'array', 'min:1'],
                'branch_ids.*' => ['required', 'integer', 'distinct'],
                'is_active' => ['sometimes', 'boolean'],
            ]);
            if (isset($data['password']) && strlen($data['password']) > 72) {
                throw ValidationException::withMessages(['password' => 'The password must fit within 72 UTF-8 bytes.']);
            }
            $branchIds = $data['branch_ids'] ?? null;
            if ($branchIds !== null && $actor->accessibleBranches()->whereIn('id', $branchIds)->count() !== count($branchIds)) {
                throw ValidationException::withMessages(['branch_ids' => 'Select active branches within your scope.']);
            }

            $target->forceFill(collect($data)->except(['branch_ids', 'role'])->all());
            $target->tenant_id = $actor->tenant_id;
            if (isset($data['password'])) {
                $target->remember_token = null;
            }
            $target->save();
            if (isset($data['role'])) {
                $target->syncRoles([$data['role']]);
            }
            if ($branchIds !== null) {
                $target->branches()->syncWithPivotValues($branchIds, ['tenant_id' => $actor->tenant_id]);
            }
            if (! $target->is_active || isset($data['password'])) {
                $target->tokens()->delete();
                DB::table('sessions')->where('user_id', $target->id)->delete();
            }

            return ApiResponse::data($target->load(['roles:id,name', 'branches:id,name,code']), $id === null ? 201 : 200);
        });
    }
}
