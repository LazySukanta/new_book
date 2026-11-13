@props(['snapshot'])
<dl class="divide-y divide-base-300">
    @forelse($snapshot as $field => $value)
        <div class="grid gap-1 py-2">
            <dt class="text-sm font-semibold">{{ is_int($field) ? 'Item '.($field + 1) : ucfirst(str_replace('_', ' ', $field)) }}</dt>
            <dd class="min-w-0 break-words text-sm [overflow-wrap:anywhere]">
                @if(is_array($value))<div class="border-l border-base-300 pl-3"><x-workspace.audit-snapshot :snapshot="$value" /></div>
                @elseif(is_bool($value)){{ $value ? 'Yes' : 'No' }}
                @else{{ $value ?? '—' }}@endif
            </dd>
        </div>
    @empty
        <div class="py-2 text-sm text-base-content/75">No snapshot values.</div>
    @endforelse
</dl>
