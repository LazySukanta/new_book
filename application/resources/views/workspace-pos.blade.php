<div wire:key="pos-{{ $branch->id }}" wire:ignore
    x-data="workspacePos(@js(route('workspace.pos')), @js((string) $branch->id), @js($actor->tenant_id.'.'.$actor->id), @js(csrf_token()))"
    @keydown.window="shortcut($event)" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]">
    <div class="xl:col-span-2">
        <p class="text-sm text-error" role="alert" x-text="error"></p>
        <p class="text-sm text-success" role="status" x-text="message"></p>
        <a x-show="completed" x-ref="invoiceLink" :href="completed && invoicesUrl + '/' + completed.id"
            class="link link-primary text-sm font-semibold" x-text="completed && 'Open invoice ' + completed.number"></a>
        <a :href="invoicesUrl" class="link ml-2 text-sm">Invoice history</a>
    </div>
    <section class="min-w-0 rounded-box border border-base-300 bg-base-100 p-4 sm:p-5" aria-labelledby="pos-products">
        <h2 id="pos-products" class="mb-3 text-lg font-bold">Products</h2>
        <form @submit.prevent="scan()">
            <label class="block text-sm font-semibold" for="pos-scan">Search or scan <kbd class="kbd kbd-sm">F2</kbd></label>
            <input id="pos-scan" x-ref="scan" x-model="term" @input.debounce.180ms="searchProducts()"
                class="input mt-1 w-full" autocomplete="off" maxlength="100" placeholder="Product name, SKU or barcode">
        </form>
        <ul class="mt-3 divide-y divide-base-300" aria-label="Matching products">
            <template x-for="product in results" :key="product.id">
                <li class="flex items-center justify-between gap-3 py-2">
                    <span class="min-w-0 break-words"><strong x-text="product.name"></strong>
                        <small class="block text-base-content/75" x-text="product.sku"></small></span>
                    <button type="button" class="btn btn-sm" :disabled="locked" @click="add(product)" :aria-label="'Add ' + product.name">Add</button>
                </li>
            </template>
        </ul>
        <p x-show="!results.length" class="mt-3 text-sm text-base-content/75">Press Enter after scanning a barcode or typing an exact SKU.</p>
    </section>
    <section class="min-w-0 rounded-box border border-base-300 bg-base-100 p-4 sm:p-5" aria-labelledby="pos-cart">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="pos-cart" class="text-lg font-bold" x-text="held ? 'Current bill #' + held : 'Current bill'"></h2>
            <button type="button" class="btn btn-sm" :disabled="locked" @click="openHeld()">Held bills</button>
        </div>
        <fieldset :disabled="locked" class="mt-3 min-w-0">
            <div class="flex flex-wrap items-end gap-3">
                <button type="button" class="btn btn-outline" @click="openCustomer()" aria-haspopup="dialog">
                    <span x-text="customerName"></span><kbd class="kbd kbd-sm">F4</kbd>
                </button>
                <div>
                    <label for="pos-pricing" class="block text-sm font-semibold">Pricing</label>
                    <select id="pos-pricing" class="select block" x-model="cart.sale_type" @change="refresh()">
                        <option value="retail">Retail</option><option value="wholesale">Wholesale</option>
                    </select>
                </div>
            </div>
            <p x-show="!cart.items.length" class="my-4 text-sm text-base-content/75">Add a product to start the bill.</p>
            <ul class="mt-4 divide-y divide-base-300" aria-label="Bill items">
                <template x-for="(item, index) in cart.items" :key="item.product_id">
                    <li class="py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 break-words">
                                <strong x-text="products[item.product_id]?.name ?? 'Product #' + item.product_id"></strong>
                                <p class="text-sm text-base-content/75" x-show="preview?.items[index]"
                                    x-text="preview?.items[index] ? '₹' + preview.items[index].unit_price + ' per unit · ' + preview.items[index].price_source.replaceAll('_', ' ') : ''"></p>
                                <p class="text-sm text-warning-content bg-warning/30" x-show="products[item.product_id]?.stock_qty == null || Number(products[item.product_id]?.stock_qty) < Number(item.qty)"
                                    x-text="products[item.product_id]?.stock_qty == null ? 'Stock unknown' : 'Insufficient stock · ' + products[item.product_id].stock_qty + ' available'"></p>
                            </div>
                            <span class="shrink-0 font-semibold tabular-nums" x-text="preview?.items[index] ? '₹' + preview.items[index].line_total : '—'"></span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-end gap-2">
                            <label class="text-xs font-semibold">Quantity
                                <input class="input input-sm block w-24" inputmode="decimal" :value="item.qty" @input.debounce.160ms="refresh()" @input="item.qty = $event.target.value; revision++; preview = null; persist()" @keydown.enter.prevent="$refs.scan.focus()">
                            </label>
                            @can('sales.override_price')
                                <label class="text-xs font-semibold">Unit price override
                                    <input class="input input-sm block w-28" inputmode="decimal" placeholder="Automatic"
                                        :value="item.unit_price ?? ''" @input="item.unit_price = $event.target.value || null; revision++; preview = null; persist()" @input.debounce.160ms="refresh()">
                                </label>
                            @endcan
                            @can('sales.discount')
                                <label class="text-xs font-semibold">Line discount (₹ or %)
                                    <input class="input input-sm block w-28" placeholder="0.00" :value="discountInput(item.discount)"
                                        @input="item.discount = discountIntent($event.target.value); revision++; preview = null; persist()" @input.debounce.160ms="refresh()">
                                </label>
                            @endcan
                            <button type="button" class="btn btn-ghost btn-sm" :aria-label="'Remove ' + (products[item.product_id]?.name ?? 'product')" @click="cart.items.splice(index, 1); refresh()">Remove</button>
                        </div>
                    </li>
                </template>
            </ul>
            @can('sales.discount')
                <label class="mt-2 block text-sm font-semibold">Bill discount (₹ or %)
                    <input class="input mt-1 block w-32" placeholder="0.00" :value="discountInput(cart.bill_discount)"
                        @input="cart.bill_discount = discountIntent($event.target.value); revision++; preview = null; persist()" @input.debounce.160ms="refresh()">
                </label>
            @endcan
            <label class="mt-3 block text-sm font-semibold">Bill comment
                <textarea class="textarea mt-1 w-full" x-model="cart.note" @input="$nextTick(() => persist())" maxlength="1000" rows="2"></textarea>
            </label>
        </fieldset>
        <p class="mt-2 text-sm text-error" role="alert" x-text="pricingError"></p>
        <p x-show="pending" class="mt-2 text-sm text-base-content/75" role="status">Updating totals…</p>
        <dl x-show="preview" class="mt-3 space-y-1 border-t border-base-300 pt-3 text-sm tabular-nums" aria-label="Bill totals">
            <template x-for="[key, label] in [['subtotal', 'Subtotal'], ['line_discount_total', 'Line discounts'], ['bill_discount', 'Bill discount'], ['cgst', 'CGST'], ['sgst', 'SGST'], ['igst', 'IGST'], ['total', 'Total']]" :key="key">
                <div class="flex justify-between gap-2" :class="{ 'mt-2 border-t border-base-300 pt-2 text-lg font-bold': key === 'total' }">
                    <dt x-text="label"></dt><dd x-text="preview ? '₹' + preview.totals[key] : '—'"></dd>
                </div>
            </template>
        </dl>
        <section x-show="needsAcknowledgement" class="mt-3 rounded-box border border-warning p-3" aria-labelledby="pos-reprice">
            <h3 id="pos-reprice" class="font-bold">Pricing differs from the held bill</h3>
            <p class="text-sm" x-text="'Held total ₹' + (savedPricing?.totals.total ?? '—') + ' · Current total ₹' + (preview?.totals.total ?? '—')"></p>
            <ul class="my-2 text-sm">
                <template x-for="line in preview?.items ?? []" :key="line.product_id">
                    <li x-text="pricingChange(line)"></li>
                </template>
            </ul>
            <button type="button" class="btn btn-sm" @click="acknowledge()">Acknowledge current pricing</button>
        </section>
        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" :disabled="busy || queuedScans || retry?.checkout || (!retry && (!preview || needsAcknowledgement))" @click="hold()"><span x-text="retry && !retry.checkout && !busy ? 'Retry hold' : 'Hold bill'"></span> <kbd class="kbd kbd-sm">F8</kbd></button>
            <button type="button" class="btn btn-neutral" :disabled="busy || queuedScans || (retry ? !retry.checkout : !preview || needsAcknowledgement)" @click="openCheckout()">
                <span x-text="retry?.checkout ? 'Recover payment' : 'Checkout'"></span><kbd class="kbd kbd-sm">F9</kbd>
            </button>
            <button type="button" class="btn btn-ghost" :disabled="locked || queuedScans" @click="if (confirm(held ? 'Close this draft? The saved held bill will remain available.' : 'Clear this draft?')) clear()">Clear draft</button>
        </div>
    </section>
    <x-ui.dialog id="pos-checkout" title="Checkout" x-ref="checkout">
        <form @submit.prevent="checkout()" class="mt-3 space-y-3">
            <p class="text-sm text-error" role="alert" x-text="checkoutError"></p>
            <p x-show="retry?.checkout" class="text-sm">Recover the result of the original payment submission.</p>
            <fieldset :disabled="busy || !!retry" x-show="!retry" class="grid grid-cols-2 gap-3">
                <template x-for="mode in paymentModes" :key="mode">
                    <label class="block text-sm font-semibold"><span x-text="{cash:'Cash tendered',upi:'UPI',card:'Card',bank:'Bank'}[mode]"></span>
                        <input class="input mt-1 w-full" inputmode="decimal" placeholder="0.00" x-model="amounts[mode]">
                    </label>
                </template>
                @can('sales.override_credit')
                    <label x-show="Number(payment.due) > 0 && cart.customer_id" class="col-span-2 block text-sm font-semibold">
                        Credit limit override reason (if needed)
                        <input class="input mt-1 w-full" maxlength="255" x-model="creditOverride">
                    </label>
                @endcan
            </fieldset>
            <dl x-show="!retry" class="space-y-1 border-t border-base-300 pt-2 text-sm tabular-nums">
                <div class="flex justify-between"><dt>Total</dt><dd x-text="preview && '₹' + preview.totals.total"></dd></div>
                <div class="flex justify-between"><dt>Change</dt><dd x-text="payment.valid ? '₹' + payment.change : '—'"></dd></div>
                <div class="flex justify-between"><dt>Balance on credit</dt><dd x-text="payment.valid ? '₹' + payment.due : '—'"></dd></div>
            </dl>
            <p x-show="!retry && !payment.valid" class="text-sm text-base-content/75">Enter valid payment amounts. Walk-in bills must be paid in full.</p>
            <button class="btn btn-primary" :disabled="busy || (!retry && !payment.valid)" x-text="busy ? 'Submitting…' : retry ? 'Recover payment' : 'Complete sale'"></button>
        </form>
    </x-ui.dialog>
    <x-ui.dialog id="pos-customer" title="Choose customer" x-ref="customer">
        <p class="my-2 text-sm text-error" role="alert" x-text="error"></p>
        <label class="block text-sm font-semibold" for="pos-customer-search">Search by name or phone</label>
        <input id="pos-customer-search" autofocus class="input mt-1 w-full" x-model="customerTerm" @input.debounce.180ms="searchCustomers()">
        <ul class="my-2 max-h-48 overflow-auto">
            <li><button type="button" class="btn btn-ghost w-full justify-start" :disabled="busy" @click="chooseCustomer(null)">Walk-in</button></li>
            <template x-for="customer in customers" :key="customer.id">
                <li><button type="button" class="btn btn-ghost w-full justify-start" :disabled="busy" @click="chooseCustomer(customer)" x-text="customer.name"></button></li>
            </template>
        </ul>
        @can('customers.create')
            <form @submit.prevent="createCustomer()" class="space-y-2 border-t border-base-300 pt-3">
                <h3 class="font-bold">Quick add customer</h3>
                <label class="block text-sm font-semibold">Name <input class="input mt-1 w-full" x-model="newCustomer.name" required maxlength="255"></label>
                <label class="block text-sm font-semibold">Phone <input type="tel" class="input mt-1 w-full" x-model="newCustomer.phone" maxlength="32"></label>
                <button class="btn btn-primary" :disabled="busy">Add and use customer</button>
            </form>
        @endcan
    </x-ui.dialog>
    <x-ui.dialog id="pos-held" title="Held bills" x-ref="heldList">
        <p class="my-2 text-sm text-error" role="alert" x-text="error"></p>
        <p x-show="!bills.length" class="mt-3 text-sm">No held bills in this branch.</p>
        <ul class="mt-3 max-h-[60vh] divide-y divide-base-300 overflow-auto">
            <template x-for="bill in bills" :key="bill.id">
                <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div><strong x-text="'Bill #' + bill.id"></strong>
                        <p class="text-sm" x-text="bill.cart.items.length + ' items · ₹' + bill.pricing.totals.total"></p></div>
                    <div class="flex gap-1">
                        <button type="button" class="btn btn-sm" :disabled="busy" @click="resume(bill)">Resume</button>
                        <button type="button" class="btn btn-ghost btn-sm text-error" :disabled="busy" @click="discard(bill)">Discard</button>
                    </div>
                </li>
            </template>
        </ul>
    </x-ui.dialog>
</div>
