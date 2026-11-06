@props(['amount'])
@php
    $decimal = (string) \Brick\Math\BigDecimal::of((string) $amount)->toScale(2);
    [$whole, $fraction] = explode('.', ltrim($decimal, '-'));
    $grouped = preg_replace('/(?<=\d)(?=(?:\d{2})*\d{3}$)/', ',', $whole);
@endphp
<span {{ $attributes->class('whitespace-nowrap tabular-nums') }}>{{ str_starts_with($decimal, '-') ? '−' : '' }}₹{{ $grouped }}.{{ $fraction }}</span>
