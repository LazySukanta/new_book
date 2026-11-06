@props(['section', 'canSeeStock'])

<nav aria-label="Workspace navigation">
    <ul class="menu w-full gap-1 p-0 text-sm font-semibold">
        <li>
            <a href="{{ route('workspace') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'overview'])
                @if ($section === 'overview') aria-current="page" @endif>
                <x-ui.icon name="overview" /> Overview
            </a>
        </li>
        @can('sales.create')
            <li><a href="{{ route('workspace.pos') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'pos'])
                @if ($section === 'pos') aria-current="page" @endif><x-ui.icon name="receipt" /> Point of sale</a></li>
        @endcan
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
        @can('customers.view')
            <li><a href="{{ route('workspace.customers') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'customers'])
                @if ($section === 'customers') aria-current="page" @endif><x-ui.icon name="receipt" /> Customers</a></li>
        @endcan
        @can('suppliers.manage')
            <li><a href="{{ route('workspace.suppliers') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'suppliers'])
                @if ($section === 'suppliers') aria-current="page" @endif><x-ui.icon name="receipt" /> Suppliers</a></li>
        @endcan
        @canany(['reports.view', 'reports.daily_own'])
            <li><a href="{{ route('workspace.reports') }}" @class(['menu-active bg-secondary! text-secondary-content!' => $section === 'reports'])
                @if ($section === 'reports') aria-current="page" @endif><x-ui.icon name="overview" /> {{ auth()->user()->can('reports.view') ? 'Reports' : 'My daily sales' }}</a></li>
        @endcanany
        @can('users.manage')
            <li><a href="{{ route('workspace.admin', ['section' => 'staff']) }}"><x-ui.icon name="store" /> Administration</a></li>
        @endcan
    </ul>
</nav>
