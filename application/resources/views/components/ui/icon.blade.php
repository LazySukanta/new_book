@props(['name'])

<svg {{ $attributes->class('size-5 shrink-0') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('store')
            <path d="M4 10v10h16V10M3 10l2-6h14l2 6a3 3 0 0 1-4.5 2.6 3 3 0 0 1-4.5 0 3 3 0 0 1-4.5 0A3 3 0 0 1 3 10ZM10 20v-4h4v4"/>
            @break
        @case('overview')
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
            @break
        @case('box')
            <path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 4 9-4m-9 4v10m-4.5-16 9 4"/>
            @break
        @case('receipt')
            <path d="M5 3v18l3-2 4 2 4-2 3 2V3l-3 2-4-2-4 2-3-2Zm4 6h6m-6 4h6"/>
            @break
        @case('alert')
            <path d="m12 4 9 16H3L12 4Zm0 6v4m0 3h.01"/>
            @break
        @case('chevron')
            <path d="m6 9 6 6 6-6"/>
            @break
        @case('arrow')
            <path d="M4 12h15m-6-6 6 6-6 6"/>
            @break
        @case('menu')
            <path d="M4 6h16M4 12h16M4 18h16"/>
            @break
        @case('close')
            <path d="m6 6 12 12M18 6 6 18"/>
            @break
    @endswitch
</svg>
