@php
    $customer = $section === 'customers';
    $partyLabel = $customer ? 'customer' : 'supplier';
    $canManage = $actor->can($customer ? 'customers.manage' : 'suppliers.manage');
    $canCreate = $actor->can($customer ? 'customers.create' : 'suppliers.manage');
    $canPay = $actor->can($customer ? 'payments.receive' : 'payments.pay_supplier');
    $canOpen = $actor->hasRole('Owner') && $actor->can('opening.manage');
@endphp
<div wire:key="parties-{{ $section }}-{{ $branch->id }}" wire:ignore
    x-data="workspaceParties(@js(url('/workspace/party-data')), @js((string) $branch->id), @js(csrf_token()), @js($actor->tenant_id.'.'.$actor->id), @js($customer))"
    class="grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(16rem,1fr)_minmax(0,2.35fr)]">
    <section class="min-w-0 rounded-box border border-base-300 bg-base-100 p-4" aria-labelledby="party-list">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="party-list" class="font-bold">{{ $customer ? 'Customer directory' : 'Supplier directory' }}</h2>
            @if ($canCreate)
                <button type="button" class="btn btn-primary btn-sm" @click="openProfile(false)">Add {{ $partyLabel }}</button>
            @endif
        </div>
        <label for="party-search" class="mt-3 block text-sm font-semibold">Search name or phone</label>
        <input id="party-search" class="input mt-1 w-full" maxlength="100" x-model="search" @input.debounce.200ms="loadList()">
        <p x-show="loadingList" class="mt-3 text-sm" role="status">Loading directory…</p>
        <p x-show="!loadingList && !list.length" class="mt-3 text-sm text-base-content/75">No matching {{ $section }}.</p>
        <ul class="mt-2 divide-y divide-base-300" aria-label="{{ ucfirst($section) }}">
            <template x-for="party in list" :key="party.id">
                <li>
                    <button type="button" class="btn btn-ghost h-auto min-h-11 w-full justify-start px-2 py-2 text-left"
                        :class="{ 'bg-secondary text-secondary-content': selected?.id === party.id }"
                        :aria-pressed="selected?.id === party.id" :disabled="busy" @click="choose(party)">
                        <span class="min-w-0 break-words">
                            <span class="block" x-text="party.name"></span>
                            <span class="block text-xs font-normal" x-text="party.is_system ? 'Walk-in' : !party.is_active ? 'Inactive' : party.phone"></span>
                        </span>
                    </button>
                </li>
            </template>
        </ul>
        <nav x-show="listMeta.last_page > 1" class="mt-2 flex items-center justify-between gap-2" aria-label="Directory pages">
            <button type="button" class="btn btn-sm" :disabled="loadingList || listMeta.current_page <= 1" @click="loadList(listMeta.current_page - 1)">Previous</button>
            <span class="text-xs" x-text="listMeta.current_page + ' / ' + listMeta.last_page"></span>
            <button type="button" class="btn btn-sm" :disabled="loadingList || listMeta.current_page >= listMeta.last_page" @click="loadList(listMeta.current_page + 1)">Next</button>
        </nav>
    </section>

    <section class="min-w-0 space-y-4 rounded-box border border-base-300 bg-base-100 p-4 sm:p-5" aria-labelledby="party-ledger">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h2 id="party-ledger" x-ref="ledgerHeading" tabindex="-1" class="break-words text-lg font-bold" x-text="selected?.name ?? 'Branch ledger'"></h2>
                <p class="text-sm text-base-content/75">{{ $branch->name }} · {{ $branch->code }}</p>
                <p class="mt-1 break-words text-sm" x-show="selected?.phone || selected?.gstin"
                    x-text="[selected?.phone, selected?.gstin && 'GSTIN ' + selected.gstin].filter(Boolean).join(' · ')"></p>
                <p class="whitespace-pre-wrap break-words text-sm" x-show="selected?.address" x-text="selected?.address"></p>
            </div>
            @if ($canManage)
                <button x-show="selected && !selected.is_system" type="button" class="btn btn-sm" @click="openProfile(true)">Edit {{ $partyLabel }}</button>
            @endif
        </div>
        <p role="alert" class="text-sm text-error" x-show="error" x-text="error"></p>
        <p role="status" class="text-sm text-success" x-show="status" x-text="status"></p>
        <p x-show="!selected" class="text-sm text-base-content/75">Select a {{ $partyLabel }} to view their ledger.</p>
        <p x-show="selected?.is_system" class="text-sm text-base-content/75">Walk-in sales have no credit account.</p>
        <div x-show="selected && !selected.is_system" class="space-y-4">
            <dl class="flex flex-wrap gap-x-8 gap-y-3 rounded-box border border-base-300 bg-base-200 p-4">
                <div><dt class="text-sm text-base-content/75" x-text="isHistoricalView ? 'Balance at selected cut-offs' : 'Current balance'"></dt>
                    <dd class="text-xl font-bold tabular-nums" x-text="ledgerMeta.balance == null ? '—' : '₹' + ledgerMeta.balance"></dd></div>
                <div><dt class="text-sm text-base-content/75">Opening status</dt>
                    <dd class="font-semibold" x-text="openingLabel ?? '—'"></dd>
                    <dd class="text-xs text-base-content/75" x-show="ledgerMeta.opening_status_effective_at"
                        x-text="'Effective ' + date(ledgerMeta.opening_status_effective_at)"></dd>
                    <dd class="text-xs text-base-content/75" x-show="ledgerMeta.opening_confirmed_at"
                        x-text="'Recorded ' + date(ledgerMeta.opening_confirmed_at)"></dd>
                </div>
            </dl>
            <p x-show="ledgerMeta.opening_status === 'not_entered'" class="text-sm text-base-content/75">The balance may be incomplete. Trading can continue while opening records are entered.</p>
            <div class="flex flex-wrap gap-2" x-show="!isHistoricalView || retry">
                <button x-show="retry" type="button" class="btn btn-primary btn-sm" @click="recover()">Recover posting</button>
                @if ($canPay)
                    <button x-show="!retry" type="button" class="btn btn-primary btn-sm" @click="start('payment')">{{ $customer ? 'Record receipt' : 'Record payment' }}</button>
                @endif
                @if ($canOpen)
                    <button x-show="!retry" type="button" class="btn btn-sm" @click="start('opening')">Record opening balance</button>
                    <button x-show="!retry && ledgerMeta.opening_status !== 'confirmed_zero'" type="button" class="btn btn-sm" @click="start('zero')">Confirm zero opening</button>
                @endif
            </div>
            <form class="flex flex-wrap items-end gap-2 border-t border-base-300 pt-3" @submit.prevent="filterLedger()">
                <label class="min-w-0 text-xs font-semibold">Effective through (IST)
                    <input type="datetime-local" class="input input-sm block max-w-full" x-model="filters.as_of">
                </label>
                <label class="min-w-0 text-xs font-semibold">Recorded through (IST)
                    <input type="datetime-local" class="input input-sm block max-w-full" x-model="filters.recorded_as_of">
                </label>
                <button class="btn btn-sm" :disabled="loadingLedger">Apply dates</button>
                <button x-show="isHistoricalView" type="button" class="btn btn-ghost btn-sm"
                    @click="filters = { as_of: '', recorded_as_of: '' }; filterLedger()">Clear dates</button>
            </form>
            <p x-show="loadingLedger" role="status" class="text-sm">Loading ledger…</p>
            <p x-show="!loadingLedger && !entries.length" class="text-sm text-base-content/75">No entries for the selected dates.</p>
            <div x-show="entries.length" class="overflow-x-auto" tabindex="0" role="region" aria-label="Ledger entries">
                <table class="table table-sm max-sm:block" role="table">
                    <thead class="max-sm:sr-only"><tr><th scope="col">Entry</th><th scope="col">Effective</th><th scope="col">Recorded</th><th scope="col" class="text-right">Effect</th><th scope="col" class="text-right">Balance</th></tr></thead>
                    <tbody class="max-sm:block" role="rowgroup">
                        <template x-for="row in entries" :key="row.source + ':' + row.id">
                            <tr class="max-sm:grid max-sm:grid-cols-2 max-sm:border-b max-sm:border-base-300 max-sm:py-2" role="row">
                                <th scope="row" class="max-sm:col-span-2 sm:max-w-52" role="rowheader">
                                    <button x-show="['opening','receipt','payment'].includes(row.source)" type="button" class="link link-primary text-left" @click="showEntry(row)"
                                        x-text="label(row.source) + ' · ' + label(row.kind) + ' #' + row.id"></button>
                                    <span x-show="!['opening','receipt','payment'].includes(row.source)" x-text="label(row.kind) + ' #' + row.id"></span>
                                    <span class="block break-all text-xs font-normal text-base-content/75" x-text="row.reference"></span>
                                </th>
                                <td class="sm:whitespace-nowrap" role="cell"><span class="block text-xs text-base-content/75 sm:hidden" aria-hidden="true">Effective</span>
                                    <span x-text="date(row.effective_at)"></span></td>
                                <td class="sm:whitespace-nowrap" role="cell"><span class="block text-xs text-base-content/75 sm:hidden" aria-hidden="true">Recorded</span>
                                    <span x-text="date(row.created_at)"></span></td>
                                <td class="break-all tabular-nums sm:text-right" role="cell"><span class="block text-xs text-base-content/75 sm:hidden" aria-hidden="true">Effect</span>
                                    <span x-text="'₹' + row.signed_amount"></span></td>
                                <td class="break-all font-semibold tabular-nums sm:text-right" role="cell"><span class="block text-xs font-normal text-base-content/75 sm:hidden" aria-hidden="true">Balance</span>
                                    <span x-text="'₹' + row.running_balance"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <nav x-show="ledgerMeta.last_page > 1" class="flex items-center justify-between gap-2" aria-label="Ledger pages">
                <button type="button" class="btn btn-sm" :disabled="loadingLedger || ledgerMeta.current_page <= 1" @click="loadLedger(ledgerMeta.current_page - 1)">Previous</button>
                <span class="text-xs" x-text="ledgerMeta.current_page + ' / ' + ledgerMeta.last_page"></span>
                <button type="button" class="btn btn-sm" :disabled="loadingLedger || ledgerMeta.current_page >= ledgerMeta.last_page" @click="loadLedger(ledgerMeta.current_page + 1)">Next</button>
            </nav>
        </div>
    </section>

    @include('components.workspace.party-dialogs')
</div>
