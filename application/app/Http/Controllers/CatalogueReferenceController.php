<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CatalogueReferenceController
{
    public function index(Request $request, string $reference): JsonResponse
    {
        abort_unless($request->user()->can('products.view'), 403);
        $table = $this->table($reference);
        $query = DB::table($table)->where('tenant_id', $request->user()->tenant_id);
        if (! $request->user()->can('products.manage')) {
            $query->where('is_active', true);
        }

        return ApiResponse::data($query->orderBy('name')->get());
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        return $this->save($request, $reference, null);
    }

    public function update(Request $request, string $reference, int $id): JsonResponse
    {
        return $this->save($request, $reference, $id);
    }

    private function save(Request $request, string $reference, ?int $id): JsonResponse
    {
        $table = $this->table($reference);
        $user = $request->user();
        abort_unless($table === 'tax_rates' ? $user->hasRole('Owner') : $user->can('products.manage'), 403);

        return DB::transaction(function () use ($request, $table, $user, $id) {
            DB::table('tenants')->where('id', $user->tenant_id)->lockForUpdate()->first();
            $query = DB::table($table)->where('tenant_id', $user->tenant_id);
            $previous = $id === null ? null : (clone $query)->where('id', $id)->first();
            abort_if($id !== null && ! $previous, 404);
            $presence = $id === null ? 'required' : 'sometimes';
            $unique = fn (string $column) => Rule::unique($table, $column)->where('tenant_id', $user->tenant_id)->ignore($id);
            $rules = ['name' => [$presence, 'required', 'string', 'max:255'], 'is_active' => ['sometimes', 'boolean']];
            if ($table === 'categories') {
                $rules['name'][] = $unique('name');
            } elseif ($table === 'units') {
                $rules['code'] = [$presence, 'required', 'string', 'max:32', $unique('code')];
                $rules['allow_decimal'] = ['sometimes', 'boolean'];
            } else {
                $rules['rate_bp'] = [$presence, 'required', 'integer', 'between:0,10000'];
            }
            $data = $request->validate($rules);

            $protectedField = match ($table) {
                'tax_rates' => 'rate_bp',
                'units' => 'allow_decimal',
                default => null,
            };
            if ($previous && $protectedField && isset($data[$protectedField])
                && (int) $previous->{$protectedField} !== (int) $data[$protectedField]
                && DB::table('products')->where($table === 'units' ? 'unit_id' : 'tax_rate_id', $id)->exists()) {
                return ApiResponse::conflict('REFERENCE_IN_USE', 'Create a new reference and reassign products that need the change.');
            }

            if ($id === null) {
                $newId = DB::table($table)->insertGetId($data + ['tenant_id' => $user->tenant_id]);
            } else {
                if ($data !== []) {
                    (clone $query)->where('id', $id)->update($data);
                }
                $newId = $id;
            }

            return ApiResponse::data($query->where('id', $newId)->first(), $id === null ? 201 : 200);
        }, 3);
    }

    private function table(string $reference): string
    {
        return match ($reference) {
            'categories', 'units' => $reference,
            'tax-rates' => 'tax_rates',
            default => abort(404),
        };
    }
}
