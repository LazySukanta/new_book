@php
    $sections = $owner ? ['staff' => 'Staff', 'branches' => 'Branches', 'devices' => 'Devices', 'settings' => 'Shop settings', 'series' => 'Document series'] : ['staff' => 'Staff'];
    $heading = $sections[$section];
    $branchOptions = $branches->mapWithKeys(fn ($branch) => [$branch->id => $branch->name.' · '.$branch->code])->all();
    $statusOptions = [1 => 'Active', 0 => 'Inactive'];
    $yesNo = [1 => 'Yes', 0 => 'No'];
@endphp
<x-workspace.layout :title="$heading.' · KhataPOS'">
    <header class="border-b border-base-300 bg-base-100">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-8">
            <a href="{{ route('workspace') }}"><x-workspace.brand /></a>
            <a class="btn btn-ghost" href="{{ route('workspace') }}">Back to workspace</a>
        </div>
    </header>
    <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-6 sm:px-8">
        <nav aria-label="Administration" class="mb-6 flex flex-wrap gap-2">
            @foreach ($sections as $key => $label)
                <a href="{{ route('workspace.admin', ['section' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $section === $key])
                    @if ($section === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <h1 class="mb-5 text-2xl font-bold tracking-tight">{{ $heading }}</h1>
        @if (session('workspace.saved'))
            <x-ui.feedback class="mb-4">{{ session('workspace.saved') }}</x-ui.feedback>
        @endif
        @if ($errors->any())
            <x-ui.feedback :error="true" class="mb-4">
                @foreach ($errors->all() as $error)<span class="block">{{ $error }}</span>@endforeach
            </x-ui.feedback>
        @endif
        @if ($pairing)
            <section class="mb-5 rounded-box border border-primary bg-base-100 p-5" aria-labelledby="pairing-title">
                <h2 id="pairing-title" class="font-bold">One-time pairing code</h2>
                <p class="mt-2 text-sm">Enter this code when signing in on the new browser. It expires in 30 minutes and is shown here once.</p>
                <code class="mt-3 block break-all select-all">{{ $pairing }}</code>
            </section>
        @endif

        @if ($section === 'branches' || $section === 'staff')
            <section class="mb-6 rounded-box border border-base-300 bg-base-100 p-5">
                <h2 class="mb-4 font-bold">{{ $section === 'staff' ? 'Add staff member' : 'Add branch' }}</h2>
                @include('administration-record', ['item' => []])
            </section>
            <section aria-label="{{ $heading }} records" class="grid gap-3">
                @forelse ($records as $item)
                    <details class="rounded-box border border-base-300 bg-base-100 p-4">
                        <summary class="cursor-pointer break-words font-semibold">{{ $item['name'] }}
                            <span class="ml-2 text-sm font-normal text-base-content/75">{{ $item['code'] ?? collect($item['roles'] ?? [])->pluck('name')->implode(', ') }} · {{ $item['is_active'] ? 'Active' : 'Inactive' }}</span>
                        </summary>
                        <div class="mt-4">
                            @if ($section === 'staff' && ($item['id'] === $actor->id || collect($item['roles'])->contains('name', 'Owner')))
                                <p class="text-sm">This account is protected from staff edits.</p>
                            @else
                                @include('administration-record')
                            @endif
                        </div>
                    </details>
                @empty
                    <p>No records to show.</p>
                @endforelse
            </section>
            @if (($pagination['last_page'] ?? 1) > 1)
                <nav aria-label="Record pages" class="mt-4 flex gap-3">
                    @if ($pagination['current_page'] > 1)<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] - 1]) }}">Previous</a>@endif
                    @if ($pagination['current_page'] < $pagination['last_page'])<a class="btn" href="{{ request()->fullUrlWithQuery(['page' => $pagination['current_page'] + 1]) }}">Next</a>@endif
                </nav>
            @endif
        @elseif ($section === 'devices')
            <form method="post" action="{{ route('workspace.admin.save', ['section' => $section]) }}" class="mb-6 grid gap-4 rounded-box border border-base-300 bg-base-100 p-5 sm:grid-cols-2">
                @csrf
                <x-workspace.admin-field name="name" label="Device name" required />
                <x-workspace.admin-field name="branch_ids" label="Approved branches" :options="$branchOptions" multiple required />
                <div><button type="submit" class="btn btn-primary">Approve device</button></div>
            </form>
            <x-ui.table caption="Approved devices">
                <thead><tr><th scope="col">Device</th><th scope="col">Branches</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                    @foreach ($records as $item)
                        <tr>
                            <th scope="row">{{ $item['name'] }}{{ $item['id'] === $currentDeviceId ? ' (this browser)' : '' }}</th>
                            <td>{{ collect($item['branches'])->pluck('name')->implode(', ') }}</td>
                            <td>{{ $item['status'] === 'revoked' ? 'Revoked' : ($item['paired_at'] ? 'Paired' : 'Awaiting first sign-in') }}</td>
                            <td>
                                @if ($item['status'] === 'active')
                                    <form method="post" action="{{ route('workspace.admin.save', ['section' => $section, 'record' => $item['id']]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline btn-error btn-sm" aria-label="Revoke {{ $item['name'] }}">Revoke</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @elseif ($section === 'settings')
            <form method="post" enctype="multipart/form-data" action="{{ route('workspace.admin.save', ['section' => $section]) }}" class="grid gap-4 rounded-box border border-base-300 bg-base-100 p-5 sm:grid-cols-2">
                @csrf
                @foreach (['name' => 'Shop name', 'legal_name' => 'Legal name', 'max_cashier_discount_bp' => 'Cashier discount limit (basis points)', 'manager_cancel_window_days' => 'Manager cancellation window (days)'] as $name => $label)
                    <x-workspace.admin-field :$name :$label :value="$records[$name]" :required="$name === 'name'" />
                @endforeach
                <x-workspace.admin-field name="gst_registration_type" label="GST registration" :options="['regular' => 'Regular', 'composition' => 'Composition', 'unregistered' => 'Unregistered']" :value="$records['gst_registration_type']" required />
                <x-workspace.admin-field name="receipt_width_mm" label="Receipt width" :options="[58 => '58 mm', 80 => '80 mm']" :value="$records['receipt_width_mm']" required />
                @foreach (['price_inclusive_default' => 'Prices include tax by default', 'hsn_required' => 'Require HSN', 'allow_negative_stock' => 'Allow negative stock'] as $name => $label)
                    <x-workspace.admin-field :$name :$label :options="$yesNo" :value="(int) $records[$name]" required />
                @endforeach
                <x-workspace.admin-field name="logo" label="Shop logo" type="file" />
                <x-workspace.admin-field name="footer" label="Receipt footer" type="textarea" :value="$records['footer']" />
                <div class="sm:col-span-2"><button type="submit" class="btn btn-primary">Save settings</button></div>
            </form>
        @elseif ($section === 'series')
            @if ($branchId)
                <form method="get" action="{{ route('workspace.admin', ['section' => $section]) }}" class="mb-5 flex flex-wrap items-end gap-3">
                    <x-workspace.admin-field name="branch" label="Branch" :options="$pairedBranches->pluck('name', 'id')->all()" :value="$branchId" required />
                    <button class="btn" type="submit">View series</button>
                </form>
                <form method="post" action="{{ route('workspace.admin.save', ['section' => $section]) }}" class="mb-6 grid gap-4 rounded-box border border-base-300 bg-base-100 p-5 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                    <x-workspace.admin-field name="financial_year" label="Financial year (YY-YY)" :value="$year" required />
                    <x-workspace.admin-field name="document_type" label="Document type" :options="collect($documentTypes)->mapWithKeys(fn ($type) => [$type => ucfirst(str_replace('_', ' ', $type))])->all()" required />
                    <x-workspace.admin-field name="owning_channel" label="Channel" :options="['web' => 'Web', 'windows' => 'Windows', 'mobile' => 'Mobile']" required />
                    <x-workspace.admin-field name="prefix_list" label="Prefixes (comma-separated)" />
                    <p class="text-sm text-base-content/75 sm:col-span-2">Leave prefixes blank for the default credit-note series. Existing counters are read-only.</p>
                    <div><button type="submit" class="btn btn-primary">Add series</button></div>
                </form>
                <x-ui.table caption="Document series">
                    <thead><tr><th scope="col">Year</th><th scope="col">Document</th><th scope="col">Channel</th><th scope="col">Prefix</th><th scope="col">Next</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                        @forelse ($records as $item)
                            <tr><td>{{ $item['financial_year'] }}</td><th scope="row">{{ str_replace('_', ' ', $item['document_type']) }}</th><td>{{ $item['owning_channel'] }}</td><td>{{ $item['prefix'] }}</td><td>{{ $item['next_number'] }}</td>
                                <td>@if ($item['status'] === 'exhausted')<span class="font-semibold text-error">Exhausted</span>@else{{ ucfirst($item['status']) }}@endif</td></tr>
                        @empty
                            <tr><td colspan="6">No series configured for this branch.</td></tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            @else
                <x-ui.feedback>Pair this browser with an active branch to manage its document series.</x-ui.feedback>
            @endif
        @endif
    </main>
</x-workspace.layout>
