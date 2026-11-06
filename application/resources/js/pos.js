const blank = () => ({ items: [], customer_id: null, sale_type: 'retail', note: '', bill_discount: null });
const paymentModes = ['cash', 'upi', 'card', 'bank'];
const paise = value => {
    if (!/^\d{1,16}(\.\d{1,2})?$/.test(value)) return null;
    const [rupees, fraction = ''] = value.split('.');
    return BigInt(rupees + fraction.padEnd(2, '0'));
};
const money = value => String(value / 100n) + '.' + String(value % 100n).padStart(2, '0');

window.workspacePos = (base, branch, scope, csrf) => ({
    cart: blank(),
    products: {},
    preview: null,
    held: null,
    savedPricing: null,
    acknowledged: null,
    customerName: 'Walk-in',
    term: '',
    results: [],
    customerTerm: '',
    customers: [],
    bills: [],
    newCustomer: { name: '', phone: '' },
    revision: 0,
    pending: false,
    busy: false,
    error: '',
    pricingError: '',
    message: '',
    retry: null,
    paymentModes,
    amounts: { cash: '', upi: '', card: '', bank: '' },
    creditOverride: '',
    checkoutError: '',
    completed: null,
    scans: Promise.resolve(),
    queuedScans: 0,
    get key() { return 'khata.pos.' + scope + '.' + branch; },
    get locked() { return this.busy || !!this.retry; },
    get invoicesUrl() { return base + '/invoices/' + branch; },
    get payment() {
        const amounts = Object.fromEntries(paymentModes.map(mode => [mode, paise(this.amounts[mode].trim() || '0')]));
        const total = paise(this.preview?.totals.total ?? '');
        if (total === null || Object.values(amounts).includes(null)) return { valid: false };
        const remaining = total - amounts.upi - amounts.card - amounts.bank;
        if (remaining < 0n) return { valid: false };
        const change = amounts.cash > remaining ? amounts.cash - remaining : 0n;
        const due = amounts.cash < remaining ? remaining - amounts.cash : 0n;
        amounts.cash -= change;
        return { valid: !!this.cart.customer_id || due === 0n, change: money(change), due: money(due), payments: paymentModes.filter(mode => amounts[mode] > 0n)
            .map(mode => ({ mode, amount: money(amounts[mode]) })) };
    },
    get hasDraft() {
        return this.cart.items.length || this.held || this.cart.customer_id || this.cart.note
            || this.cart.bill_discount || this.cart.sale_type !== 'retail';
    },
    get needsAcknowledgement() {
        const current = this.preview?.pricing_fingerprint;
        return this.savedPricing && current && current !== this.savedPricing.pricing_fingerprint && current !== this.acknowledged;
    },
    init() {
        try {
            const draft = JSON.parse(sessionStorage.getItem(this.key));
            if (draft) Object.assign(this, draft);
        } catch {
            this.error = 'This tab could not restore its previous bill.';
        }
        this.refresh();
        this.$nextTick(() => this.$refs.scan.focus());
    },
    persist() {
        try {
            const { cart, products, held, savedPricing, acknowledged, customerName, retry } = this;
            if (this.hasDraft) {
                sessionStorage.setItem(this.key, JSON.stringify({ cart, products, held, savedPricing, acknowledged, customerName, retry }));
            } else {
                sessionStorage.removeItem(this.key);
            }
        } catch {
            this.error = 'This tab cannot save drafts. Hold the bill before leaving this page.';
        }
    },
    async request(path, method = 'GET', data, key) {
        const response = await fetch(base + path, {
            method,
            signal: AbortSignal.timeout(20000),
            headers: {
                Accept: 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf, 'X-Branch-Id': branch,
                ...(key ? { 'Idempotency-Key': key } : {}),
            },
            body: data === undefined ? undefined : JSON.stringify(data),
        });
        const result = await response.json();
        if (!response.ok) {
            const error = new Error(Object.values(result.errors ?? {}).flat()[0] || result.message || 'The request failed.');
            error.status = response.status;
            error.code = result.code;
            throw error;
        }
        return result.data;
    },
    async refresh() {
        const revision = ++this.revision;
        this.persist();
        this.preview = null;
        this.pricingError = '';
        this.pending = !!this.cart.items.length;
        if (!this.pending) return;
        try {
            const preview = await this.request('/calculate', 'POST', this.cart);
            if (revision !== this.revision) return;
            this.preview = preview;
            for (const [id, name] of Object.entries(preview.names)) {
                this.products[id] = { ...this.products[id], name, stock_qty: preview.stock[id] ?? '0.000' };
            }
            this.persist();
        } catch (error) {
            if (revision === this.revision) this.pricingError = error.message;
        } finally {
            if (revision === this.revision) this.pending = false;
        }
    },
    async searchProducts() {
        const term = this.term.trim();
        this.results = [];
        if (!term) return;
        try {
            const results = await this.request('/products?active=1&per_page=12&search=' + encodeURIComponent(term));
            if (this.term.trim() === term) this.results = results;
        } catch (error) {
            if (this.term.trim() === term) this.error = error.message;
        }
    },
    scan() {
        const code = this.term.trim();
        if (!code || this.locked) return;
        const matches = this.results;
        this.term = '';
        this.results = [];
        this.queuedScans++;
        this.scans = this.scans.then(async () => {
            try {
                this.add(await this.request('/lookup?code=' + encodeURIComponent(code)));
            } catch (error) {
                if (error.status === 404 && matches.length === 1) {
                    this.add(matches[0]);
                } else {
                    this.error = error.status === 404 ? 'No exact barcode or SKU matched. Search by product name and choose a result.' : error.message;
                }
            } finally {
                this.queuedScans--;
            }
        });
    },
    add(product) {
        if (this.locked) return;
        const line = this.cart.items.find(item => item.product_id === product.id);
        if (line) {
            if (!/^\d+(\.\d{1,3})?$/.test(line.qty)) {
                this.error = 'Enter a valid quantity before adding another unit.';
                return;
            }
            const [whole, fraction] = line.qty.split('.');
            line.qty = String(BigInt(whole) + 1n) + (fraction ? '.' + fraction : '');
        } else if (this.cart.items.length < 100) {
            this.cart.items.push({ product_id: product.id, qty: '1' });
        } else {
            this.error = 'A bill can contain up to 100 distinct products.';
            return;
        }
        this.error = '';
        this.products[product.id] = { name: product.name, stock_qty: product.stock_qty ?? this.products[product.id]?.stock_qty };
        this.refresh();
        this.$refs.scan.focus();
    },
    async searchCustomers() {
        const term = this.customerTerm.trim();
        try {
            const customers = await this.request('/customers?per_page=15' + (term ? '&search=' + encodeURIComponent(term) : ''));
            if (this.customerTerm.trim() === term) this.customers = customers.filter(customer => customer.is_active && !customer.is_system);
        } catch (error) {
            this.error = error.message;
        }
    },
    chooseCustomer(customer) {
        this.cart.customer_id = customer?.id ?? null;
        this.customerName = customer?.name ?? 'Walk-in';
        this.cart.sale_type = customer?.preferred_sale_type ?? 'retail';
        this.$refs.customer.close();
        this.refresh();
        this.$refs.scan.focus();
    },
    async createCustomer() {
        await this.run(async () => {
            this.chooseCustomer(await this.request('/customers', 'POST', this.newCustomer));
            this.newCustomer = { name: '', phone: '' };
        });
    },
    openCustomer() {
        if (this.locked) return;
        this.$refs.customer.showModal();
        this.searchCustomers();
    },
    async run(action) {
        if (this.busy || this.queuedScans) return;
        this.busy = true;
        this.error = '';
        try {
            await action();
        } catch (error) {
            this.error = error.message;
            if (error.code === 'PRICE_CHANGED') await this.refresh();
        } finally {
            this.busy = false;
        }
    },
    async openHeld() {
        if (this.locked) return;
        await this.run(async () => {
            this.bills = await this.request('/held');
            this.$refs.heldList.showModal();
        });
    },
    async resume(bill) {
        if (this.hasDraft) {
            this.error = 'Hold or clear the current draft before resuming another bill.';
            return;
        }
        await this.run(async () => {
            this.cart = { ...blank(), ...bill.cart };
            this.held = bill.id;
            this.savedPricing = bill.pricing;
            this.customerName = this.cart.customer_id ? 'Customer #' + this.cart.customer_id : 'Walk-in';
            this.$refs.heldList.close();
            this.refresh();
            if (this.cart.customer_id) {
                const customer = await this.request('/customers/' + this.cart.customer_id);
                this.customerName = customer.name;
                this.persist();
            }
            this.$refs.scan.focus();
        });
    },
    clear() {
        this.cart = blank();
        this.products = {};
        this.held = this.savedPricing = this.acknowledged = this.retry = null;
        this.customerName = 'Walk-in';
        this.refresh();
    },
    async hold() {
        if (this.retry?.checkout) return;
        if (!this.retry && (!this.preview || this.needsAcknowledgement)) return;
        await this.run(async () => {
            const payload = this.retry ? JSON.parse(this.retry.body) : {
                ...this.cart, held_sale_id: this.held,
                acknowledged_pricing_fingerprint: this.preview.pricing_fingerprint,
            };
            this.retry ??= { body: JSON.stringify(payload), key: crypto.randomUUID() };
            this.persist();
            let bill;
            try {
                bill = await this.request('/held', 'POST', payload, this.retry.key);
            } catch (error) {
                if (error.status && error.status < 500) {
                    this.retry = null;
                    this.persist();
                }
                throw error;
            }
            this.clear();
            this.message = 'Bill #' + bill.id + ' held.';
            this.$refs.scan.focus();
        });
    },
    openCheckout() {
        if (this.busy || this.queuedScans || (this.retry && !this.retry.checkout)) return;
        if (!this.retry && (!this.preview || this.needsAcknowledgement)) return;
        this.checkoutError = '';
        if (!this.$refs.checkout.open) this.$refs.checkout.showModal();
    },
    async checkout() {
        if (!this.retry?.checkout && (!this.preview || this.needsAcknowledgement || !this.payment.valid)) return;
        await this.run(async () => {
            const payload = this.retry ? JSON.parse(this.retry.body) : {
                ...this.cart, held_sale_id: this.held, payments: this.payment.payments,
                acknowledged_pricing_fingerprint: this.preview.pricing_fingerprint,
                ...(this.creditOverride.trim() ? { credit_override_reason: this.creditOverride.trim() } : {}),
            };
            this.retry ??= { checkout: true, body: JSON.stringify(payload), key: crypto.randomUUID(), change: this.payment.change };
            this.persist();
            this.checkoutError = '';
            try {
                const sale = await this.request('/checkout', 'POST', payload, this.retry.key);
                const change = this.retry.change;
                this.clear();
                this.amounts = { cash: '', upi: '', card: '', bank: '' };
                this.creditOverride = '';
                this.completed = { id: sale.id, number: sale.invoice_no };
                this.message = Number(change) > 0 ? 'Sale completed. Change due ₹' + change + '.' : 'Sale completed.';
                this.$refs.checkout.close();
                this.$nextTick(() => this.$refs.invoiceLink.focus());
            } catch (error) {
                if ([409, 422].includes(error.status)) {
                    this.retry = null;
                    this.persist();
                }
                if (error.code === 'PRICE_CHANGED') {
                    this.savedPricing ??= this.preview;
                    this.acknowledged = null;
                    this.$refs.checkout.close();
                    throw error;
                }
                this.checkoutError = error.message;
            }
        });
    },
    async discard(bill) {
        const reason = window.prompt('Why are you discarding bill #' + bill.id + '?');
        if (!reason?.trim()) return;
        await this.run(async () => {
            await this.request('/held/' + bill.id, 'DELETE', { reason }, crypto.randomUUID());
            this.bills = this.bills.filter(entry => entry.id !== bill.id);
            if (this.held === bill.id) this.clear();
            this.message = 'Bill #' + bill.id + ' discarded.';
        });
    },
    acknowledge() {
        this.acknowledged = this.preview.pricing_fingerprint;
        this.persist();
    },
    discountInput(discount) {
        return discount?.type === 'percent' ? discount.value_bp / 100 + '%' : discount?.value ?? '';
    },
    discountIntent(value) {
        const match = /^(\d{1,3})(?:\.(\d{1,2}))?%$/.exec(value.trim());
        if (match) {
            return { type: 'percent', value_bp: Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) };
        }
        return value.trim() ? { type: 'amount', value: value.trim() } : null;
    },
    pricingChange(line) {
        const name = this.products[line.product_id]?.name ?? 'Product #' + line.product_id;
        const previous = this.savedPricing?.items.find(item => item.product_id === line.product_id);
        const describe = item => {
            const slab = item.slab_min_qty ? ' from ' + item.slab_min_qty : '';
            return '₹' + item.unit_price + ' · ' + item.price_source.replaceAll('_', ' ') + slab
                + ' · GST ' + item.rate_bp / 100 + '% ' + (item.price_includes_tax ? 'included' : 'extra');
        };
        return name + ' · ' + (previous ? describe(previous) + ' → ' : '') + describe(line);
    },
    shortcut(event) {
        if (event.defaultPrevented || document.querySelector('dialog[open]')) return;
        const actions = {
            F2: () => this.$refs.scan.focus(),
            F4: () => this.openCustomer(),
            F8: () => this.hold(),
            F9: () => this.openCheckout(),
        };
        if (!actions[event.key]) return;
        event.preventDefault();
        actions[event.key]();
    },
});
