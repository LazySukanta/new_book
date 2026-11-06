<x-workspace.layout :title="($section === 'stock' ? 'Stock' : 'Overview').' · KhataPOS'">
    <livewire:workspace :section="$section" />
</x-workspace.layout>
