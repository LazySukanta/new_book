<?php

namespace App\Services;

use App\Http\ApiResponse;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

final class PostingTransaction
{
    /**
     * Call after authorization and branch resolution. Include target IDs in the validated payload.
     * Keep callbacks database-only. After the request row, lock branches, parties,
     * documents, catalogue, stock and series in that order, sorting IDs within each group.
     */
    public function run(Request $request, string $operation, array $payload, Closure $post): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Supply a valid Idempotency-Key header.']);
        }

        $user = $request->user();
        $branch = $request->attributes->get('branch');
        if (! $user || ! $branch || $branch->tenant_id !== $user->tenant_id) {
            throw new LogicException('Posting requires an authorized tenant and branch.');
        }

        $scope = ['tenant_id' => $user->tenant_id, 'user_id' => $user->id,
            'branch_id' => $branch->id, 'route' => $operation, 'key' => $key];
        $hash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($scope, $hash, $post) {
            DB::table('idempotency_requests')->upsert(
                [$scope + ['request_hash' => $hash]], array_keys($scope), ['key'],
            );
            $query = DB::table('idempotency_requests')->where($scope);
            $entry = $query->lockForUpdate()->first();
            if ($entry->request_hash !== $hash) {
                return ApiResponse::conflict('IDEMPOTENCY_CONFLICT', 'This key was already used with different data.');
            }
            if ($entry->completed_at !== null) {
                return JsonResponse::fromJsonString($entry->response_body, $entry->response_status);
            }

            $response = $post($entry->id);
            if (! $response->isSuccessful()) {
                throw new HttpResponseException($response);
            }
            $query->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'completed_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
