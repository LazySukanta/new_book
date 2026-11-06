<div class="grid min-h-dvh grid-cols-1 lg:grid-cols-[246px_minmax(0,1fr)]">
    <aside class="hidden flex-col gap-7 border-r border-base-300 bg-base-100 px-4 py-6 lg:flex">
        <a href="{{ route('workspace') }}" class="mx-2 w-fit rounded-field"><x-workspace.brand /></a>
        @if ($branch)
            <button type="button" data-open-dialog="branch-dialog" aria-haspopup="dialog" aria-controls="branch-dialog" class="btn btn-outline h-auto min-h-16 w-full justify-start gap-3 border-base-300 p-2.5 text-left">
                <span class="grid size-9 shrink-0 place-items-center rounded-field bg-primary/10 text-primary"><x-ui.icon name="store" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate" title="{{ $branch->name }}">{{ $branch->name }}</span>
                    <span class="mt-0.5 block text-xs font-normal text-base-content/75">{{ $branch->code }} · Active branch</span>
                </span>
                <x-ui.icon name="chevron" class="size-4" />
            </button>
        @endif
        <x-workspace.navigation :$section :$canSeeStock />
        <div class="mt-auto border-t border-base-300 px-2 pt-4">
            <p class="break-words text-sm font-semibold">{{ $actor->name }}</p>
            <p class="mt-1 text-xs text-base-content/75">{{ $actor->getRoleNames()->implode(', ') }}</p>
            <form method="post" action="{{ route('session.sign-out') }}" class="mt-3">
                @csrf
                <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <div class="min-w-0">
        <header class="flex min-h-16 items-center justify-between gap-3 border-b border-base-300 bg-base-100 px-4 py-2 sm:px-8">
            <div class="flex min-w-0 items-center gap-2">
                <button type="button" data-open-dialog="navigation-dialog" aria-haspopup="dialog" aria-controls="navigation-dialog" aria-label="Open navigation" class="btn btn-ghost btn-square lg:hidden">
                    <x-ui.icon name="menu" />
                </button>
                <span class="font-bold lg:hidden">KhataPOS</span>
                <p class="hidden text-sm text-base-content/75 lg:block">Workspace <span aria-hidden="true" class="mx-1">/</span>
                    <strong class="text-base-content">{{ $section === 'stock' ? 'Stock' : 'Overview' }}</strong>
                </p>
            </div>
            @if ($branch)
                <button type="button" data-open-dialog="branch-dialog" aria-haspopup="dialog" aria-controls="branch-dialog" class="btn btn-ghost max-w-[55%] px-2 lg:hidden">
                    <span class="truncate">{{ $branch->name }}</span><x-ui.icon name="chevron" class="size-4" />
                </button>
                <span class="hidden text-sm font-medium text-base-content/75 lg:block">{{ $date }}</span>
            @endif
        </header>

        <main id="main-content" tabindex="-1" class="mx-auto max-w-[1600px] px-4 py-6 sm:px-8 sm:py-8">
            <h1 class="text-2xl font-bold tracking-tight sm:text-[28px]">{{ $section === 'stock' ? 'Stock' : 'Overview' }}</h1>
            <p class="mt-1 mb-6 text-sm text-base-content/75">
                {{ $section === 'stock' ? 'Current quantities for the active branch.' : 'See what is happening at your branch.' }}
            </p>
            @if ($section === 'stock' && $branch)
                <nav aria-label="Stock tools" class="mb-4 flex flex-wrap gap-2">
                    <a class="btn btn-sm" href="{{ route('workspace.inventory', ['section' => 'low-stock']) }}">Low stock</a>
                    <a class="btn btn-sm" href="{{ route('workspace.inventory', ['section' => 'ledger']) }}">Stock ledger</a>
                    @can('stock.adjust')<a class="btn btn-sm" href="{{ route('workspace.inventory', ['section' => 'products']) }}">Choose product to adjust or count</a>@endcan
                </nav>
            @endif
            @if (! $branch)
                <x-ui.feedback>No branch is available on this device. Ask an owner to check the branch and device access.</x-ui.feedback>
            @else
                @if ($section === 'overview')
                    <section class="flex flex-wrap items-center justify-between gap-4 rounded-box bg-neutral p-5 text-neutral-content sm:p-7" aria-labelledby="branch-heading">
                        <div class="min-w-0">
                            <h2 id="branch-heading" class="break-words text-xl font-bold tracking-tight">{{ $branch->name }}</h2>
                            <p class="mt-1 text-sm text-neutral-content/85">{{ $branch->code }} · {{ $date }}</p>
                        </div>
                        @if ($canSeeStock)
                            <a class="btn border-0 bg-base-100 text-primary shadow-none" href="{{ route('workspace.stock') }}">View stock <x-ui.icon name="arrow" class="size-4" /></a>
                        @endif
                    </section>

                    @if ($metrics)
                        <section class="mt-7" aria-labelledby="overview-heading">
                            <h2 id="overview-heading" class="mb-3 text-base font-bold">Branch at a glance</h2>
                            <dl class="grid grid-cols-2 gap-3 md:grid-cols-3">
                                @foreach ($metrics as $metric)
                                    <div @class(['rounded-box border border-base-300 bg-base-100 p-4', 'col-span-2 md:col-span-1' => isset($metric['money'])])>
                                        <dt class="flex items-center justify-between gap-3 text-sm font-medium text-base-content/75">
                                            {{ $metric['label'] }}
                                            <span class="grid size-9 shrink-0 place-items-center rounded-field bg-primary/10 text-primary">
                                                <x-ui.icon :name="$metric['icon']" />
                                            </span>
                                        </dt>
                                        <dd class="mt-3 text-2xl font-bold tabular-nums">
                                            @if (isset($metric['money']))
                                                <x-ui.money :amount="$metric['money']" />
                                            @else
                                                {{ $metric['count'] }}
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endif
                @endif
                @if ($stock !== null)
                    <section @class(['mt-7' => $section === 'overview']) aria-labelledby="stock-heading">
                        <h2 id="stock-heading" class="mb-3 text-base font-bold">{{ $section === 'overview' ? 'Stock preview' : $branch->name }}</h2>
                        <x-ui.table caption="Branch stock">
                            <thead class="text-base-content/75">
                                <tr>
                                    <th scope="col">Product</th><th scope="col" class="hidden sm:table-cell">SKU</th>
                                    <th scope="col" class="text-right">Quantity</th>
                                    @if ($canSeeValue)<th scope="col" class="text-right">Value</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($stock as $row)
                                    <tr wire:key="product-{{ $row->product_id }}">
                                        <th scope="row" class="break-words font-semibold sm:min-w-36">
                                            {{ $row->name }}
                                            <span class="mt-1 block text-xs font-normal text-base-content/75 [overflow-wrap:anywhere] sm:hidden">{{ $row->sku }}</span>
                                        </th>
                                        <td class="hidden whitespace-nowrap sm:table-cell">{{ $row->sku }}</td>
                                        <td class="text-right tabular-nums">
                                            {{ $row->qty }}
                                            @if ($row->qty < 0)
                                                <span class="block text-xs text-error">Negative stock</span>
                                            @endif
                                            @if (!empty($row->valuation_provisional))
                                                <span class="block text-xs">Estimated value</span>
                                            @endif
                                        </td>
                                        @if ($canSeeValue)<td class="text-right"><x-ui.money :amount="$row->value" /></td>@endif
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $canSeeValue ? 4 : 3 }}" class="py-8 text-center text-base-content/75">No products to show.</td></tr>
                                @endforelse
                            </tbody>
                        </x-ui.table>
                        @if ($section === 'stock')
                            <div class="mt-4">{{ $stock->links() }}</div>
                        @endif
                    </section>
                @endif
            @endif
        </main>
    </div>

    <x-ui.dialog id="navigation-dialog" title="Workspace navigation">
        <div class="mt-3"><x-workspace.navigation :$section :$canSeeStock /></div>
        <form method="post" action="{{ route('session.sign-out') }}" class="mt-3">
            @csrf
            <button class="btn btn-ghost" type="submit">Sign out</button>
        </form>
    </x-ui.dialog>
    @if ($branch)
        <x-ui.dialog id="branch-dialog" title="Switch branch">
            <form wire:submit="selectBranch" class="mt-3">
                <x-ui.field id="branch-selection" label="Branch" :error="$errors->first('branchSelection')">
                    <select id="branch-selection" class="select w-full" wire:model="branchSelection" autofocus
                        @error('branchSelection') aria-invalid="true" aria-describedby="branch-selection-error" @enderror>
                        @foreach ($branches as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->code }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <div class="modal-action">
                    <button type="button" data-close-dialog class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="selectBranch">
                        <span wire:loading.remove wire:target="selectBranch">Switch branch</span>
                        <span wire:loading wire:target="selectBranch" role="status">Switching…</span>
                    </button>
                </div>
            </form>
        </x-ui.dialog>
    @endif
</div>
