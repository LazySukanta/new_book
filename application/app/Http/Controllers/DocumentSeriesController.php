<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use App\Services\DocumentNumberService;
use App\Services\PostingAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DocumentSeriesController
{
    public function index(Request $request): JsonResponse
    {
        $scope = $this->scope($request);

        return ApiResponse::data(DB::table('document_series')->where($scope)->orderBy('financial_year')
            ->orderBy('document_type')->orderBy('owning_channel')->orderBy('rollover_order')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $data = $request->validate([
            'financial_year' => ['required', 'regex:/\A[0-9]{2}-[0-9]{2}\z/'],
            'document_type' => ['required', Rule::in(array_keys(DocumentNumberService::SOURCES))],
            'owning_channel' => 'required|in:web,windows,mobile',
            'prefixes' => 'required_unless:document_type,credit_note|array|list|min:1|max:24',
            'prefixes.*' => ['required', 'distinct', 'regex:/\A[A-HJ-NP-Z]\z/'],
            'next_number' => 'prohibited',
        ]);
        [$start, $end] = explode('-', $data['financial_year']);
        abort_unless(((int) $start + 1) % 100 === (int) $end, 422, 'Use a consecutive financial year.');
        $prefixes = $data['prefixes'] ?? ['X', 'Y', 'Z'];
        unset($data['prefixes']);

        return DB::transaction(function () use ($request, $scope, $data, $prefixes) {
            $request->user()->accessibleBranches()->lockForUpdate()->findOrFail($scope['branch_id']);
            $year = DB::table('document_series')->where($scope + ['financial_year' => $data['financial_year']]);
            if ($year->whereIn('prefix', $prefixes)->exists()) {
                return ApiResponse::conflict('DOCUMENT_PREFIX_IN_USE', 'A prefix is already assigned in this branch and year.');
            }
            $order = (int) DB::table('document_series')->where($scope + $data)->max('rollover_order');
            $rows = [];
            foreach ($prefixes as $prefix) {
                $row = $scope + $data + ['prefix' => $prefix, 'rollover_order' => ++$order, 'created_by' => $request->user()->id];
                $id = DB::table('document_series')->insertGetId($row);
                app(PostingAudit::class)->record($request, 'document_series.created', 'document_series', $id, null, $row);
                $rows[] = DB::table('document_series')->find($id);
            }

            return ApiResponse::data($rows, 201);
        }, 3);
    }

    private function scope(Request $request): array
    {
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('branches.manage'), 403);
        $branch = $request->attributes->get('branch');

        return ['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id];
    }
}
