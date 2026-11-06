@props(['id', 'title'])

<dialog id="{{ $id }}" {{ $attributes->class('modal modal-middle') }} aria-labelledby="{{ $id }}-title" wire:ignore.self>
    <div class="modal-box max-w-md">
        <div class="flex items-center justify-between gap-4">
            <h2 id="{{ $id }}-title" class="text-lg font-bold">{{ $title }}</h2>
            <button type="button" class="btn btn-ghost btn-square" data-close-dialog aria-label="Close {{ strtolower($title) }}">
                <x-ui.icon name="close" />
            </button>
        </div>
        {{ $slot }}
    </div>
    <form method="dialog" class="modal-backdrop">
        <button tabindex="-1" aria-label="Dismiss dialog">Dismiss</button>
    </form>
</dialog>
