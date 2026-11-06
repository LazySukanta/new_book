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
    </ul>
</nav>
