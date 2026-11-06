<x-workspace.layout title="Inventory · KhataPOS">
    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-8">
            <a href="{{ route('workspace') }}"><x-workspace.brand /></a>
            <a class="btn btn-ghost" href="{{ route('workspace.stock') }}">Stock workspace</a>
        </div>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-6 sm:px-8">
        @php
            $tabs = ['products' => 'Products', 'low-stock' => 'Low stock', 'ledger' => 'Stock ledger'];
            if ($canEdit) $tabs += ['edit' => 'Add product', 'import' => 'CSV import', 'references' => 'Catalogue setup'];
            $heading = $section === 'movement' ? 'Stock movement' : ($section === 'edit' && $item ? 'Edit product' : $tabs[$section]);
        @endphp
        <nav aria-label="Inventory" class="mb-5 flex flex-wrap gap-2">
            @foreach ($tabs as $tab => $label)
                <a @class(['btn btn-sm', 'btn-primary' => $tab === $section]) href="{{ route('workspace.inventory', ['section' => $tab]) }}"
                    @if ($tab === $section) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <h1 class="text-2xl font-bold">{{ $heading }}</h1>
        <p class="mt-1 mb-5 text-sm text-base-content/75">{{ $branch->name }} · {{ $branch->code }}</p>
        @if (session('inventory.message'))
            <x-ui.feedback class="mb-4">{{ session('inventory.message') }}</x-ui.feedback>
        @endif
        @if ($errors->any())
            <x-ui.feedback class="mb-4" :error="true">
                <ul class="list-inside list-disc">@foreach ($errors->getMessages() as $field => $messages) @foreach ($messages as $message)
                    <li>{{ str_starts_with($field, 'rows.') ? 'Row '.explode('.', $field)[1].' · ' : '' }}{{ $message }}</li>
                @endforeach @endforeach</ul>
            </x-ui.feedback>
        @endif

        @if ($section === 'products')
            <form method="get" class="mb-4 flex flex-wrap items-end gap-3">
                <x-workspace.admin-field name="search" label="Name, SKU or barcode" :value="request('search')" required />
                <button class="btn" type="submit">Search</button>
                @if(request('search'))<a class="btn btn-ghost" href="{{ route('workspace.inventory', ['section' => 'products']) }}">Clear search</a>@endif
            </form>
            <x-ui.table caption="Products">
                <thead><tr><th scope="col">Product</th><th scope="col" class="text-right">Retail price</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                    @forelse ($records as $row)
                        <tr>
                            <th scope="row" class="max-w-64 whitespace-normal [overflow-wrap:anywhere]">{{ $row['name'] }}<span class="block text-xs font-normal">{{ $row['sku'] }}</span></th>
                            <td class="text-right"><x-ui.money :amount="$row['retail_price']" /></td>
                            <td>
                                @if($canEdit)<a class="btn btn-ghost btn-sm" href="{{ route('workspace.inventory', ['section' => 'edit', 'product' => $row['id']]) }}">Edit &amp; history</a>@endif
                                @can('stock.view')<a class="btn btn-ghost btn-sm" href="{{ route('workspace.inventory', ['section' => 'ledger', 'product_id' => $row['id']]) }}">Ledger</a>@endcan
                                @if($canAdjust)<a class="btn btn-ghost btn-sm" href="{{ route('workspace.inventory', ['section' => 'movement', 'product' => $row['id']]) }}">Adjust or count</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3">No products match this view.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @elseif ($section === 'edit')
            @if ($references['units']->isEmpty() || $references['tax_rates']->isEmpty())
                <x-ui.feedback class="mb-4">Add a unit and GST rate in Catalogue setup before saving a product.</x-ui.feedback>
            @endif
            <form method="post" action="{{ route('workspace.inventory.save', ['product' => $item?->id]) }}" class="grid gap-4 rounded-box border border-base-300 bg-base-100 p-5 sm:grid-cols-2">
                @csrf
                @foreach (['name' => 'Product name', 'sku' => 'SKU', 'barcode' => 'Barcode', 'hsn' => 'HSN', 'retail_price' => 'Retail price', 'wholesale_price' => 'Wholesale price', 'purchase_price' => 'Purchase price', 'reorder_level' => 'Reorder quantity'] as $name => $label)
                    <x-workspace.admin-field :$name :$label :value="old($name, $item?->$name ?? (in_array($name, ['purchase_price', 'reorder_level'], true) ? '0' : ''))" :required="in_array($name, ['name', 'retail_price', 'wholesale_price'], true)" />
                @endforeach
                <x-workspace.admin-field name="unit_id" label="Unit" :options="$references['units']" :value="old('unit_id', $item?->unit_id)" required />
                <x-workspace.admin-field name="tax_rate_id" label="GST rate" :options="$references['tax_rates']" :value="old('tax_rate_id', $item?->tax_rate_id)" required />
                <x-workspace.admin-field name="category_id" label="Category" :options="$references['categories']" :value="old('category_id', $item?->category_id)" />
                <x-workspace.admin-field name="tax_inclusive" label="Prices include tax" :options="[1 => 'Yes', 0 => 'No']" :value="old('tax_inclusive', (int) ($item?->tax_inclusive ?? false))" required />
                <x-workspace.admin-field name="is_active" label="Status" :options="[1 => 'Active', 0 => 'Inactive']" :value="old('is_active', (int) ($item?->is_active ?? true))" required />
                <x-workspace.admin-field name="reason" label="Reason for price change" :value="old('reason')" />
                <fieldset class="fieldset sm:col-span-2">
                    <legend class="fieldset-legend">Wholesale quantity tiers</legend>
                    <input type="hidden" name="manage_slabs" value="1">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @for ($tier = 0; $tier < 5; $tier++)
                            <x-workspace.admin-field :name="'slabs['.$tier.'][min_qty]'" :label="'Tier '.($tier + 1).' minimum quantity'" :value="old('slabs.'.$tier.'.min_qty', $slabs[$tier]['min_qty'] ?? '')" />
                            <x-workspace.admin-field :name="'slabs['.$tier.'][price]'" :label="'Tier '.($tier + 1).' price'" :value="old('slabs.'.$tier.'.price', $slabs[$tier]['price'] ?? '')" />
                        @endfor
                    </div>
                </fieldset>
                <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Save product</button></div>
            </form>
            @if ($item)
                <h2 class="mt-6 mb-3 font-bold">Price history</h2>
                <x-ui.table caption="Price history">
                    <thead><tr><th scope="col">Recorded</th><th scope="col">Price</th><th scope="col">Before</th><th scope="col">After</th><th scope="col">Reason</th></tr></thead>
                    <tbody>
                        @forelse ($records as $row)
                            @foreach (['retail_price' => 'Retail', 'wholesale_price' => 'Wholesale', 'purchase_price' => 'Purchase'] as $field => $label)
                                <tr><td>{{ \Carbon\CarbonImmutable::parse($row['created_at'], 'UTC')->setTimezone('Asia/Kolkata')->format('j M Y, H:i') }}</td><th scope="row">{{ $label }}</th>
                                    <td>@if(isset($row['before_json'][$field]))<x-ui.money :amount="$row['before_json'][$field]" />@else — @endif</td>
                                    <td><x-ui.money :amount="$row['after_json'][$field]" /></td><td>{{ $row['reason'] }}</td></tr>
                            @endforeach
                            @foreach (['rate_bp' => 'GST rate (basis points)', 'tax_inclusive' => 'Tax included', 'slabs' => 'Wholesale tiers'] as $field => $label)
                                @if (($row['before_json'][$field] ?? null) !== ($row['after_json'][$field] ?? null))
                                    <tr><td>{{ \Carbon\CarbonImmutable::parse($row['created_at'], 'UTC')->setTimezone('Asia/Kolkata')->format('j M Y, H:i') }}</td><th scope="row">{{ $label }}</th>
                                        @foreach (['before_json', 'after_json'] as $side)
                                            <td>
                                                @if ($field === 'slabs')
                                                    @forelse ($row[$side][$field] ?? [] as $tier)<span class="block">{{ $tier['min_qty'] }}+ · <x-ui.money :amount="$tier['price']" /></span>@empty — @endforelse
                                                @elseif ($field === 'tax_inclusive' && isset($row[$side][$field]))
                                                    {{ $row[$side][$field] ? 'Yes' : 'No' }}
                                                @else {{ $row[$side][$field] ?? '—' }} @endif
                                            </td>
                                        @endforeach
                                        <td>{{ $row['reason'] }}</td></tr>
                                @endif
                            @endforeach
                        @empty
                            <tr><td colspan="5">No price changes recorded.</td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            @endif
        @elseif ($section === 'import')
            <form method="post" enctype="multipart/form-data" action="{{ route('workspace.inventory.import') }}" class="grid max-w-2xl gap-4 rounded-box border border-base-300 bg-base-100 p-5">
                @csrf
                <input type="hidden" name="key" value="{{ $key }}">
                <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                <x-ui.field id="catalogue-file" label="Product CSV">
                    <input class="file-input w-full" id="catalogue-file" name="file" type="file" accept=".csv,text/csv" required>
                </x-ui.field>
                <p class="break-words text-sm">Required headers <code>name, unit, gst_rate, retail_price, wholesale_price</code>. Optional headers include SKU, barcode, category, HSN, purchase price, reorder level, opening quantity and opening unit cost. Opening stock goes to {{ $branch->name }}.</p>
                <div class="flex flex-wrap gap-2">
                    <button class="btn" type="submit" name="dry_run" value="1">Validate CSV</button>
                    <button class="btn btn-primary" type="submit" name="dry_run" value="0">Import CSV</button>
                </div>
            </form>
        @elseif ($section === 'movement')
            @include('inventory-movement')
        @elseif ($section === 'references')
            @include('inventory-references')
        @else
            @if ($section === 'ledger' && request('product_id'))
                <a class="btn btn-ghost mb-3" href="{{ route('workspace.inventory', ['section' => 'ledger']) }}">Show all movements</a>
            @endif
            <x-ui.table :caption="$heading">
                <thead><tr><th scope="col">{{ $section === 'ledger' ? 'Product / movement' : 'Product' }}</th><th scope="col">Quantity</th>
                    @if($canSeeValue)<th scope="col">Value</th>@endif<th scope="col">Status</th></tr></thead>
                <tbody>
                    @forelse ($records as $row)
                        <tr>
                            <th scope="row" class="max-w-64 whitespace-normal break-words">{{ $row['name'] ?? ('Product #'.$row['product_id']) }}
                                @if($section === 'ledger')<span class="block font-normal">{{ str_replace('_', ' ', $row['type']) }} · {{ \Carbon\CarbonImmutable::parse($row['posted_at'], 'UTC')->setTimezone('Asia/Kolkata')->format('j M Y, H:i') }}</span>@endif
                            </th>
                            <td class="tabular-nums">{{ $row['qty'] ?? $row['qty_after'] }} @if(isset($row['qty_change'])) <span class="block text-xs">Change {{ $row['qty_change'] }}</span>@endif</td>
                            @if($canSeeValue)<td><x-ui.money :amount="$row['value'] ?? $row['value_after']" /></td>@endif
                            <td>
                                @if(($row['qty'] ?? $row['qty_after']) < 0)<span class="badge badge-outline badge-error">Negative</span>@endif
                                @if(!empty($row['cost_estimated']) || !empty($row['valuation_provisional']))<span class="badge badge-outline">Estimated</span>@endif
                                @if($section === 'low-stock')<span class="block text-xs">Reorder at {{ $row['reorder_level'] }}</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canSeeValue ? 4 : 3 }}">No records for this branch.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @endif
        @if (($pagination['last_page'] ?? 1) > 1)
            <nav aria-label="Record pages" class="mt-4 flex gap-3">
                @if ($pagination['current_page'] > 1)<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] - 1]) }}">Previous</a>@endif
                @if ($pagination['current_page'] < $pagination['last_page'])<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </main>
</x-workspace.layout>
