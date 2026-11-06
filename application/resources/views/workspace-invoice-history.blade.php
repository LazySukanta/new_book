<x-workspace.layout title="Invoice history · KhataPOS">
    <main id="main-content" class="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-8">
        <a class="link link-primary" href="{{ route('workspace.pos') }}">Point of sale</a>
        <div>
            <h1 class="text-2xl font-bold">Invoice history</h1>
            <p class="text-sm text-base-content/75">{{ $branch->name }}</p>
        </div>
        <div class="rounded-box border border-base-300 bg-base-100 p-4">
            <ul class="divide-y divide-base-300">
                @forelse ($invoices as $invoice)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <a class="link link-primary break-all font-semibold" href="{{ route('workspace.pos.invoices', [$branch->id, $invoice->id]) }}">{{ $invoice->invoice_no }}</a>
                        <span class="text-sm">{{ ucfirst($invoice->status) }}</span>
                    </li>
                @empty
                    <li class="py-3 text-sm">No issued invoices are available for this branch.</li>
                @endforelse
            </ul>
            {{ $invoices->links() }}
        </div>
    </main>
</x-workspace.layout>
