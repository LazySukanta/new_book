<div class="grid gap-4 lg:grid-cols-3">
    @foreach (['units' => 'Units', 'tax-rates' => 'GST rates', 'categories' => 'Categories'] as $reference => $title)
        <section class="rounded-box border border-base-300 bg-base-100 p-5">
            <h2 class="mb-3 font-bold">{{ $title }}</h2>
            <ul class="mb-4 grid gap-2 text-sm">
                @forelse ($references[$reference] as $option)
                    <li>{{ $option['name'] }} @if(isset($option['code'])) · {{ $option['code'] }} @endif</li>
                @empty
                    <li class="text-base-content/75">None configured.</li>
                @endforelse
            </ul>
            @if ($reference !== 'tax-rates' || auth()->user()->hasRole('Owner'))
                <form method="post" action="{{ route('workspace.inventory.reference', ['reference' => $reference]) }}" class="grid gap-3">
                    @csrf
                    <x-workspace.admin-field name="name" label="Name" required />
                    @if ($reference === 'units')
                        <x-workspace.admin-field name="code" label="Unit code" required />
                        <x-workspace.admin-field name="allow_decimal" label="Allow fractional quantities" :options="[0 => 'No', 1 => 'Yes']" :value="0" required />
                    @elseif ($reference === 'tax-rates')
                        <x-workspace.admin-field name="rate_bp" label="Basis points (1800 = 18%)" type="number" required />
                    @endif
                    <div><button class="btn btn-primary" type="submit">Add {{ ['units' => 'unit', 'tax-rates' => 'GST rate', 'categories' => 'category'][$reference] }}</button></div>
                </form>
            @endif
        </section>
    @endforeach
</div>
