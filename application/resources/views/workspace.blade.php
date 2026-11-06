<x-workspace.layout :title="(match ($section) { 'pos' => 'Point of sale', 'stock' => 'Stock', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'reports' => 'Reports', default => 'Overview' }).' · KhataPOS'">
    <livewire:workspace :section="$section" />
</x-workspace.layout>
