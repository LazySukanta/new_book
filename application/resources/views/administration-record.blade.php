<form method="post" action="{{ route('workspace.admin.save', ['section' => $section, 'record' => $item['id'] ?? null]) }}" class="grid gap-4 sm:grid-cols-2">
    @csrf
    <x-workspace.admin-field name="name" label="Name" :value="$item['name'] ?? ''" required />
    @if ($section === 'branches')
        <x-workspace.admin-field name="code" label="Branch code" :value="$item['code'] ?? ''" required />
        <x-workspace.admin-field name="state_code" label="State" :options="$states" :value="$item['state_code'] ?? ''" />
        <x-workspace.admin-field name="gstin" label="GSTIN" :value="$item['gstin'] ?? ''" />
        <x-workspace.admin-field name="phone" label="Phone" type="tel" :value="$item['phone'] ?? ''" />
        <x-workspace.admin-field name="address" label="Address" type="textarea" :value="$item['address'] ?? ''" />
    @else
        <x-workspace.admin-field name="email" label="Email" type="email" :value="$item['email'] ?? ''" required />
        <x-workspace.admin-field name="password" :label="$item ? 'New password (optional)' : 'Initial password'" type="password" :required="!$item" />
        <x-workspace.admin-field name="role" label="Role" :options="$owner ? ['Cashier' => 'Cashier', 'Manager' => 'Manager'] : ['Cashier' => 'Cashier']" :value="$item['roles'][0]['name'] ?? 'Cashier'" required />
        <x-workspace.admin-field name="branch_ids" label="Staff branches" :options="$branchOptions" :value="collect($item['branches'] ?? [])->pluck('id')->all()" multiple required />
    @endif
    <x-workspace.admin-field name="is_active" label="Status" :options="$statusOptions" :value="(int) ($item['is_active'] ?? true)" required />
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">{{ $item ? 'Save changes' : 'Create' }}</button></div>
</form>
