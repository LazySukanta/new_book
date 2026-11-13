<?php

namespace App\Http\Controllers;

use App\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AuditController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scope($request);
        $filters = $request->validate(['action' => 'sometimes|required|string|max:100', 'entity_type' => 'sometimes|required|string|max:100',
            'entity_id' => 'sometimes|required|integer|min:1', 'user_id' => 'sometimes|required|integer|min:1',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|between:1,100']);
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }
        foreach (['action', 'entity_type', 'entity_id', 'user_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Kolkata')->utc());
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Kolkata')->addDay()->utc());
        }
        $page = $query->select('id', 'user_id', 'branch_id', 'device_id', 'action', 'entity_type', 'entity_id', 'reason', 'request_id', 'created_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', (int) ($filters['page'] ?? 1));
        return ApiResponse::data($page->items(), meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function show(Request $request, int $audit): JsonResponse
    {
        $row = $this->scope($request)->where('id', $audit)->first();
        abort_unless($row, 404);
        foreach (['before_json', 'after_json'] as $field) {
            $snapshot = $row->$field === null ? null : json_decode($row->$field, true, flags: JSON_THROW_ON_ERROR);
            $row->$field = is_array($snapshot) ? $this->redact($snapshot) : null;
        }
        return ApiResponse::data($row);
    }

    private function scope(Request $request): Builder
    {
        abort_unless($request->user()->hasRole('Owner') && $request->user()->can('audit.view'), 403);
        return DB::table('audit_logs')->where('tenant_id', $request->user()->tenant_id)
            ->where(fn ($query) => $query->where('branch_id', $request->attributes->get('branch')->id)->orWhereNull('branch_id'));
    }

    private function redact(array $snapshot): array
    {
        foreach ($snapshot as $field => $value) {
            if (preg_match('/password|token|secret|credential|authorization|cookie|pairing|api.?key/i', (string) $field)) {
                unset($snapshot[$field]);
            } elseif (is_array($value)) {
                $snapshot[$field] = $this->redact($value);
            }
        }
        return $snapshot;
    }
}
