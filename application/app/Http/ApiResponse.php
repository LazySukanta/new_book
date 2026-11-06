<?php

namespace App\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ApiResponse
{
    public static function data(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => (object) $meta], $status);
    }

    public static function page(Request $request, Builder $query, array $sortColumns): JsonResponse
    {
        $filters = $request->validate([
            'sort' => ['sometimes', Rule::in($sortColumns)],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $page = $query->orderBy($filters['sort'] ?? $sortColumns[0], $filters['dir'] ?? 'asc')
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);

        return self::data($page->items(), meta: [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public static function conflict(string $code, string $message): JsonResponse
    {
        return response()->json(compact('message', 'code'), 409);
    }
}
