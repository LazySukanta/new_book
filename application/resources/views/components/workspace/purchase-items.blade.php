@props(['snapshot', 'caption'])

<x-ui.table :$caption>
    <thead><tr><th scope="col">Product</th><th scope="col">Quantity</th><th scope="col">Unit cost</th><th scope="col">GST (bp)</th><th scope="col">Line total</th></tr></thead>
    <tbody>
        @forelse ($snapshot['items'] ?? [] as $line)
            @php($product = is_string($line['product_snapshot'] ?? null) ? json_decode($line['product_snapshot'], true) : ($line['product_snapshot'] ?? []))
            <tr>
                <th scope="row" class="max-w-72 whitespace-normal [overflow-wrap:anywhere]">{{ $product['name'] ?? 'Product #'.$line['product_id'] }}
                    @if(isset($product['sku']))<span class="block font-normal">{{ $product['sku'] }}</span>@endif
                    @if(!empty($line['is_removed']))<span class="badge badge-outline">Removed</span>@endif
                </th>
                <td class="tabular-nums">{{ $line['qty'] }}</td>
                <td><x-ui.money :amount="$line['unit_cost']" /></td>
                <td class="tabular-nums">{{ $line['rate_bp'] }}</td>
                <td><x-ui.money :amount="$line['line_total'] ?? $line['total'] ?? '0.00'" /></td>
            </tr>
        @empty
            <tr><td colspan="5">No retained lines.</td></tr>
        @endforelse
    </tbody>
</x-ui.table>
