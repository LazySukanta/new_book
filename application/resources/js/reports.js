const text = (key, label) => ({ key, label });
const amount = (key, label) => ({ key, label, money: true });
const reports = {
    'gst-sales': { title: 'GST sales and credit notes', historical: true, columns: [text('document_no', 'Document'), text('issued_at', 'Issued'), text('document_type', 'Type'), text('section', 'Section'), text('party_name', 'Customer'), text('party_gstin', 'GSTIN'), text('place_of_supply', 'Place of supply'), text('hsn', 'HSN'), text('rate_bp', 'Rate (basis points)'), amount('net_taxable', 'Net taxable'), amount('net_cgst', 'Net CGST'), amount('net_sgst', 'Net SGST'), amount('net_igst', 'Net IGST'), amount('net_total', 'Net line total'), text('status_at_cutoff', 'Status at cutoff')] },
    'gst-purchases': { title: 'GST purchases at cutoff', historical: true, columns: [text('supplier_invoice_no', 'Supplier invoice'), text('supplier_invoice_date', 'Invoice date'), text('party_name', 'Supplier'), text('party_gstin', 'GSTIN'), text('revision_no', 'Revision'), amount('taxable', 'Taxable'), amount('cgst', 'CGST'), amount('sgst', 'SGST'), amount('igst', 'IGST'), amount('total', 'Total')] },
    'documents-issued': { title: 'Documents issued by series', historical: true, columns: [text('document_type', 'Document type'), text('financial_year', 'Financial year'), text('registration_gstin', 'GSTIN'), text('prefix', 'Series'), text('owning_channel', 'Channel'), text('first_number', 'First issued'), text('last_number', 'Last issued'), text('total', 'Issued'), text('cancelled', 'Cancelled'), text('net', 'Net issued'), text('gap_exceptions', 'Missing serial ranges')] },
    'customer-as-of': { title: 'Customer balances at cutoff', historical: true, current: true, columns: [text('name', 'Customer'), amount('balance', 'Balance'), text('opening_status', 'Opening status')] },
    'supplier-as-of': { title: 'Supplier balances at cutoff', historical: true, current: true, columns: [text('name', 'Supplier'), amount('balance', 'Balance'), text('opening_status', 'Opening status')] },
    'stock-as-of': { title: 'Stock at posting cutoff', historical: true, current: true, columns: [text('name', 'Product'), text('sku', 'SKU'), text('qty', 'Quantity'), amount('value', 'Stock value'), amount('valuation_variance', 'Valuation variance')] },
    transfers: { title: 'Transfers from this branch', current: true, columns: [text('transfer_no', 'Transfer'), text('destination', 'Destination'), text('name', 'Product'), text('qty_dispatched', 'Dispatched'), text('qty_received', 'Received'), text('qty_recovered', 'Recovered'), text('qty_written_off', 'Written off'), amount('written_off_value', 'Loss'), text('status', 'Status')] },
    'in-transit': { title: 'Stock in transit from this branch', current: true, columns: [text('transfer_no', 'Transfer'), text('destination', 'Destination'), text('name', 'Product'), text('unresolved_qty', 'In transit'), amount('in_transit_value', 'In-transit value')] },
    'transfer-discrepancies': { title: 'Transfer shortages from this branch', current: true, columns: [text('transfer_no', 'Transfer'), text('destination', 'Destination'), text('name', 'Product'), text('unresolved_qty', 'Unresolved shortage'), amount('in_transit_value', 'Unresolved value')] },
    'transfer-resolutions': { title: 'Transfer recoveries and losses from this branch', columns: [text('transfer_no', 'Transfer'), text('name', 'Product'), text('resolution', 'Outcome'), text('qty', 'Quantity'), amount('value', 'Carried value'), text('reason', 'Reason'), text('resolved_at', 'Recorded')] },
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

window.workspaceReports = (base, branch, scope, today, full, exportBase, owner) => ({
    options: full ? reports : { 'daily-sales': { ...reports['daily-sales'], title: 'My daily sales' } },
    selected: full ? 'dashboard' : 'daily-sales',
    dates: { from: today, to: today },
    applied: { from: today, to: today },
    cutoffs: { as_of: '', recorded_as_of: '' },
    appliedCutoffs: { as_of: '', recorded_as_of: '' },
    data: null,
    meta: {},
    loading: false,
    error: '',
    exporting: false,
    exportError: '',
    revision: 0,
    get report() { return this.options[this.selected]; },
    get canUseCutoffs() { return this.selected !== 'gst-purchases' || owner; },
    get storageKey() { return 'khata.reports.' + scope; },
    init() {
        try {
            const saved = JSON.parse(sessionStorage.getItem(this.storageKey));
            if (saved && Object.hasOwn(this.options, saved.selected)) this.selected = saved.selected;
            if (saved?.dates?.from && saved?.dates?.to) {
                this.applied = saved.dates;
                this.dates = { ...saved.dates };
            }
            if (saved?.cutoffs) {
                this.cutoffs = { as_of: saved.cutoffs.as_of ?? '', recorded_as_of: saved.cutoffs.recorded_as_of ?? '' };
                this.appliedCutoffs = { ...this.cutoffs };
            }
        } catch { /* A new tab can use today's period. */ }
        this.load();
    },
    apply() {
        this.applied = { ...this.dates };
        this.appliedCutoffs = { ...this.cutoffs };
        this.load();
    },
    parameters(page = 1) {
        const query = new URLSearchParams({ page, per_page: 15 });
        if (!this.report.current) {
            query.set('from', this.applied.from);
            query.set('to', this.applied.to);
        }
        if (this.report.historical && this.canUseCutoffs) {
            for (const [field, value] of Object.entries(this.appliedCutoffs)) {
                if (value) query.set(field, value);
            }
        }
        return query;
    },
    async download() {
        if (this.exporting) return;
        this.exporting = true;
        this.exportError = '';
        const selected = this.selected;
        try {
            const response = await fetch(exportBase + '/' + selected + '?' + this.parameters(), {
                headers: { Accept: 'application/json', 'X-Branch-Id': branch }, signal: AbortSignal.timeout(120000),
            });
            if (!response.ok) {
                const result = await response.json();
                throw new Error(Object.values(result.errors ?? {}).flat()[0] || result.message || 'The Excel file could not be created.');
            }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)/i)?.[1] || selected + '.xlsx';
            document.body.append(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
            this.exportError = error.message;
        } finally {
            this.exporting = false;
        }
    },
    async load(page = 1) {
        const revision = ++this.revision;
        this.data = null;
        this.meta = {};
        this.error = '';
        this.exportError = '';
        this.loading = true;
        const query = this.parameters(page);
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
                sessionStorage.setItem(this.storageKey, JSON.stringify({ selected: this.selected, dates: this.applied, cutoffs: this.appliedCutoffs }));
            } catch { /* Report filters still work in memory. */ }
        } catch (error) {
            if (revision === this.revision) this.error = error.message;
        } finally {
            if (revision === this.revision) this.loading = false;
        }
    },
    money,
    label,
    cutoffLabel(value) { return value ? ist.format(new Date(value)) : 'Now'; },
    format(row, column) {
        const value = row[column.key];
        if (value == null) return '—';
        if (column.money) return money(value);
        switch (column.key) {
            case 'valuation_provisional': return Number(value) ? 'Provisional' : 'Recorded';
            case 'opening_status': return { not_entered: 'Not entered', confirmed_zero: 'Confirmed zero', entered: 'Entered' }[value];
            case 'paid_at':
            case 'resolved_at':
            case 'issued_at':
            case 'last_payment': return ist.format(new Date(value.replace(' ', 'T') + (value.includes('T') ? '' : 'Z')));
            case 'collected_by_mode': return Object.entries(value).map(([mode, amount]) => mode.toUpperCase() + ' ' + money(amount)).join(' · ') || '—';
            case 'mode': return value.toUpperCase();
            case 'purpose':
            case 'resolution':
            case 'document_type':
            case 'status_at_cutoff':
            case 'status': return label(value);
            case 'balance_effect': return value === 'included_in_opening' ? 'Already included in opening' : 'Normal';
            case 'direction': return value === 'in' ? 'Received' : 'Paid';
            case 'gap_exceptions': return value.length ? value.map(range => range.first_missing_serial === range.last_missing_serial ? range.first_missing_serial : range.first_missing_serial + '–' + range.last_missing_serial).join(', ') : 'None';
            default: return String(value);
        }
    },
});
