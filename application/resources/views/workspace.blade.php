<x-workspace.layout :title="(match ($section) { 'pos' => 'Point of sale', 'stock' => 'Stock', default => 'Overview' }).' · KhataPOS'">
    <livewire:workspace :section="$section" />
</x-workspace.layout>
