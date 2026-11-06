@props(['id', 'label', 'error' => null])

<div>
    <label for="{{ $id }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label>
    {{ $slot }}
    @if ($error)
        <x-ui.feedback :id="$id.'-error'" :error="true" class="mt-3">{{ $error }}</x-ui.feedback>
    @endif
</div>
