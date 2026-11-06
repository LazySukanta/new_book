<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostingAudit
{
    /** Pass explicit business snapshots and write inside the posting transaction. */
    public function record(Request $request, string $action, string $entity, int $entityId, ?array $before, ?array $after, ?string $reason = null): void
    {
        $requestId = $request->attributes->get('audit_request_id') ?? (string) Str::uuid();
        $request->attributes->set('audit_request_id', $requestId);

        DB::table('audit_logs')->insert([
            'tenant_id' => $request->user()->tenant_id,
            'user_id' => $request->user()->id,
            'branch_id' => $request->attributes->get('branch')?->id,
            'device_id' => $request->attributes->get('device')?->id,
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'before_json' => $before === null ? null : json_encode($this->redact($before), JSON_THROW_ON_ERROR),
            'after_json' => $after === null ? null : json_encode($this->redact($after), JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'request_id' => $requestId,
            'created_at' => now(),
        ]);
    }

    private function redact(array $snapshot): array
    {
        foreach ($snapshot as $key => $value) {
            if (preg_match('/password|token|secret|credential|authorization|cookie|pairing|api.?key/i', (string) $key)) {
                unset($snapshot[$key]);
            } elseif (is_array($value)) {
                $snapshot[$key] = $this->redact($value);
            }
        }

        return $snapshot;
    }
}
