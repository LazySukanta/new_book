@php
    $credit = $invoice['document_type'] === 'credit_note';
    $taxed = $invoice['document_type'] !== 'bill_of_supply';
    $cancelled = $invoice['status'] === 'cancelled';
@endphp
<x-workspace.layout :title="$invoice['invoice_no'].' · KhataPOS'">
    <main id="main-content" class="mx-auto max-w-4xl space-y-4 px-4 py-6 sm:px-8"
        x-data="workspaceInvoice(@js($invoice), @js(route('workspace.pos')), @js((string) $branch->id), @js(csrf_token()))">
        <nav class="invoice-controls flex flex-wrap items-center gap-3" aria-label="Invoice controls">
            <a class="link link-primary mr-auto" href="{{ route('workspace.pos.invoices', $branch->id) }}">Invoice history</a>
            <button type="button" class="btn btn-sm" @click="pdf()" :disabled="busy">Download A4 PDF</button>
            <button type="button" class="btn btn-sm btn-primary" @click="print()">Print {{ $width }} mm receipt</button>
        </nav>
        @if ($invoice['note'])
            <label class="invoice-controls flex items-center gap-2 text-sm">
                <input type="checkbox" class="checkbox checkbox-sm" x-model="includeNote"> Include comment in this print or PDF
            </label>
        @endif
        <p class="invoice-controls text-sm text-error" role="alert" x-text="error"></p>
        <article class="invoice-paper invoice-paper-{{ $width }} space-y-4 rounded-box border border-base-300 bg-base-100 p-4 sm:p-6">
            <header class="space-y-1">
                <p class="font-bold">{{ $invoice['shop']['legal_name'] ?? $invoice['shop']['name'] ?? $invoice['branch']['name'] }}</p>
                <p class="text-sm">{{ $invoice['branch']['name'] }} {{ $invoice['branch']['address'] }} {{ $invoice['branch']['phone'] }}</p>
                @if ($invoice['branch']['gstin']) <p class="text-sm">GSTIN {{ $invoice['branch']['gstin'] }}</p> @endif
                <h1 class="break-words text-xl font-bold">{{ $invoice['title'] }} {{ $invoice['invoice_no'] }}</h1>
                @if ($cancelled) <p class="text-lg font-bold text-error">CANCELLED</p> @endif
                <p class="text-sm">{{ $invoice['display_date'] }}</p>
                @if ($credit)
                    <p class="text-sm">Original invoice {{ $invoice['original_invoice_no'] }}</p>
                    <p class="break-words text-sm">{{ $invoice['reason'] }}</p>
                @endif
            </header>
            <div class="border-t border-base-300 pt-2 text-sm">
                <p><strong>Customer</strong> {{ $invoice['customer']['name'] }}</p>
                <p>{{ $invoice['customer']['address'] }} {{ $invoice['customer']['phone'] }}</p>
                @if ($invoice['customer']['gstin']) <p>GSTIN {{ $invoice['customer']['gstin'] }}</p> @endif
                <p>Place of supply {{ $invoice['place_of_supply']['name'] ?? $invoice['place_of_supply']['code'] ?? '—' }}</p>
            </div>
            <table class="invoice-lines w-full table-fixed border-collapse text-left text-sm tabular-nums">
                <thead><tr class="border-b border-base-300"><th scope="col" class="w-1/2 py-2">Item</th><th scope="col" class="text-right">Qty</th><th scope="col" class="text-right">Total</th></tr></thead>
                <tbody>
                    @foreach ($invoice['items'] as $line)
                        <tr class="border-b border-base-300 align-top">
                            <th scope="row" class="break-words py-2 pr-1 font-medium">
                                {{ $line['name'] }}
                                <span class="block text-xs font-normal">₹{{ $line['unit_price'] }} / {{ $line['unit'] }}
                                    @if ($line['hsn']) · HSN {{ $line['hsn'] }} @endif
                                    @if ($taxed) · GST {{ $line['rate_bp'] / 100 }}% @endif
                                </span>
                            </th>
                            <td class="py-2 text-right">{{ $line['qty'] }}</td>
                            <td class="break-words py-2 text-right">₹{{ $line['line_total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <dl class="ml-auto max-w-xs space-y-1 text-sm tabular-nums" aria-label="Invoice totals">
                @foreach (['subtotal' => 'Subtotal', 'discount' => 'Discount', 'taxable' => 'Taxable', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'round_off' => 'Round-off', 'total' => 'Total'] as $key => $label)
                    @if (isset($invoice['totals'][$key]) && ($taxed || !in_array($key, ['taxable', 'cgst', 'sgst', 'igst'])))
                        <div @class(['flex justify-between gap-2', 'border-t border-base-300 pt-2 font-bold' => $key === 'total'])>
                            <dt>{{ $label }}</dt><dd>₹{{ $invoice['totals'][$key] }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @if (isset($invoice['total_in_words'])) <p class="text-sm">{{ $invoice['total_in_words'] }}</p> @endif
            @if ($invoice['notice']) <p class="text-sm">{{ $invoice['notice'] }}</p> @endif
            @if ($invoice['note'])
                <div class="invoice-note text-sm" :class="{ 'invoice-note-excluded': !includeNote }">
                    <p class="font-semibold">Comment</p><p class="whitespace-pre-wrap break-words">{{ $invoice['note'] }}</p>
                </div>
            @endif
            @if (!empty($invoice['shop']['footer'])) <p class="whitespace-pre-wrap break-words text-sm">{{ $invoice['shop']['footer'] }}</p> @endif
        </article>
        <section class="invoice-controls rounded-box border border-base-300 bg-base-100 p-4">
            <h2 class="font-bold">{{ $credit ? 'Credit note settlement' : 'Current settlement' }}</h2>
            <dl class="mt-2 max-w-sm space-y-1 text-sm tabular-nums">
                @foreach (['paid_amount' => 'Paid', 'balance_amount' => 'Balance', 'opening_credit_applied' => 'Opening credit applied', 'credit_note_adjusted' => 'Credit notes applied', 'credit_adjusted' => 'Balance adjusted', 'refund' => 'Refund', 'retained_credit' => 'Retained credit'] as $key => $label)
                    @isset($invoice['settlement'][$key])
                        <div class="flex justify-between gap-2"><dt>{{ $label }}</dt><dd>₹{{ $invoice['settlement'][$key] }}</dd></div>
                    @endisset
                @endforeach
            </dl>
            @foreach ($invoice['settlement']['payments'] ?? [] as $payment)
                <p class="text-sm">{{ strtoupper($payment['mode']) }} · ₹{{ $payment['amount'] }}</p>
            @endforeach
        </section>
        @if (isset($returns) && $returns->count())
            <section class="invoice-controls rounded-box border border-base-300 bg-base-100 p-4">
                <h2 class="font-bold">Credit notes</h2>
                <ul class="my-2 space-y-1">
                    @foreach ($returns as $note)
                        <li><a class="link link-primary" href="{{ route('workspace.pos.credit-note', [$branch->id, $note->id]) }}">{{ $note->credit_note_no }}</a> · {{ ucfirst($note->status) }}</li>
                    @endforeach
                </ul>
                {{ $returns->links() }}
            </section>
        @endif
        @unless ($cancelled)
            <div class="invoice-controls flex flex-wrap gap-2">
                @if (!$credit)
                    @can('sales.return') <button type="button" class="btn" @click="edit('return')">Return items</button> @endcan
                @endif
                @can('sales.cancel') <button type="button" class="btn btn-outline text-error" @click="edit('cancel')">{{ $credit ? 'Cancel credit note' : 'Cancel sale' }}</button> @endcan
            </div>
        @endunless
        @if (!$credit && auth()->user()->hasRole('Owner'))
            <section class="invoice-controls rounded-box border border-base-300 bg-base-100 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-bold">Comment history</h2>
                    <button type="button" class="btn btn-sm" @click="edit('comment')">Edit comment</button>
                </div>
                @forelse ($comments as $change)
                    <div class="mt-2 border-t border-base-300 pt-2 text-sm">
                        <p>{{ $change['created_at'] }} · {{ $change['reason'] }}</p>
                        <p class="whitespace-pre-wrap break-words">Previous · {{ $change['before'] ?? 'No comment' }}</p>
                        <p class="whitespace-pre-wrap break-words">Updated · {{ $change['after'] ?? 'No comment' }}</p>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-base-content/75">No comment changes recorded.</p>
                @endforelse
            </section>
        @endif
        <x-ui.dialog id="invoice-adjustment" title="Adjust invoice" x-ref="adjustment" class="invoice-controls">
            <form class="mt-3 space-y-3" @submit.prevent="submit()">
                <p class="text-sm text-error" role="alert" x-text="error"></p>
                <fieldset class="space-y-3" :disabled="busy || !!retry">
                    <div x-show="action === 'return'" class="space-y-2">
                        <p class="text-sm">Return quantities use the original invoice pricing.</p>
                        @foreach ($invoice['items'] as $line)
                            @if (isset($line['id']))
                                <label class="flex items-center justify-between gap-2 text-sm">
                                    <span class="min-w-0 break-words">{{ $line['name'] }} · Sold {{ $line['qty'] }}</span>
                                    <input class="input input-sm w-24 shrink-0" inputmode="decimal" placeholder="0" x-model="quantities[{{ $line['id'] }}]">
                                </label>
                            @endif
                        @endforeach
                    </div>
                    <label class="block text-sm font-semibold">Reason
                        <input class="input mt-1 w-full" x-model="reason" required maxlength="255">
                    </label>
                    <label x-show="action === 'comment'" class="block text-sm font-semibold">Bill comment
                        <textarea class="textarea mt-1 w-full" x-model="note" maxlength="1000" rows="3"></textarea>
                    </label>
                    @if ($credit)
                        <label class="flex items-center gap-2 text-sm"><input class="checkbox checkbox-sm" type="checkbox" x-model="refundRecovered"> Refund has been recovered, if applicable</label>
                    @else
                        <label x-show="action !== 'comment'" class="block text-sm font-semibold">Settlement for any excess credit
                            <select class="select mt-1 w-full" x-model="refundMode">
                                <option value="">No refund required</option><option value="cash">Cash refund</option>
                                <option value="upi">UPI refund</option><option value="card">Card refund</option>
                                <option value="bank">Bank refund</option><option value="credit_adjust">Retain customer credit</option>
                            </select>
                        </label>
                    @endif
                </fieldset>
                <button class="btn btn-primary" :disabled="busy" x-text="busy ? 'Submitting…' : retry ? 'Recover result' : action === 'return' ? 'Issue credit note' : action === 'comment' ? 'Save comment' : 'Confirm cancellation'"></button>
            </form>
        </x-ui.dialog>
    </main>
</x-workspace.layout>
