<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class InvoiceVisionDraft
{
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];
        $number = ['type' => ['number', 'null']];
        $item = [
            'item_name' => $text, 'quantity' => $number, 'unit_price' => $number,
            'amount' => $number, 'uncertain' => ['type' => 'boolean'],
        ];
        $properties = [
            'original_invoice_no' => $text, 'invoice_issuer' => $text, 'invoice_date' => $text,
            'currency' => $text, 'invoice_deduction' => $number, 'total' => $number,
            'invoice_items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $item, 'required' => array_keys($item), 'additionalProperties' => false]],
            'uncertain_fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['original_invoice_no', 'invoice_issuer', 'invoice_date', 'currency', 'invoice_deduction', 'total']]],
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function normalize(array $result): array
    {
        $validator = Validator::make($result, [
            'original_invoice_no' => 'present|nullable|string|max:255',
            'invoice_issuer' => 'present|nullable|string|max:255',
            'invoice_date' => 'present|nullable|string|max:30',
            'currency' => 'present|nullable|string|max:30',
            'invoice_deduction' => 'present|nullable|numeric|min:0|max:1000000000',
            'total' => 'present|nullable|numeric|min:0|max:1000000000000',
            'invoice_items' => 'present|array|max:100',
            'invoice_items.*.item_name' => 'present|nullable|string|max:255',
            'invoice_items.*.quantity' => 'present|nullable|numeric|min:0|max:1000000',
            'invoice_items.*.unit_price' => 'present|nullable|numeric|min:0|max:1000000000',
            'invoice_items.*.amount' => 'present|nullable|numeric|min:0|max:1000000000000',
            'invoice_items.*.uncertain' => 'required|boolean',
            'uncertain_fields' => 'present|array|max:6',
            'uncertain_fields.*' => 'string|in:original_invoice_no,invoice_issuer,invoice_date,currency,invoice_deduction,total',
            'notes' => 'present|array|max:10',
            'notes.*' => 'string|max:500',
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invoice_capture.errors.invalid_response');
        }
        $draft = $validator->validated();
        $warnings = [];
        foreach ($draft['uncertain_fields'] as $field) {
            $draft[$field] = null;
            $warnings[] = 'uncertain_fields';
        }
        if ($draft['invoice_date'] !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $draft['invoice_date']);
            if (! $date || $date->format('Y-m-d') !== $draft['invoice_date']) {
                $draft['invoice_date'] = null;
                $warnings[] = 'uncertain_fields';
            }
        }
        $draft['currency'] = $draft['currency'] ? strtoupper(trim($draft['currency'])) : null;
        if ($draft['currency'] !== null && ! preg_match('/^[A-Z]{3}$/', $draft['currency'])) {
            $draft['currency'] = null;
            $warnings[] = 'uncertain_fields';
        }
        $items = [];
        // Keep incomplete rows available for manual correction without treating
        // their uncertain numbers as safe values to apply to the invoice.
        $draft['review_items'] = array_map(fn ($item) => [
            'item_name' => $item['item_name'] ?? '',
            'quantity' => $item['uncertain'] ? '' : (string) ($item['quantity'] ?? ''),
            'unit_price' => $item['uncertain'] ? '' : (string) ($item['unit_price'] ?? ''),
            'amount' => $item['uncertain'] ? '' : (string) ($item['amount'] ?? ''),
        ], $draft['invoice_items']);
        foreach ($draft['invoice_items'] as $item) {
            if ($item['uncertain'] || ! trim($item['item_name'] ?? '') || ! $item['quantity'] || $item['unit_price'] === null) {
                $warnings[] = 'unreadable_rows';

                continue;
            }
            if ($item['amount'] !== null && abs(round($item['quantity'] * $item['unit_price'], 2) - $item['amount']) > 0.02) {
                $warnings[] = 'unreadable_rows';

                continue;
            }
            $items[] = ['item_name' => trim($item['item_name']), 'quantity' => (string) $item['quantity'], 'unit_price' => (string) $item['unit_price']];
        }
        $draft['invoice_items'] = $items;
        if (! $items) {
            $warnings[] = 'no_items';
        } elseif ($draft['total'] !== null) {
            $calculated = array_sum(array_map(fn ($item) => (float) $item['quantity'] * (float) $item['unit_price'], $items)) - ($draft['invoice_deduction'] ?? 0);
            if (abs(round($calculated, 2) - $draft['total']) > 0.02) {
                $warnings[] = 'total_mismatch';
            }
        }
        if ($draft['total'] === null) {
            $warnings[] = 'missing_total';
        }
        if (! $draft['original_invoice_no'] || ! $draft['invoice_issuer'] || ! $draft['invoice_date']) {
            $warnings[] = 'missing_fields';
        }
        $draft['invoice_deduction'] = $draft['invoice_deduction'] !== null ? (string) $draft['invoice_deduction'] : null;
        $draft['warnings'] = array_values(array_unique($warnings));
        $draft['text'] = '';

        return $draft;
    }
}
