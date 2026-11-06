@props(['error' => false])

<div {{ $attributes->class(['alert', 'alert-error' => $error, 'alert-info alert-soft' => ! $error]) }} role="{{ $error ? 'alert' : 'status' }}">
    <x-ui.icon name="alert" />
    <span>{{ $slot }}</span>
</div>
