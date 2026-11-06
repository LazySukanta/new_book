@props(['row' => [], 'index', 'products', 'correcting' => false])

<div data-repeat-row class="grid gap-3 rounded-box border border-base-300 p-3 sm:grid-cols-2 lg:grid-cols-6">
    <div class="min-w-0 lg:col-span-2">
        <x-workspace.admin-field :name="'items['.$index.'][product_id]'" label="Product" placeholder="Choose a product" :options="$products" :value="$row['product_id'] ?? ''" required />
    </div>
    <x-workspace.admin-field :name="'items['.$index.'][qty]'" label="Quantity" :value="$row['qty'] ?? ''" required />
    <x-workspace.admin-field :name="'items['.$index.'][unit_cost]'" label="Unit cost" :value="$row['unit_cost'] ?? ''" required />
    <x-workspace.admin-field :name="'items['.$index.'][rate_bp]'" label="GST (basis points)" :value="$row['rate_bp'] ?? ''" :required="$correcting" />
    @if ($correcting)
        <x-workspace.admin-field :name="'items['.$index.'][confirmed_remaining]'" label="Confirmed remaining" :value="$row['confirmed_remaining'] ?? ''" />
    @endif
    <div class="flex items-end"><button type="button" data-remove-row class="btn btn-ghost btn-sm">Remove line</button></div>
</div>
