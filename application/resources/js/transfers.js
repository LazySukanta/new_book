export default (rows, url) => ({
    rows, found: [], search: '', message: '', controller: null,
    async find() {
        this.controller?.abort();
        this.found = [];
        if (!this.search.trim()) { this.message = ''; return; }
        this.controller = new AbortController();
        this.message = 'Searching…';
        try {
            const response = await fetch(url + '?search=' + encodeURIComponent(this.search.trim()), { headers: { Accept: 'application/json' }, signal: this.controller.signal });
            if (!response.ok) throw new Error('Unable to search products. Type again to retry.');
            this.found = (await response.json()).data;
            this.message = this.found.length ? 'Choose a product to add.' : 'No matching products.';
        } catch (error) { if (error.name !== 'AbortError') this.message = error.message; }
    },
    add(product) {
        if (this.rows.some(row => Number(row.product_id) === product.id)) { this.message = 'This product is already on the draft.'; return; }
        this.rows.push({ product_id: product.id, name: product.name, qty: '1' });
        this.found = []; this.search = ''; this.message = '';
    },
});
