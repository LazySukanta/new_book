@props(['section', 'canSeeStock'])

<nav aria-label="Workspace navigation">
    <ul class="menu w-full gap-1 p-0 text-sm font-semibold">
        <li>
            <a href="{{ route('workspace') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'overview'])
                @if ($section === 'overview') aria-current="page" @endif>
                <x-ui.icon name="overview" /> Overview
            </a>
        </li>
        @if ($canSeeStock)
            <li>
                <a href="{{ route('workspace.stock') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'stock'])
                    @if ($section === 'stock') aria-current="page" @endif>
                    <x-ui.icon name="box" /> Stock
                </a>
            </li>
        @endif
        @can('products.view')
            <li><a href="{{ route('workspace.inventory', ['section' => 'products']) }}"><x-ui.icon name="box" /> Catalogue</a></li>
        @endcan
        @can('purchases.manage')
            <li><a href="{{ route('workspace.purchases.index') }}"><x-ui.icon name="receipt" /> Purchases</a></li>
        @endcan
        @can('users.manage')
            <li><a href="{{ route('workspace.admin', ['section' => 'staff']) }}"><x-ui.icon name="store" /> Administration</a></li>
        @endcan
    </ul>
</nav>
