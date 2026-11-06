const partyFields = ['name', 'phone', 'address', 'state_code', 'gstin'];
const freshEntry = () => ({
    amount: '', entry_type: 'due', effective_at: '', reason: '', reference: '', note: '',
    mode: 'cash', historical: false, included: '', auto_allocate: true, target: 'opening_entry_id', targetId: '',
});
const dateFormat = new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata',
});

window.workspaceParties = (base, branch, csrf, scope, customer) => ({
    search: '',
    list: [],
    listMeta: {},
    selected: null,
    entries: [],
    ledgerMeta: {},
    filters: { as_of: '', recorded_as_of: '' },
    appliedFilters: {},
    listRevision: 0,
    ledgerRevision: 0,
    entryRevision: 0,
    loadingList: false,
    loadingLedger: false,
    error: '',
    formError: '',
    status: '',
    busy: false,
    profile: {},
    editing: false,
    operation: '',
    draft: freshEntry(),
    preview: null,
    retry: null,
    entry: null,
    details: null,
    allocation: null,
    detailsError: '',
    get path() { return '/' + (customer ? 'customers' : 'suppliers'); },
    get account() { return this.path + '/' + this.selected.id; },
    get paymentPath() { return customer ? 'receipts' : 'payments'; },
    get paymentOperation() { return ['payment', 'evidence'].includes(this.operation); },
    get canApply() {
        const row = this.details?.record;
        if (!row || row.status !== 'posted' || row.reversal_of_id) return false;
        return this.entry.source === 'opening'
            ? customer && ['credit', 'advance'].includes(row.entry_type)
            : row.balance_effect === 'normal' && ['customer_receipt', 'supplier_payment'].includes(row.purpose);
    },
    get retryKey() { return 'khata.party.' + scope + '.' + branch + this.account; },
    get isHistoricalView() { return !!Object.values(this.appliedFilters).some(Boolean); },
    get openingLabel() {
        return { not_entered: 'Opening not entered', confirmed_zero: 'Opening confirmed zero', entered: 'Opening entered' }[this.ledgerMeta.opening_status];
    },
    get heading() {
        return {
            opening: this.draft.replaces ? 'Replace reversed opening' : 'Record opening balance', zero: 'Confirm zero opening', payment: customer ? 'Record receipt' : 'Record payment',
            reverse: 'Reverse entry', apply: 'Apply to an outstanding balance', unapply: 'Reverse allocation',
            evidence: customer ? 'Record historical receipt evidence' : 'Record historical payment evidence',
        }[this.operation];
    },
    init() { this.loadList(); },
    date(value) {
        if (!value) return '—';
        return dateFormat.format(new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z'));
    },
    label(value) { return value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase()); },
    async request(path, method = 'GET', data, key) {
        const response = await fetch(base + path, {
            method, signal: AbortSignal.timeout(20000),
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
            throw error;
        }
        return result;
    },
    async loadList(page = 1) {
        const revision = ++this.listRevision;
        this.loadingList = true;
        const query = new URLSearchParams({ page, per_page: 15 });
        if (this.search.trim()) query.set('search', this.search.trim());
        try {
            const result = await this.request(this.path + '?' + query);
            if (revision !== this.listRevision) return;
            this.list = result.data;
            this.listMeta = result.meta;
        } catch (error) {
            if (revision === this.listRevision) this.error = error.message;
        } finally {
            if (revision === this.listRevision) this.loadingList = false;
        }
    },
    choose(party) {
        this.entryRevision++;
        this.error = this.status = '';
        this.selected = party;
        this.filters = { as_of: '', recorded_as_of: '' };
        this.appliedFilters = {};
        this.retry = null;
        try {
            this.retry = JSON.parse(sessionStorage.getItem(this.retryKey));
        } catch {
            this.error = 'This browser could not restore the previous submission.';
        }
        this.entries = [];
        this.ledgerMeta = {};
        this.loadLedger();
        this.$nextTick(() => this.$refs.ledgerHeading.focus());
    },
    async loadLedger(page = 1) {
        const revision = ++this.ledgerRevision;
        if (!this.selected || this.selected.is_system) {
            this.loadingLedger = false;
            return;
        }
        this.loadingLedger = true;
        const query = new URLSearchParams({ page, per_page: 15 });
        for (const [name, value] of Object.entries(this.appliedFilters)) {
            if (value) query.set(name, value);
        }
        try {
            const result = await this.request(this.account + '/ledger?' + query);
            if (revision !== this.ledgerRevision) return;
            this.entries = result.data;
            this.ledgerMeta = result.meta;
        } catch (error) {
            if (revision === this.ledgerRevision) this.error = error.message;
        } finally {
            if (revision === this.ledgerRevision) this.loadingLedger = false;
        }
    },
    filterLedger() {
        this.error = '';
        this.appliedFilters = { ...this.filters };
        this.loadLedger();
    },
    openProfile(edit) {
        this.editing = edit;
        const values = edit ? this.selected : {};
        this.profile = Object.fromEntries(partyFields.map(field => [field, values[field] ?? '']));
        this.profile.is_active = values.is_active ?? true;
        this.profile.credit_limit = values.credit_limit ?? '0.00';
        this.profile.preferred_sale_type = values.preferred_sale_type ?? 'retail';
        this.formError = '';
        this.$refs.profile.showModal();
    },
    async saveProfile(manage) {
        if (this.busy) return;
        this.busy = true;
        this.formError = '';
        const data = Object.fromEntries(partyFields.map(field => [field, this.profile[field].trim() || null]));
        if (manage) {
            data.is_active = this.profile.is_active;
            if (customer) {
                data.credit_limit = this.profile.credit_limit;
                data.preferred_sale_type = this.profile.preferred_sale_type;
            }
        }
        try {
            const result = await this.request(this.editing ? this.account : this.path, this.editing ? 'PATCH' : 'POST', data);
            this.$refs.profile.close();
            this.search = '';
            this.loadList();
            this.choose(result.data);
            this.status = 'Party record saved.';
        } catch (error) {
            this.formError = error.message;
        } finally {
            this.busy = false;
        }
    },
    async showEntry(entry) {
        const revision = ++this.entryRevision;
        this.entry = entry;
        this.details = null;
        this.detailsError = '';
        this.$refs.details.showModal();
        try {
            const result = await this.request(this.account + '/entries/' + entry.source + '/' + entry.id);
            if (revision === this.entryRevision) this.details = result.data;
        } catch (error) {
            if (revision === this.entryRevision) this.detailsError = error.message;
        }
    },
    start(operation, allocation = null, replaces = null) {
        this.operation = operation;
        this.allocation = allocation;
        this.draft = freshEntry();
        this.draft.replaces = replaces;
        this.formError = '';
        this.preview = null;
        if (operation === 'evidence') {
            this.draft.historical = true;
            this.draft.included = this.entry.id;
        }
        this.$refs.details.close();
        this.$refs.posting.showModal();
    },
    recover() {
        this.operation = this.retry.operation;
        this.formError = '';
        this.$refs.posting.showModal();
    },
    intent() {
        const { amount, effective_at, reason, reference, note, entry_type, historical, included, mode, auto_allocate, target, targetId } = this.draft;
        const explanation = { reason: reason.trim() };
        const payment = this.paymentPath;
        const opening = this.entry?.source === 'opening';
        const entry = this.entry?.id;
        switch (this.operation) {
            case 'opening': return ['opening-entries', { ...explanation, amount, effective_at, entry_type, reference, note,
                ...(this.draft.replaces ? { replaces_entry_id: this.draft.replaces } : {}) }];
            case 'zero': return ['opening-status/confirm-zero', { ...explanation, effective_at }];
            case 'reverse': return opening
                ? ['opening-entries/' + entry + '/reverse', { ...explanation, effective_at }]
                : [payment + '/' + entry + '/reverse', explanation];
            case 'apply': return [opening ? 'opening-entries/' + entry + '/apply-credit' : payment + '/' + entry + '/apply',
                { amount, [target]: Number(targetId), ...(effective_at ? { effective_at } : {}) }];
            case 'unapply': return [opening ? 'opening-entries/' + entry + '/credit-allocations/' + this.allocation.id + '/reverse'
                : payment + '/' + entry + '/allocations/' + this.allocation.id + '/reverse', explanation];
            default: return [payment, { amount, mode, reference, auto_allocate: included ? false : auto_allocate,
                ...(historical ? { historical_entry: true, paid_at: effective_at, ...explanation,
                    ...(included ? { included_in_opening_entry_id: Number(included) } : {}) } : {}) }];
        }
    },
    async post() {
        if (this.busy) return;
        this.busy = true;
        this.formError = '';
        try {
            const [endpoint, payload] = this.retry ? [this.retry.endpoint, this.retry.payload] : this.intent();
            if (!this.retry && this.paymentOperation && !this.preview) {
                this.preview = (await this.request(this.account + '/' + endpoint + '/preview', 'POST', payload)).data;
                return;
            }
            const data = this.retry ? payload : { ...payload, ...(customer && payload.historical_entry ? { confirm_balance_effect: true } : {}) };
            this.retry ??= { endpoint, payload: data, key: crypto.randomUUID(), operation: this.operation };
            try {
                sessionStorage.setItem(this.retryKey, JSON.stringify(this.retry));
            } catch {
                this.error = 'Keep this page open until the posting is confirmed.';
            }
            await this.request(this.account + '/' + this.retry.endpoint, 'POST', this.retry.payload, this.retry.key);
            this.clearRetry();
            this.$refs.posting.close();
            this.status = 'Ledger entry posted.';
            this.loadLedger();
        } catch (error) {
            if ([409, 422].includes(error.status)) this.clearRetry();
            this.formError = error.message;
        } finally {
            this.busy = false;
        }
    },
    clearRetry() {
        this.retry = null;
        try { sessionStorage.removeItem(this.retryKey); } catch { /* Current tab still has the result. */ }
    },
    reversible(allocation) {
        return !allocation.reversal_of_id && !this.details.allocations.some(row => row.reversal_of_id === allocation.id);
    },
});
