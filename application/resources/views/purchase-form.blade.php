@php
    $correcting = $section === 'correct';
    $lines = array_filter((array) old('items', $record['items'] ?? [[]]), 'is_array') ?: [[]];
    $payments = array_filter((array) old('payments', []), 'is_array');
    $yesNo = [0 => 'No', 1 => 'Yes'];
@endphp
@if (!$correcting)
    <details class="mb-4 rounded-box border border-base-300 bg-base-100 p-4">
        <summary class="cursor-pointer font-semibold">Add a supplier</summary>
        <form method="post" action="{{ route('workspace.purchases.supplier') }}" class="mt-4 grid gap-3 sm:grid-cols-3">
            @csrf
            <x-workspace.admin-field name="name" label="Supplier name" required />
            <x-workspace.admin-field name="phone" label="Phone" type="tel" />
            <div class="flex items-end"><button type="submit" class="btn">Save supplier</button></div>
        </form>
    </details>
@endif
<form method="post" action="{{ $correcting ? route('workspace.purchases.preview', $record['id']) : route('workspace.purchases.store') }}" class="grid gap-5">
    @csrf
    <input type="hidden" name="branch_id" value="{{ $branch->id }}">
    @if ($correcting)
        <input type="hidden" name="expected_revision" value="{{ $record['current_revision'] }}">
        <input type="hidden" name="action" value="update">
    @else
        <input type="hidden" name="key" value="{{ $key }}">
    @endif
    <section class="rounded-box border border-base-300 bg-base-100 p-5">
        <h2 class="mb-4 font-bold">Supplier bill</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-workspace.admin-field name="supplier_id" label="Supplier" placeholder="Choose a supplier" :options="$suppliers" :value="old('supplier_id', $record['supplier_id'] ?? request('supplier'))" required />
            <x-workspace.admin-field name="supplier_invoice_no" label="Supplier invoice number" :value="old('supplier_invoice_no', $record['supplier_invoice_no'] ?? '')" required />
            <x-workspace.admin-field name="supplier_invoice_date" label="Supplier invoice date" type="date" :value="old('supplier_invoice_date', $record['supplier_invoice_date'] ?? $today)" required />
            <x-workspace.admin-field name="tax_type" label="Tax treatment" :options="['intra' => 'Within state (CGST + SGST)', 'inter' => 'Interstate (IGST)']" :value="old('tax_type', $record['tax_type'] ?? 'intra')" required />
            <x-workspace.admin-field name="cost_includes_tax" label="Unit cost includes tax" :options="$yesNo" :value="old('cost_includes_tax', (int) ($record['cost_includes_tax'] ?? false))" required />
            <x-workspace.admin-field name="input_tax_recoverable" label="Input tax recoverable" :options="$yesNo" :value="old('input_tax_recoverable', (int) ($record['input_tax_recoverable'] ?? false))" required />
            <x-workspace.admin-field name="discount_amount" label="Bill discount" :value="old('discount_amount', $record['discount_amount'] ?? '0.00')" required />
            @if ($correcting)
                <x-workspace.admin-field name="reason" label="Correction reason" :value="old('action') === 'update' ? old('reason') : ''" required />
            @endif
        </div>
    </section>
    <section class="rounded-box border border-base-300 bg-base-100 p-5" data-repeat-group data-next="{{ count($lines) }}" data-limit="100">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-bold">Purchase lines</h2>
            <button type="button" class="btn btn-sm" data-add-row>Add product line</button>
        </div>
        <p class="mb-3 text-sm text-base-content/75">
            {{ $correcting ? 'If a cost changes, confirm how much of the original purchase is still on hand.' : 'Leave GST blank to use the product’s rate. Enter rates in basis points (1800 = 18%).' }}
        </p>
        @if ($products->isEmpty())
            <p class="mb-3 text-sm">Add an active product in <a class="link" href="{{ route('workspace.inventory', ['section' => 'edit']) }}">Catalogue</a> before recording a bill.</p>
        @endif
        <div class="grid gap-3" data-repeat-rows>
            @foreach (array_values($lines) as $index => $line)
                <x-workspace.purchase-line :row="$line" :$index :$products :$correcting />
            @endforeach
        </div>
        <template><x-workspace.purchase-line index="__ROW__" :$products :$correcting /></template>
    </section>
    @if (!$correcting && $canPay)
        <section class="rounded-box border border-base-300 bg-base-100 p-5" data-repeat-group data-next="{{ count($payments) }}" data-limit="10">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-bold">Payments made</h2>
                <button class="btn btn-sm" type="button" data-add-row>Add payment</button>
            </div>
            <p class="mb-3 text-sm text-base-content/75">Add only payments already made. Any unpaid amount remains due to the supplier.</p>
            <div class="grid gap-3" data-repeat-rows>
                @foreach (array_values($payments) as $index => $payment)
                    <x-workspace.purchase-payment :row="$payment" :$index />
                @endforeach
            </div>
            <template><x-workspace.purchase-payment index="__ROW__" /></template>
        </section>
    @endif
    <div><button class="btn btn-primary" type="submit">{{ $correcting ? 'Preview correction' : 'Record purchase' }}</button></div>
</form>
@if ($correcting)
    <details class="mt-6 rounded-box border border-base-300 bg-base-100 p-4">
        <summary class="cursor-pointer font-semibold">Remove purchase from active records</summary>
        <form method="post" action="{{ route('workspace.purchases.preview', $record['id']) }}" class="mt-4 grid gap-3">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <input type="hidden" name="expected_revision" value="{{ $record['current_revision'] }}">
            <input type="hidden" name="action" value="soft_delete">
            <x-workspace.admin-field name="reason" label="Removal reason" :value="old('action') === 'soft_delete' ? old('reason') : ''" required />
            <div><button class="btn btn-outline btn-error" type="submit">Preview removal</button></div>
        </form>
    </details>
@endif
