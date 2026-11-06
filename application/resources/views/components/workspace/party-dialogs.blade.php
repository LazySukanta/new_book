<x-ui.dialog id="party-profile" title="{{ ucfirst($partyLabel) }} record" x-ref="profile">
    <form class="mt-3 space-y-3" @submit.prevent="saveProfile(@js($canManage))">
        <p class="text-sm text-error" role="alert" x-text="formError"></p>
        <label class="block text-sm font-semibold">Name <input class="input mt-1 w-full" x-model="profile.name" maxlength="255" required autofocus></label>
        <label class="block text-sm font-semibold">Phone <input class="input mt-1 w-full" type="tel" x-model="profile.phone" maxlength="32"></label>
        <label class="block text-sm font-semibold">Address <textarea class="textarea mt-1 w-full" rows="2" x-model="profile.address" maxlength="2000"></textarea></label>
        <div class="grid gap-2 sm:grid-cols-2">
            <label class="min-w-0 text-sm font-semibold">GST state
                <select class="select mt-1 w-full" x-model="profile.state_code">
                    <option value="">Select state</option>
                    @foreach ($states as $code => $state)
                        <option value="{{ $code }}">{{ $state }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-0 text-sm font-semibold">GSTIN <input class="input mt-1 w-full" x-model="profile.gstin" maxlength="15"></label>
        </div>
        @if ($canManage)
            @if ($customer)
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="text-sm font-semibold">Credit limit (₹)
                        <input class="input mt-1 w-full" x-model="profile.credit_limit" inputmode="decimal" required>
                    </label>
                    <label class="text-sm font-semibold">Preferred pricing
                        <select class="select mt-1 w-full" x-model="profile.preferred_sale_type">
                            <option value="retail">Retail</option><option value="wholesale">Wholesale</option>
                        </select>
                    </label>
                </div>
            @endif
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="checkbox checkbox-sm" x-model="profile.is_active"> Active</label>
        @endif
        <button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Saving…' : 'Save {{ $partyLabel }}'"></button>
    </form>
</x-ui.dialog>

<x-ui.dialog id="party-entry" title="Ledger entry" x-ref="details">
    <p class="text-sm text-error" role="alert" x-text="detailsError"></p>
    <p class="text-sm" x-show="!details && !detailsError" role="status">Loading entry…</p>
    <div class="mt-3 space-y-3" x-show="details">
        <h3 class="font-bold" x-text="entry ? label(entry.kind) + ' #' + entry.id : ''"></h3>
        <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-sm">
            <dt>Effective</dt><dd x-text="date(entry?.effective_at)"></dd>
            <dt>Recorded</dt><dd x-text="date(entry?.created_at)"></dd>
            <dt x-show="details?.actor">Recorded by</dt><dd x-show="details?.actor" x-text="details?.actor"></dd>
            <dt>Amount</dt><dd x-text="details && '₹' + (details.record.amount ?? details.record.signed_amount)"></dd>
            <dt>Balance effect</dt><dd x-text="entry && '₹' + entry.signed_amount"></dd>
            <dt>Status</dt><dd x-text="details && label(details.record.status)"></dd>
            <dt x-show="details?.record.reference">Reference</dt><dd class="break-all" x-show="details?.record.reference" x-text="details?.record.reference"></dd>
            <dt x-show="details?.record.reason">Reason</dt><dd class="break-words" x-show="details?.record.reason" x-text="details?.record.reason"></dd>
            <dt x-show="details?.record.note">Note</dt><dd class="whitespace-pre-wrap break-words" x-show="details?.record.note" x-text="details?.record.note"></dd>
            <dt x-show="details?.record.reversal_of_id">Reversal of</dt><dd x-show="details?.record.reversal_of_id" x-text="'#' + details?.record.reversal_of_id"></dd>
            <dt x-show="details?.record.replaces_entry_id">Replacement for</dt><dd x-show="details?.record.replaces_entry_id" x-text="'#' + details?.record.replaces_entry_id"></dd>
            <dt x-show="details?.record.included_in_opening_entry_id">Included in opening</dt>
            <dd x-show="details?.record.included_in_opening_entry_id" x-text="'#' + details?.record.included_in_opening_entry_id"></dd>
        </dl>
        <div class="flex flex-wrap gap-2" x-show="!isHistoricalView && !retry && details?.record.status === 'posted' && !details?.record.reversal_of_id">
            @if ($canOpen)
                <button type="button" class="btn btn-sm" x-show="entry?.source === 'opening'" @click="start('reverse')">Reverse opening</button>
                @if ($canPay)
                    <button type="button" class="btn btn-sm" x-show="entry?.source === 'opening'" @click="start('evidence')">Record included evidence</button>
                @endif
                @if ($customer)
                    <button type="button" class="btn btn-sm" x-show="canApply && entry?.source === 'opening'" @click="start('apply')">Apply opening credit</button>
                @endif
            @endif
            @if ($canPay)
                <button type="button" class="btn btn-sm" x-show="canApply && entry?.source !== 'opening'" @click="start('apply')">Apply unallocated amount</button>
            @endif
            @can('payments.reverse')
                <button type="button" class="btn btn-sm" x-show="['customer_receipt','supplier_payment'].includes(details?.record.purpose)
                    && (!details?.record.historical_entry || @js($actor->hasRole('Owner')))" @click="start('reverse')">Reverse {{ $customer ? 'receipt' : 'payment' }}</button>
            @endcan
        </div>
        @if ($canOpen)
            <button type="button" class="btn btn-sm" x-show="!isHistoricalView && !retry && entry?.source === 'opening' && details?.record.status === 'reversed'"
                @click="start('opening', null, entry.id)">Replace reversed opening</button>
        @endif
        <section x-show="details?.allocations.length" aria-label="Allocation history" class="border-t border-base-300 pt-3">
            <h4 class="text-sm font-bold">Allocation history</h4>
            <ul class="divide-y divide-base-300">
                <template x-for="row in details?.allocations ?? []" :key="row.id">
                    <li class="py-2 text-sm">
                        <p class="font-semibold" x-text="'₹' + row.amount + ' · ' + (row.sale_id ? 'Sale #' + row.sale_id : row.purchase_id ? 'Purchase #' + row.purchase_id : 'Opening #' + (row.opening_due_id ?? row.party_opening_entry_id))"></p>
                        <p x-text="'Effective ' + date(row.effective_at)"></p>
                        <p x-text="'Recorded ' + date(row.created_at)"></p>
                        <p x-show="row.reversal_of_id" x-text="'Reversal of allocation #' + row.reversal_of_id"></p>
                        <button type="button" class="link link-primary" x-show="!isHistoricalView && !retry && reversible(row)
                            && (entry.source === 'opening' ? @js($canOpen) : @js($actor->can('payments.reverse')))"
                            @click="start('unapply', row)">Reverse allocation</button>
                    </li>
                </template>
            </ul>
        </section>
    </div>
</x-ui.dialog>

<x-ui.dialog id="party-posting" title="Ledger posting" x-ref="posting">
    <form class="mt-3 space-y-3" @submit.prevent="post()">
        <h3 class="font-semibold" x-text="heading"></h3>
        <p class="text-sm text-error" role="alert" x-text="formError"></p>
        <p class="text-sm" x-show="retry">Recover the result of the original submission.</p>
        <fieldset class="space-y-3" x-show="!retry && !preview" :disabled="busy || !!retry || !!preview">
            <label class="block text-sm font-semibold" x-show="operation === 'opening'">Opening type
                <select class="select mt-1 w-full" x-model="draft.entry_type">
                    <option value="due">Amount due</option><option value="credit">Credit</option><option value="advance">Advance</option>
                </select>
            </label>
            <label class="block text-sm font-semibold" x-show="operation === 'opening' || paymentOperation || operation === 'apply'">Amount (₹)
                <input class="input mt-1 w-full" x-model="draft.amount" inputmode="decimal"
                    :required="!retry && !preview && (operation === 'opening' || paymentOperation || operation === 'apply')">
            </label>
            <label class="block text-sm font-semibold" x-show="paymentOperation">Payment method
                <select class="select mt-1 w-full" x-model="draft.mode">
                    <option value="cash">Cash</option><option value="upi">UPI</option><option value="card">Card</option><option value="bank">Bank</option>
                </select>
            </label>
            @if ($actor->hasRole('Owner'))
                <label class="flex items-center gap-2 text-sm" x-show="operation === 'payment'">
                    <input type="checkbox" class="checkbox checkbox-sm" x-model="draft.historical"> Enter a historical date
                </label>
            @endif
            <p class="text-sm" x-show="operation === 'evidence'" x-text="'Already included in opening entry #' + draft.included + '. Posting this records evidence without changing the balance.'"></p>
            <label class="flex items-center gap-2 text-sm" x-show="operation === 'payment'">
                <input type="checkbox" class="checkbox checkbox-sm" x-model="draft.auto_allocate"> Apply to outstanding amounts automatically
            </label>
            <label class="block text-sm font-semibold" x-show="['opening','zero'].includes(operation) || (operation === 'reverse' && entry?.source === 'opening') || (paymentOperation && draft.historical) || (operation === 'apply' && @js($actor->hasRole('Owner')))">
                Effective date and time (IST)
                <input type="datetime-local" class="input mt-1 w-full" x-model="draft.effective_at"
                    :required="!retry && !preview && (['opening','zero'].includes(operation) || (operation === 'reverse' && entry?.source === 'opening') || (paymentOperation && draft.historical))">
            </label>
            <div class="space-y-2" x-show="operation === 'apply'">
                <label class="block text-sm font-semibold">Apply to
                    <select class="select mt-1 w-full" x-model="draft.target">
                        <option value="opening_entry_id">Opening due</option>
                        <option value="{{ $customer ? 'sale_id' : 'purchase_id' }}">{{ $customer ? 'Sale' : 'Purchase' }}</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold">Target entry number
                    <input type="number" class="input mt-1 w-full" min="1" step="1" x-model="draft.targetId" :required="!retry && operation === 'apply'">
                </label>
                <p class="text-sm text-base-content/75">Use the entry number shown in the ledger.</p>
            </div>
            <label class="block text-sm font-semibold" x-show="paymentOperation || operation === 'opening'">Reference
                <input class="input mt-1 w-full" x-model="draft.reference" maxlength="255">
            </label>
            <label class="block text-sm font-semibold" x-show="operation === 'opening'">Note
                <textarea class="textarea mt-1 w-full" x-model="draft.note" maxlength="2000" rows="2"></textarea>
            </label>
            <label class="block text-sm font-semibold" x-show="['opening','zero','reverse','unapply'].includes(operation) || (paymentOperation && draft.historical)">Reason
                <input class="input mt-1 w-full" x-model="draft.reason" maxlength="255"
                    :required="!retry && !preview && (['opening','zero','reverse','unapply'].includes(operation) || (paymentOperation && draft.historical))">
            </label>
        </fieldset>
        <div class="space-y-1 rounded-box border border-base-300 p-3 text-sm" x-show="preview && !retry">
            <p class="font-bold" x-text="preview?.balance_effect === 'included_in_opening' ? 'Already included in opening' : 'New balance effect'"></p>
            <p x-text="'Change in balance ₹' + (preview?.balance_delta ?? '')"></p>
            <p x-text="'Effective ' + date(preview?.paid_at)"></p>
            <p x-show="preview?.duplicate_reference" class="font-semibold">An existing payment uses this reference. Check it before posting.</p>
            <button type="button" class="link link-primary" @click="preview = null" :disabled="busy">Edit details</button>
        </div>
        <button class="btn btn-primary" :disabled="busy"
            x-text="busy ? 'Working…' : retry ? 'Recover posting' : paymentOperation && !preview ? 'Preview balance effect' : 'Confirm posting'"></button>
    </form>
</x-ui.dialog>
