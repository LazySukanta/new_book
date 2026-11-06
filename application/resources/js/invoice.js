const money = value => 'INR ' + value;

function invoiceDefinition(invoice, includeNote) {
    const text = value => ({ text: String(value ?? '') });
    const party = (label, details) => [label, details.name, details.address, details.phone, details.gstin && 'GSTIN ' + details.gstin]
        .filter(Boolean).join('\n');
    const taxed = invoice.document_type !== 'bill_of_supply';
    const totals = [['Subtotal', invoice.totals.subtotal], ['Discount', invoice.totals.discount],
        ...(taxed ? [['Taxable', invoice.totals.taxable], ['CGST', invoice.totals.cgst], ['SGST', invoice.totals.sgst], ['IGST', invoice.totals.igst]] : []),
        ['Round-off', invoice.totals.round_off], ['Total', invoice.totals.total]];
    return {
        pageSize: 'A4',
        defaultStyle: { fontSize: 10 },
        content: [
            { text: invoice.shop?.legal_name || invoice.shop?.name || invoice.branch.name, fontSize: 18, bold: true },
            text(party('', invoice.branch)),
            { text: invoice.title + '  ' + invoice.invoice_no, fontSize: 15, bold: true, margin: [0, 10, 0, 3] },
            ...(invoice.status === 'cancelled' ? [{ text: 'CANCELLED', color: '#a7272d', bold: true, fontSize: 17 }] : []),
            text(invoice.display_date),
            ...(invoice.original_invoice_no ? [text('Original invoice ' + invoice.original_invoice_no), text(invoice.reason)] : []),
            { ...text(party('Customer', invoice.customer)), margin: [0, 8, 0, 4] },
            text('Place of supply ' + (invoice.place_of_supply.name || invoice.place_of_supply.code || '—')),
            { margin: [0, 10, 0, 8], table: { headerRows: 1, widths: ['*', 'auto', 'auto', 'auto', 'auto', 'auto'], body: [
                ['Item', 'HSN', 'Qty', 'Rate', 'GST', 'Total'],
                ...invoice.items.map(line => [text(line.name), text(line.hsn), text(line.qty + ' ' + (line.unit ?? '')),
                    text(line.unit_price), text(taxed ? line.rate_bp / 100 + '%' : '—'), text(line.line_total)]),
            ] }, layout: 'lightHorizontalLines' },
            ...(taxed && invoice.tax_summary.length ? [{ table: { headerRows: 1, widths: ['*', 'auto', 'auto', 'auto', 'auto', 'auto'], body: [
                ['HSN', 'Rate', 'Taxable', 'CGST', 'SGST', 'IGST'],
                ...invoice.tax_summary.map(line => [text(line.hsn), text(line.rate_bp / 100 + '%'), line.taxable, line.cgst, line.sgst, line.igst]),
            ] }, layout: 'lightHorizontalLines', margin: [0, 0, 0, 8] }] : []),
            ...totals.filter(([, amount]) => amount != null).map(([label, amount]) => ({ text: label + '  ' + money(amount), alignment: 'right', bold: label === 'Total' })),
            ...(invoice.total_in_words ? [text(invoice.total_in_words)] : []),
            ...(invoice.notice ? [text(invoice.notice)] : []),
            ...(includeNote && invoice.note ? [{ ...text('Comment\n' + invoice.note), margin: [0, 8, 0, 0] }] : []),
            { text: 'Authorised signature', alignment: 'right', margin: [0, 24, 0, 0] },
            ...(invoice.shop?.footer ? [text(invoice.shop.footer)] : []),
        ],
    };
}

window.workspaceInvoice = (invoice, base, branch, csrf) => ({
    invoice,
    includeNote: false,
    busy: false,
    error: '',
    reason: '',
    note: invoice.note ?? '',
    refundMode: '',
    refundRecovered: false,
    quantities: {},
    action: '',
    retry: null,
    async pdf() {
        this.busy = true;
        this.error = '';
        try {
            const [{ default: pdfMake }, { default: fonts }] = await Promise.all([
                import('pdfmake/build/pdfmake.js'), import('pdfmake/build/vfs_fonts.js'),
            ]);
            pdfMake.addVirtualFileSystem(fonts);
            pdfMake.createPdf(invoiceDefinition(this.invoice, this.includeNote))
                .download(this.invoice.invoice_no.replaceAll('/', '-') + '.pdf', () => { this.busy = false; });
        } catch {
            this.error = 'The PDF could not be generated. Try downloading it again.';
            this.busy = false;
        }
    },
    print() {
        this.error = '';
        try {
            window.print();
        } catch {
            this.error = 'Printing could not start. Check the printer and try again.';
        }
    },
    edit(action) {
        if (this.retry && this.action !== action) return;
        if (this.action !== action) {
            this.reason = this.refundMode = '';
            this.refundRecovered = false;
            this.quantities = {};
        }
        this.action = action;
        this.error = '';
        this.$refs.adjustment.showModal();
    },
    async submit() {
        if (this.busy) return;
        const isReturn = this.action === 'return';
        const isComment = this.action === 'comment';
        const credit = this.invoice.document_type === 'credit_note';
        const path = credit ? '/credit-notes/' + this.invoice.id + '/cancel'
            : '/sales/' + this.invoice.id + (isReturn ? '/returns' : isComment ? '/comment' : '/cancel');
        const payload = { reason: this.reason.trim() };
        if (this.refundMode && !credit) payload.refund_mode = this.refundMode;
        if (credit) payload.refund_recovered = this.refundRecovered;
        if (isComment) payload.note = this.note;
        if (isReturn) {
            payload.items = Object.entries(this.quantities).filter(([, qty]) => qty.trim() && Number(qty) !== 0)
                .map(([id, qty]) => ({ sale_item_id: Number(id), qty: qty.trim() }));
        }
        this.retry ??= { payload, key: crypto.randomUUID() };
        this.error = '';
        this.busy = true;
        try {
            const response = await fetch(base + path, {
                method: isComment ? 'PATCH' : 'POST',
                signal: AbortSignal.timeout(20000),
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf,
                    'X-Branch-Id': branch, 'Idempotency-Key': this.retry.key },
                body: JSON.stringify(this.retry.payload),
            });
            const result = await response.json();
            if (!response.ok) {
                if (response.status < 500) this.retry = null;
                throw new Error(Object.values(result.errors ?? {}).flat()[0] || result.message || 'The adjustment failed.');
            }
            if (isReturn) window.location.assign(base + '/invoices/' + branch + '/credit-note/' + result.data.id);
            else window.location.reload();
        } catch (error) {
            this.error = error.message;
        } finally {
            this.busy = false;
        }
    },
});
