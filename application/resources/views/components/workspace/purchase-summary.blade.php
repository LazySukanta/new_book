@props(['snapshot'])

@php($supplier = is_string($snapshot['supplier_snapshot'] ?? null) ? json_decode($snapshot['supplier_snapshot'], true) : ($snapshot['supplier_snapshot'] ?? []))
<dl class="mb-4 grid gap-3 rounded-box border border-base-300 bg-base-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
    <div><dt class="text-sm text-base-content/75">Supplier</dt><dd>{{ $supplier['name'] ?? 'Supplier #'.$snapshot['supplier_id'] }}</dd></div>
    <div><dt class="text-sm text-base-content/75">Invoice</dt><dd class="[overflow-wrap:anywhere]">{{ $snapshot['supplier_invoice_no'] }} · {{ $snapshot['supplier_invoice_date'] }}</dd></div>
    <div><dt class="text-sm text-base-content/75">Status</dt><dd>{{ $snapshot['status'] === 'deleted' ? 'Removed' : 'Posted' }} · Revision {{ $snapshot['current_revision'] }}</dd></div>
    @foreach (['taxable' => 'Taxable', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'discount_amount' => 'Discount', 'total' => 'Total', 'paid_amount' => 'Applied payments', 'balance_amount' => 'Balance due'] as $field => $label)
        <div><dt class="text-sm text-base-content/75">{{ $label }}</dt><dd><x-ui.money :amount="$snapshot[$field] ?? '0.00'" /></dd></div>
    @endforeach
</dl>
