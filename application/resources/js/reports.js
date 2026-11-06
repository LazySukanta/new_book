const text = (key, label) => ({ key, label });
const amount = (key, label) => ({ key, label, money: true });
const reports = {
    dashboard: { title: 'Branch dashboard' },
    'daily-sales': { title: 'Daily sales', columns: [
        text('date', 'Date'), text('bills', 'Bills'), amount('gross', 'Gross'), amount('discount', 'Discount'),
        amount('taxable', 'Taxable'), amount('tax', 'GST'), amount('round_off', 'Round-off'), amount('net', 'Net sales'),
        amount('credit', 'Credit'), text('collected_by_mode', 'Collections by mode'),
    ] },
    'sales-by-product': { title: 'Sales by product', columns: [
        text('name', 'Product'), text('qty', 'Net quantity'), amount('taxable', 'Taxable'), amount('tax', 'GST'),
        amount('total', 'Net sales'), amount('cost', 'Cost'), amount('gross_profit', 'Provisional gross profit'), amount('estimated_cogs', 'Estimated cost'),
    ] },
    'sales-by-cashier': { title: 'Sales by cashier', columns: [text('name', 'Cashier'), text('bills', 'Bills'), amount('total', 'Net sales')] },
    'stock-valuation': { title: 'Stock valuation', current: true, columns: [
        text('name', 'Product'), text('sku', 'SKU'), text('qty', 'Quantity'), amount('value', 'Stock value'), text('valuation_provisional', 'Valuation'),
    ] },
    'low-stock': { title: 'Low stock', current: true, columns: [
        text('name', 'Product'), text('sku', 'SKU'), text('qty', 'Quantity'), text('reorder_level', 'Reorder level'), text('valuation_provisional', 'Valuation'),
    ] },
    'customer-outstanding': { title: 'Customer outstanding', current: true, columns: [
        text('name', 'Customer'), text('phone', 'Phone'), amount('balance', 'Balance'), text('opening_status', 'Opening status'), text('last_payment', 'Last receipt'),
    ] },
    'supplier-outstanding': { title: 'Supplier outstanding', current: true, columns: [
        text('name', 'Supplier'), text('phone', 'Phone'), amount('balance', 'Balance'), text('opening_status', 'Opening status'), text('last_payment', 'Last payment'),
    ] },
    payments: { title: 'Payments', columns: [
        text('paid_at', 'Effective time'), text('party_name', 'Party'), text('direction', 'Direction'), text('mode', 'Method'),
        amount('amount', 'Amount'), text('purpose', 'Purpose'), text('balance_effect', 'Balance effect'), text('status', 'Status'),
    ] },
    profit: { title: 'Provisional gross profit' },
};

const money = value => value == null ? '—' : '₹' + String(value);
const label = value => value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
const ist = new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' });

window.workspaceReports = (base, branch, scope, today, full) => ({
    options: full ? reports : { 'daily-sales': { ...reports['daily-sales'], title: 'My daily sales' } },
    selected: full ? 'dashboard' : 'daily-sales',
    dates: { from: today, to: today },
    applied: { from: today, to: today },
    data: null,
    meta: {},
    loading: false,
    error: '',
    revision: 0,
    get report() { return this.options[this.selected]; },
    get storageKey() { return 'khata.reports.' + scope; },
    init() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(this.storageKey));
            if (saved && Object.hasOwn(this.options, saved.selected)) this.selected = saved.selected;
            if (saved?.dates?.from && saved?.dates?.to) {
                this.applied = saved.dates;
                this.dates = { ...saved.dates };
            }
        } catch { /* A new tab can use today's period. */ }
        this.load();
    },
    apply() {
        this.applied = { ...this.dates };
        this.load();
    },
    async load(page = 1) {
        const revision = ++this.revision;
        this.data = null;
        this.meta = {};
        this.error = '';
        this.loading = true;
        const query = new URLSearchParams({ page, per_page: 15 });
        if (!this.report.current) {
            query.set('from', this.applied.from);
            query.set('to', this.applied.to);
        }
        try {
            const response = await fetch(base + '/' + this.selected + '?' + query, {
                headers: { Accept: 'application/json', 'X-Branch-Id': branch },
                signal: AbortSignal.timeout(20000),
            });
            const result = await response.json();
            if (revision !== this.revision) return;
            if (!response.ok) throw new Error(Object.values(result.errors ?? {}).flat()[0] || result.message || 'The report could not load.');
            this.data = result.data;
            this.meta = result.meta;
            try {
                sessionStorage.setItem(this.storageKey, JSON.stringify({ selected: this.selected, dates: this.applied }));
            } catch { /* Report filters still work in memory. */ }
        } catch (error) {
            if (revision === this.revision) this.error = error.message;
        } finally {
            if (revision === this.revision) this.loading = false;
        }
    },
    money,
    label,
    format(row, column) {
        const value = row[column.key];
        if (value == null) return '—';
        if (column.money) return money(value);
        switch (column.key) {
            case 'valuation_provisional': return Number(value) ? 'Provisional' : 'Recorded';
            case 'opening_status': return { not_entered: 'Not entered', confirmed_zero: 'Confirmed zero', entered: 'Entered' }[value];
            case 'paid_at':
            case 'last_payment': return ist.format(new Date(value.replace(' ', 'T') + (value.includes('T') ? '' : 'Z')));
            case 'collected_by_mode': return Object.entries(value).map(([mode, amount]) => mode.toUpperCase() + ' ' + money(amount)).join(' · ') || '—';
            case 'mode': return value.toUpperCase();
            case 'purpose':
            case 'status': return label(value);
            case 'balance_effect': return value === 'included_in_opening' ? 'Already included in opening' : 'Normal';
            case 'direction': return value === 'in' ? 'Received' : 'Paid';
            default: return String(value);
        }
    },
});
