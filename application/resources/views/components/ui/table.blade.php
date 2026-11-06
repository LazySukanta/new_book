@props(['caption'])

<div class="overflow-x-auto rounded-box border border-base-300 bg-base-100" tabindex="0" role="region" aria-label="{{ $caption }}">
    <table class="table table-sm">
        <caption class="sr-only">{{ $caption }}</caption>
        {{ $slot }}
    </table>
</div>
