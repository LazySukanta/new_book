<section class="mb-6 rounded-box border border-primary bg-base-100 p-5" aria-labelledby="review-title">
    <h2 id="review-title" class="mb-3 text-lg font-bold">{{ $preview['action'] === 'soft_delete' ? 'Review removal' : 'Review correction' }}</h2>
    <p class="mb-3 text-sm">Reason · {{ $preview['reason'] }}</p>
    <dl class="mb-4 grid gap-3 sm:grid-cols-2">
        <div><dt>Supplier</dt><dd>{{ $preview['before']['supplier_snapshot']['name'] }} → {{ $preview['after']['supplier_snapshot']['name'] }}</dd></div>
        <div><dt>Bill total</dt><dd><x-ui.money :amount="$preview['before']['total']" /> → <x-ui.money :amount="$preview['after']['total']" /></dd></div>
        <div><dt>Purchase balance due</dt><dd><x-ui.money :amount="$preview['settlement']['previous_payable']" /> → <x-ui.money :amount="$preview['settlement']['resulting_payable']" /></dd></div>
        <div><dt>Payment kept against this purchase</dt><dd><x-ui.money :amount="$preview['settlement']['retained_allocation']" /></dd></div>
        <div><dt>Advance kept by the original supplier</dt><dd><x-ui.money :amount="$preview['settlement']['advance_to_original_supplier']" /></dd></div>
    </dl>
    <p class="mb-4 text-sm">Any released payment remains an advance with the original supplier. This correction records no cash refund.</p>
    <x-ui.table caption="Stock and valuation changes">
        <thead><tr><th scope="col">Product</th><th scope="col">Quantity before → after</th><th scope="col">Value before → after</th><th scope="col">Valuation variance</th></tr></thead>
        <tbody>
            @forelse ($preview['effects'] as $effect)
                <tr>
                    <th scope="row">{{ $products[$effect['product_id']] ?? 'Product #'.$effect['product_id'] }}</th>
                    <td>{{ $effect['qty_before'] }} → {{ $effect['qty_after'] }}</td>
                    <td><x-ui.money :amount="$effect['value_before']" /> → <x-ui.money :amount="$effect['value_after']" /></td>
                    <td><x-ui.money :amount="$effect['valuation_variance']" /></td>
                </tr>
            @empty
                <tr><td colspan="4">No stock or valuation changes.</td></tr>
            @endforelse
        </tbody>
    </x-ui.table>
    <form class="mt-4" method="post" action="{{ route('workspace.purchases.confirm', $record['id']) }}">
        @csrf
        <input type="hidden" name="preview_token" value="{{ session('purchase.token') }}">
        <button class="btn btn-primary" type="submit">{{ $preview['action'] === 'soft_delete' ? 'Confirm removal' : 'Post correction' }}</button>
    </form>
</section>
