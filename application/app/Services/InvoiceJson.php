<?php

namespace App\Services;

use Brick\Math\BigDecimal;

final class InvoiceJson
{
    public static function fromSnapshot(array $snapshot): array
    {
        $taxSummary = [];
        $discount = BigDecimal::zero()->toScale(2);
        foreach ($snapshot['items'] as $index => &$line) {
            unset($line['cost']);
            $product = $line['product'];
            $line += [
                'sr' => $index + 1, 'name' => $product['name'], 'sku' => $product['sku'],
                'hsn' => $product['hsn'], 'unit' => $product['unit'] ?? null,
            ];
            $group = $line['hsn'].':'.$line['rate_bp'];
            $taxSummary[$group] ??= ['hsn' => $line['hsn'], 'rate_bp' => $line['rate_bp']];
            foreach (['taxable', 'cgst', 'sgst', 'igst'] as $component) {
                $taxSummary[$group][$component] = (string) BigDecimal::of($taxSummary[$group][$component] ?? '0.00')->plus($line[$component]);
            }
            $discount = $discount->plus($line['line_discount'])->plus($line['bill_discount_share']);
        }
        unset($line);
        $snapshot['totals']['discount'] = (string) $discount;

        return $snapshot + [
            'title' => strtoupper(str_replace('_', ' ', $snapshot['document_type'])),
            'date' => $snapshot['issued_at'] ?? null,
            'place_of_supply' => ['code' => $snapshot['place_of_supply_code'], 'name' => $snapshot['place_of_supply_name'] ?? null],
            'tax_summary' => array_values($taxSummary),
            'notice' => $snapshot['document_type'] === 'bill_of_supply' && $snapshot['gst_registration_type'] === 'composition'
                ? 'Composition taxable person, not eligible to collect tax on supplies.' : null,
            'note' => null,
        ];
    }
}
