<x-workspace.layout title="Purchases · KhataPOS">
    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-8">
            <a href="{{ route('workspace') }}"><x-workspace.brand /></a>
            <a class="btn btn-ghost" href="{{ route('workspace') }}">Back to workspace</a>
        </div>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-6 sm:px-8">
        <nav aria-label="Purchases" class="mb-5 flex flex-wrap gap-2">
            <a class="btn btn-sm" href="{{ route('workspace.purchases.index') }}">Active purchases</a>
            <a class="btn btn-sm" href="{{ route('workspace.purchases.create') }}">Record purchase</a>
            @if ($owner)<a class="btn btn-sm" href="{{ route('workspace.purchases.index', ['status' => 'deleted']) }}">Removed purchases</a>@endif
            @if ($record)
                <a class="btn btn-sm" href="{{ route('workspace.purchases.detail', $record['id']) }}">Purchase detail</a>
                @if ($owner)
                    @if ($record['status'] === 'posted')<a class="btn btn-sm" href="{{ route('workspace.purchases.correct', $record['id']) }}">Correct purchase</a>@endif
                    <a class="btn btn-sm" href="{{ route('workspace.purchases.history', $record['id']) }}">Version history</a>
                @endif
            @endif
        </nav>
        <h1 class="text-2xl font-bold">{{ match($section) {
            'index' => request('status') === 'deleted' ? 'Removed purchases' : 'Purchases',
            'create' => 'Record purchase', 'detail' => 'Purchase detail',
            'correct' => 'Correct purchase', 'history' => 'Version history',
        } }}</h1>
        <p class="mt-1 mb-5 text-sm text-base-content/75">{{ $branch->name }} · {{ $branch->code }}</p>
        @if (session('purchase.message'))<x-ui.feedback class="mb-4">{{ session('purchase.message') }}</x-ui.feedback>@endif
        @if ($errors->any())
            <x-ui.feedback class="mb-4" :error="true">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
            </x-ui.feedback>
        @endif
        @if ($section === 'index')
            <x-ui.table caption="Supplier purchases">
                <thead><tr><th scope="col">Supplier invoice</th><th scope="col">Supplier</th><th scope="col">Total</th><th scope="col">Balance due</th><th scope="col">Revision</th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($supplier = json_decode($row['supplier_snapshot'], true))
                        <tr>
                            <th scope="row" class="max-w-64 whitespace-normal [overflow-wrap:anywhere]">
                                <a class="link link-primary" href="{{ route('workspace.purchases.detail', $row['id']) }}">{{ $row['supplier_invoice_no'] }}</a>
                                <span class="block font-normal">{{ $row['supplier_invoice_date'] }}</span>
                            </th>
                            <td>{{ $supplier['name'] ?? 'Supplier #'.$row['supplier_id'] }}</td>
                            <td><x-ui.money :amount="$row['total']" /></td><td><x-ui.money :amount="$row['balance_amount']" /></td><td>{{ $row['current_revision'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No purchases in this view.</td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        @elseif ($section === 'create' || $section === 'correct')
            @if ($section === 'correct' && session('purchase.preview_id') === $record['id'] && ($preview = session('purchase.preview')))
                @include('purchase-review')
            @endif
            @include('purchase-form')
        @elseif ($section === 'detail')
            <x-workspace.purchase-summary :snapshot="$record" />
            <x-workspace.purchase-items :snapshot="$record" caption="Current purchase lines" />
            @if ($record['status'] === 'posted')
                @include('purchase-return-form')
            @endif
            @foreach ($record['returns'] as $returned)
                <p class="mt-3">{{ $returned['document_no'] }} · Returned ₹{{ $returned['total'] }} · Refund ₹{{ $returned['refund_amount'] }} · {{ $returned['reason'] }}</p>
            @endforeach
        @else
            @forelse ($rows as $version)
                <details class="mb-3 rounded-box border border-base-300 bg-base-100 p-4">
                    <summary class="cursor-pointer font-semibold">Revision {{ $version['revision_no'] }} · {{ ucfirst(str_replace('_', ' ', $version['action'])) }}</summary>
                    <p class="my-3 text-sm">{{ $version['reason'] }} · {{ \Carbon\CarbonImmutable::parse($version['correction_posted_at'], 'UTC')->setTimezone('Asia/Kolkata')->format('j M Y, H:i') }}</p>
                    @if($version['before_snapshot'])
                        <h2 class="mb-2 font-bold">Before</h2>
                        <x-workspace.purchase-summary :snapshot="$version['before_snapshot']" />
                        <x-workspace.purchase-items :snapshot="$version['before_snapshot']" caption="Lines before correction" />
                    @endif
                    <h2 class="mt-4 mb-2 font-bold">After</h2>
                    <x-workspace.purchase-summary :snapshot="$version['after_snapshot']" />
                    <x-workspace.purchase-items :snapshot="$version['after_snapshot']" caption="Lines after correction" />
                </details>
            @empty
                <p>No recorded versions.</p>
            @endforelse
        @endif
        @if (($meta['last_page'] ?? 1) > 1)
            <nav aria-label="Purchase pages" class="mt-4 flex gap-3">
                @if ($meta['current_page'] > 1)<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $meta['current_page'] - 1]) }}">Previous</a>@endif
                @if ($meta['current_page'] < $meta['last_page'])<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $meta['current_page'] + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </main>
</x-workspace.layout>
