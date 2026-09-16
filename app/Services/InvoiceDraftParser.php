<?php

namespace App\Services;

use DateTimeImmutable;
use Normalizer;

class InvoiceDraftParser
{
    private const NUMBER = '(?:\d{1,3}(?:[,٬]\d{3})+|\d+)(?:[.٫]\d{1,4})?';

    public function parse(string $text): array
    {
        // Embedded Arabic PDF fonts often expose presentation forms instead of letters.
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = strtr(mb_substr($text, 0, 60000), array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
        $text = preg_replace('/[\x{200e}\x{200f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', $text) ?? $text;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));

        $number = $this->labelValue($lines, '(?:Invoice\s*(?:No\.?|Number|#)|(?:رقم\s*(?:الفاتورة|فاتورة)|فاتورة\s*رقم))');
        $issuer = $this->labelValue($lines, '(?:Supplier|Vendor|Seller|Issued by|اسم المورد|المورد|البائع|الجهة المصدرة)');
        if (! $issuer) {
            foreach (array_slice($lines, 0, 3) as $line) {
                if (mb_strlen($line) <= 120 && preg_match('/\p{L}/u', $line)
                    && ! preg_match('/invoice|فاتورة|date|تاريخ|phone|هاتف|tax|ضريب|\d{4,}/iu', $line)) {
                    $issuer = $line;
                    break;
                }
            }
        }

        $date = null;
        foreach ($lines as $line) {
            if (preg_match('/(?:Due\s*Date|تاريخ الاستحقاق)/iu', $line)) {
                continue;
            }
            if (preg_match('/(?<!\d)(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{4})(?!\d)/u', $line, $match)) {
                $date = $this->date($match[1]);
                if ($date) {
                    break;
                }
            }
        }

        $currency = null;
        if (preg_match('/\b(SYP|USD|EUR|TRY|SAR|AED|GBP)\b/i', $text, $match)) {
            $currency = strtoupper($match[1]);
        } elseif (preg_match('/ل\s*[.\x{066b}]\s*س|ليرة\s*سورية/u', $text)) {
            $currency = 'SYP';
        }

        $items = [];
        $warnings = [];
        $inTable = false;
        $numericColumns = [];
        foreach ($lines as $line) {
            $quantityPosition = $this->labelPosition($line, '/\b(?:Qty|Quantity)\b|الكمية/iu');
            $pricePosition = $this->labelPosition($line, '/\b(?:Price|Rate)\b|سعر|السعر/iu');
            $amountPosition = $this->labelPosition($line, '/\b(?:Total|Amount)\b|الإجمالي|الاجمالي|المبلغ|القيمة/iu');
            if ($quantityPosition !== null && $pricePosition !== null && $amountPosition !== null) {
                $inTable = true;
                $positions = ['quantity' => $quantityPosition, 'unit_price' => $pricePosition, 'amount' => $amountPosition];
                asort($positions);
                $numericColumns = array_keys($positions);

                continue;
            }
            if (preg_match('/(?:grand\s*total|sub\s*total|total|discount|tax|balance|paid|الإجمالي|الاجمالي|المجموع|الخصم|خصم|الصافي|الضريبة|المدفوع)/iu', $line)) {
                $inTable = false;

                continue;
            }
            if (! $inTable || count($items) >= 100) {
                continue;
            }

            $n = self::NUMBER;
            $values = null;
            if (preg_match('/^(.+?)\s+('.$n.')\s+('.$n.')\s+('.$n.')\s*$/u', $line, $match)) {
                $values = [$match[1], $match[2], $match[3], $match[4]];
            } elseif (preg_match('/^('.$n.')\s+('.$n.')\s+('.$n.')\s+(.+)$/u', $line, $match)) {
                $values = [$match[4], $match[1], $match[2], $match[3]];
            }
            if (! $values || ! preg_match('/\p{L}/u', $values[0])) {
                continue;
            }

            $numbers = array_combine($numericColumns, array_map(fn (string $value) => $this->number($value), array_slice($values, 1)));
            $quantity = $numbers['quantity'];
            $price = $numbers['unit_price'];
            $amount = $numbers['amount'];
            if ($quantity <= 0 || $quantity > 1000000 || $price > 1000000000 || abs(round($quantity * $price, 2) - $amount) > 0.02) {
                $warnings[] = 'unreadable_rows';

                continue;
            }

            $items[] = ['item_name' => mb_substr(trim($values[0]), 0, 255), 'quantity' => (string) $quantity, 'unit_price' => (string) $price];
        }

        $discount = $this->moneyValue($lines, '(?:Discount|Deduction|الخصم|خصم|الحسم|حسم)');
        $total = $this->moneyValue($lines, '(?:Grand\s*Total|Total\s*Amount|Amount\s*Due|الإجمالي النهائي|الاجمالي النهائي|الصافي|المجموع النهائي)')
            ?? $this->moneyValue($lines, '(?:Total|الإجمالي|الاجمالي|المجموع)');

        if (! $items) {
            $warnings[] = 'no_items';
        } elseif ($total !== null) {
            $calculated = array_sum(array_map(fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price'], $items)) - ($discount ?? 0);
            if (abs(round($calculated, 2) - $total) > 0.02) {
                $warnings[] = 'total_mismatch';
            }
        }
        if (! $number || ! $issuer || ! $date) {
            $warnings[] = 'missing_fields';
        }

        return [
            'original_invoice_no' => $number ? mb_substr($number, 0, 255) : null,
            'invoice_issuer' => $issuer ? mb_substr($issuer, 0, 255) : null,
            'invoice_date' => $date,
            'invoice_deduction' => $discount !== null ? (string) $discount : null,
            'invoice_items' => $items,
            'currency' => $currency,
            'total' => $total,
            'warnings' => array_values(array_unique($warnings)),
            'text' => $text,
        ];
    }

    private function labelValue(array $lines, string $label): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^'.$label.'(?:\s*[:#\-]\s*|\s+|(?=\d))(.+)$/iu', $line, $match)) {
                return trim($match[1], " \t\n\r\0\x0B:#");
            }
        }

        return null;
    }

    private function moneyValue(array $lines, string $label): ?float
    {
        foreach (array_reverse($lines) as $line) {
            if (preg_match('/^'.$label.'\s*[:\-]?\s*(?:[A-Z]{3}\s*)?('.self::NUMBER.')(?:\s*[A-Z]{3})?\s*:?\s*$/iu', $line, $match)) {
                return $this->number($match[1]);
            }
        }

        return null;
    }

    private function labelPosition(string $line, string $pattern): ?int
    {
        return preg_match($pattern, $line, $match, PREG_OFFSET_CAPTURE) ? $match[0][1] : null;
    }

    private function number(string $value): float
    {
        return (float) str_replace([',', '٬', '٫'], ['', '', '.'], $value);
    }

    private function date(string $value): ?string
    {
        $value = str_replace(['/', '.'], '-', $value);
        $format = preg_match('/^\d{4}-/', $value) ? '!Y-n-j' : '!j-n-Y';
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date->format('Y-m-d')
            : null;
    }
}
