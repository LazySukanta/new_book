@if (!$item)
    <a class="btn" href="{{ route('workspace.inventory', ['section' => 'products']) }}">Choose a product</a>
@else
    <form method="post" action="{{ route('workspace.inventory.preview') }}" class="grid gap-4 rounded-box border border-base-300 bg-base-100 p-5 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="product_id" value="{{ $item->id }}">
        <p class="font-semibold [overflow-wrap:anywhere] sm:col-span-2">{{ $item->name }} · {{ $item->sku }}</p>
        <x-workspace.admin-field name="kind" label="Movement" :options="['count_correction' => 'Physical count', 'adjustment' => 'Adjustment']" :value="old('kind', 'count_correction')" required />
        <x-workspace.admin-field name="quantity" label="Counted quantity or signed adjustment" :value="old('quantity')" required />
        <x-workspace.admin-field name="unit_cost" label="Unit cost (required for additions)" :value="old('unit_cost')" />
        <x-workspace.admin-field name="reason" label="Reason" :value="old('reason')" required />
        <p class="text-sm sm:col-span-2">A physical count sets the quantity. An adjustment adds or removes the entered amount. Posted movements use today’s date.</p>
        <div><button class="btn" type="submit">Preview movement</button></div>
    </form>
    @if ($preview = session('inventory.preview'))
        <section class="mt-5 rounded-box border border-base-300 bg-base-100 p-5">
            <h2 class="mb-3 font-bold">Review stock movement</h2>
            <dl class="grid gap-3 sm:grid-cols-2">
                <div><dt>Quantity</dt><dd>{{ $preview['qty_before'] }} → {{ $preview['qty_after'] }}</dd></div>
                <div><dt>Inventory value</dt><dd><x-ui.money :amount="$preview['value_before']" /> → <x-ui.money :amount="$preview['value_after']" /></dd></div>
                <div><dt>Average unit cost</dt><dd class="tabular-nums">₹{{ $preview['avg_cost_before'] }} → ₹{{ $preview['avg_cost_after'] }}</dd></div>
                <div><dt>Valuation variance</dt><dd><x-ui.money :amount="$preview['valuation_variance']" /></dd></div>
            </dl>
            <form method="post" action="{{ route('workspace.inventory.post') }}" class="mt-4">
                @csrf
                <input type="hidden" name="preview_token" value="{{ session('inventory.token') }}">
                <button class="btn btn-primary" type="submit">Post movement</button>
            </form>
        </section>
    @endif
@endif
