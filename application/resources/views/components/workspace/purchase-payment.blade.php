@props(['row' => [], 'index'])

<div data-repeat-row class="grid gap-3 rounded-box border border-base-300 p-3 sm:grid-cols-4">
    <x-workspace.admin-field :name="'payments['.$index.'][mode]'" label="Payment mode" :options="['cash'=>'Cash','upi'=>'UPI','card'=>'Card','bank'=>'Bank']" :value="$row['mode'] ?? ''" required />
    <x-workspace.admin-field :name="'payments['.$index.'][amount]'" label="Amount paid" :value="$row['amount'] ?? ''" required />
    <x-workspace.admin-field :name="'payments['.$index.'][reference]'" label="Payment reference" :value="$row['reference'] ?? ''" />
    <div class="flex items-end"><button type="button" data-remove-row class="btn btn-ghost btn-sm">Remove payment</button></div>
</div>
