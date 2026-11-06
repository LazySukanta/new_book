<div wire:key="reports-{{ $branch->id }}" wire:ignore
    x-data="workspaceReports(@js(url('/workspace/report-data')), @js((string) $branch->id), @js($actor->tenant_id.'.'.$actor->id), @js(now('Asia/Kolkata')->toDateString()), @js($actor->can('reports.view')))"
    class="min-w-0 space-y-4">
    <section class="rounded-box border border-base-300 bg-base-100 p-4 sm:p-5" aria-label="Report filters">
        <p class="mb-3 text-sm"><strong>Active branch</strong> {{ $branch->name }} · {{ $branch->code }}</p>
        <div class="flex flex-wrap items-end gap-3">
            @can('reports.view')
                <div class="w-full sm:w-auto">
                    <label for="report-kind" class="block text-sm font-semibold">Report</label>
                    <select id="report-kind" class="select mt-1 w-full" x-model="selected" @change="load()">
                        <template x-for="(option, key) in options" :key="key">
                            <option :value="key" x-text="option.title"></option>
                        </template>
                    </select>
                </div>
            @endcan
            <form x-show="!report.current" class="flex flex-wrap items-end gap-2" @submit.prevent="apply()">
                <div>
                    <label for="report-from" class="block text-sm font-semibold">From</label>
                    <input id="report-from" type="date" class="input mt-1 w-40" x-model="dates.from" required>
                </div>
                <div>
                    <label for="report-to" class="block text-sm font-semibold">Through</label>
                    <input id="report-to" type="date" class="input mt-1 w-40" x-model="dates.to" required>
                </div>
                <button class="btn btn-primary">Apply dates</button>
            </form>
        </div>
        <p class="mt-3 text-sm text-base-content/75" x-show="report.current">Current branch position. Date filters apply to activity reports.</p>
        @cannot('reports.view')
            <p class="mt-3 text-sm text-base-content/75">Sales and collections recorded by your account in this branch.</p>
        @endcannot
    </section>
    <section class="min-w-0 rounded-box border border-base-300 bg-base-100 p-4 sm:p-5" aria-labelledby="report-heading" :aria-busy="loading">
        <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="report-heading" class="text-xl font-bold" x-text="report.title"></h2>
            <p class="text-sm text-base-content/75" x-show="meta.from" x-text="meta.from + ' through ' + meta.to + ' · IST'"></p>
        </div>
        <p x-show="loading" role="status" class="text-sm">Loading report…</p>
        <div x-show="error" role="alert" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-error" x-text="error"></span>
            <button type="button" class="btn btn-sm" @click="load()">Retry</button>
        </div>
        @can('reports.view')
            <template x-if="data && selected === 'dashboard'">
                <div class="space-y-5">
                    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <template x-for="[name, value, reportKey] in [
                            ['Net sales', money(data.sales.total), 'daily-sales'], ['Bills', data.sales.bills, 'sales-by-cashier'],
                            ['Stock value', money(data.stock_value), 'stock-valuation'], ['Low-stock products', data.low_stock_count, 'low-stock'],
                            ['Customer dues', money(data.customer_dues.outstanding), 'customer-outstanding'],
                            ['Supplier dues', money(data.supplier_dues.outstanding), 'supplier-outstanding'],
                            ['Provisional gross profit', money(data.profit.gross_profit), 'profit'],
                        ]" :key="name">
                            <div class="min-w-0 rounded-box border border-base-300 bg-base-200 p-3">
                                <dt class="text-sm"><button type="button" class="link link-primary text-left" @click="selected = reportKey; load()" x-text="name"></button></dt>
                                <dd class="mt-1 break-words font-bold tabular-nums sm:text-lg" x-text="value"></dd>
                            </div>
                        </template>
                    </dl>
                    <p class="text-sm text-base-content/75" x-show="Number(data.customer_dues.incomplete_opening_accounts) || Number(data.supplier_dues.incomplete_opening_accounts)">
                        Opening records are incomplete for <span x-text="data.customer_dues.incomplete_opening_accounts"></span> customer accounts and
                        <span x-text="data.supplier_dues.incomplete_opening_accounts"></span> supplier accounts. Current dues may be incomplete.
                    </p>
                    <p class="text-sm text-base-content/75">Sales, collections and profit use the selected dates. Stock and dues show the current branch position. Dashboard figures may be up to one minute old.</p>
                    <div class="grid gap-4 md:grid-cols-2">
                        <section class="min-w-0 border-t border-base-300 pt-3">
                            <h3 class="font-bold">Net collections by method</h3>
                            <p class="mt-2 text-sm text-base-content/75" x-show="!data.collections.length">No collections for this period.</p>
                            <dl class="mt-2 divide-y divide-base-300">
                                <template x-for="row in data.collections" :key="row.mode">
                                    <div class="flex justify-between gap-3 py-2 text-sm"><dt x-text="row.mode.toUpperCase()"></dt><dd class="tabular-nums" x-text="money(row.amount)"></dd></div>
                                </template>
                            </dl>
                        </section>
                        <section class="min-w-0 border-t border-base-300 pt-3">
                            <h3 class="font-bold">Top products by net sales</h3>
                            <p class="mt-2 text-sm text-base-content/75" x-show="!data.top_products.length">No product sales for this period.</p>
                            <dl class="mt-2 divide-y divide-base-300">
                                <template x-for="row in data.top_products" :key="row.product_id">
                                    <div class="flex justify-between gap-3 py-2 text-sm"><dt class="min-w-0 break-words" x-text="row.name ?? 'Product #' + row.product_id"></dt><dd class="tabular-nums" x-text="money(row.total)"></dd></div>
                                </template>
                            </dl>
                        </section>
                    </div>
                </div>
            </template>
            <template x-if="data && selected === 'profit'">
                <div>
                    <p class="mb-3 text-sm text-base-content/75">Gross profit is provisional. Estimated cost and stock valuation variance are shown separately.</p>
                    <dl class="max-w-lg divide-y divide-base-300">
                        <template x-for="[key, title] in [['taxable','Taxable sales'], ['cost','Cost of goods sold'], ['gross_profit','Provisional gross profit'], ['estimated_cogs','Estimated cost included'], ['valuation_variance','Stock valuation variance']]" :key="key">
                            <div class="flex justify-between gap-3 py-2 text-sm"><dt x-text="title"></dt><dd class="font-semibold tabular-nums" x-text="money(data[key])"></dd></div>
                        </template>
                    </dl>
                </div>
            </template>
        @endcan
        <template x-if="Array.isArray(data)">
            <div class="min-w-0 space-y-3">
                <p x-show="!data.length" class="text-sm text-base-content/75">No entries match this report.</p>
                <p x-show="selected.endsWith('-outstanding') && data.some(row => Number(row.opening_balance_incomplete))" class="text-sm text-base-content/75">Balances marked Not entered may be incomplete.</p>
                <p x-show="selected === 'sales-by-product' && data.length" class="text-sm text-base-content/75">Gross profit is provisional. Estimated cost is shown separately.</p>
                <div x-show="data.length" class="overflow-x-auto" tabindex="0" role="region" aria-label="Report results">
                    <table class="table table-sm max-sm:block" role="table">
                        <thead class="max-sm:sr-only"><tr>
                            <template x-for="column in report.columns" :key="column.key">
                                <th scope="col" :class="{ 'text-right': column.money }" x-text="column.label"></th>
                            </template>
                        </tr></thead>
                        <tbody class="max-sm:block" role="rowgroup">
                            <template x-for="(row, index) in data" :key="index">
                                <tr class="max-sm:grid max-sm:grid-cols-2 max-sm:border-b max-sm:border-base-300 max-sm:py-2" role="row">
                                    <template x-for="(column, position) in report.columns" :key="column.key">
                                        <td role="cell" class="min-w-0 break-words tabular-nums" :class="{ 'sm:text-right': column.money, 'max-sm:col-span-2 font-semibold': position === 0 }">
                                            <span class="block text-xs font-normal text-base-content/75 sm:hidden" aria-hidden="true" x-text="column.label"></span>
                                            <span x-text="format(row, column)"></span>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <nav x-show="meta.last_page > 1" class="flex items-center justify-between gap-2" aria-label="Report pages">
                    <button type="button" class="btn btn-sm" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)">Previous</button>
                    <span class="text-xs" x-text="meta.current_page + ' / ' + meta.last_page"></span>
                    <button type="button" class="btn btn-sm" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)">Next</button>
                </nav>
            </div>
        </template>
    </section>
</div>
