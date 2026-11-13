<section class="mt-6 rounded-box border border-base-300 p-4" aria-labelledby="return-title">
    <h2 id="return-title" class="text-xl font-bold">Return goods to supplier</h2>
    <p class="my-2">Enter quantities for goods physically returned. Leave other items blank.</p>
    @php($preview = session('purchase.return_preview.purchase_id') === $record['id'] ? session('purchase.return_preview') : null)
    @if ($preview)
        <p role="status" class="my-3">Preview: return ₹{{ $preview['total'] }}, purchase dues reduced by ₹{{ $preview['credit_adjusted'] }}, refund ₹{{ $preview['refund_amount'] }}, retained supplier credit ₹{{ $preview['retained_credit'] }}. Confirm only after checking the quantities below.</p>
    @endif
    <form method="post" action="{{ route('workspace.purchases.return-preview', $record['id']) }}" class="grid gap-4">
        @csrf
        <input type="hidden" name="key" value="{{ $key }}">
        <input type="hidden" name="expected_revision" value="{{ $record['current_revision'] }}">
        @foreach ($record['items'] as $index => $item)
            @php($product = json_decode($item['product_snapshot'], true))
            <label class="grid gap-1">{{ $product['name'] ?? 'Item #'.$item['product_id'] }} · Available to return {{ $item['returnable_qty'] }}
                <input type="hidden" name="items[{{ $index }}][purchase_item_id]" value="{{ $item['id'] }}">
                <input class="input w-full" name="items[{{ $index }}][qty]" inputmode="decimal" value="{{ collect(old('items', []))->firstWhere('purchase_item_id', $item['id'])['qty'] ?? '' }}" aria-label="Return quantity for {{ $product['name'] ?? $item['product_id'] }}">
            </label>
        @endforeach
        <label class="grid gap-1">Reason<input class="input w-full" name="reason" value="{{ old('reason') }}" maxlength="255" required></label>
        <label class="grid gap-1">Actual refund received<input class="input w-full" name="refund_amount" inputmode="decimal" value="{{ old('refund_amount', '0.00') }}" required></label>
        <label class="grid gap-1">Refund method<select class="select w-full" name="refund_mode">@foreach (['cash', 'upi', 'card', 'bank'] as $mode)<option value="{{ $mode }}" @selected(old('refund_mode', 'cash') === $mode)>{{ ucfirst($mode) }}</option>@endforeach</select></label>
        <label class="grid gap-1">Supplier acknowledgement reference (optional)<input class="input w-full" name="supplier_document_no" value="{{ old('supplier_document_no') }}" maxlength="255"></label>
        <label class="grid gap-1">Supplier acknowledgement date (optional)<input class="input w-full" type="date" name="supplier_document_date" value="{{ old('supplier_document_date') }}" max="{{ $today }}"></label>
        <div class="flex flex-wrap gap-3">
            <button class="btn" type="submit">Preview return</button>
            @if ($preview)<button class="btn btn-primary" type="submit" formaction="{{ route('workspace.purchases.return-store', $record['id']) }}">Confirm supplier return</button>@endif
        </div>
    </form>
</section>
