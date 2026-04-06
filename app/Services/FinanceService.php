<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class FinanceService
{
    public function nextInvoiceNumber(): string
    {
        $prefix = (string) (DB::table('app_settings')
            ->where('group', 'finance')
            ->where('key', 'invoice_prefix')
            ->value('value') ?: 'INV');

        $lastInvoiceNo = Invoice::query()
            ->where('invoice_no', 'like', $prefix.'-%')
            ->latest('id')
            ->value('invoice_no');

        $nextSequence = 1;

        if ($lastInvoiceNo && preg_match('/(\d+)$/', $lastInvoiceNo, $matches) === 1) {
            $nextSequence = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%06d', $prefix, $nextSequence);
    }

    public function syncActivityTotals(Activity $activity): void
    {
        $expectedRevenue = ActivityRegistration::query()
            ->where('activity_id', $activity->id)
            ->where('status', '!=', 'cancelled')
            ->sum('fee_amount');

        $collectedRevenue = ActivityPayment::query()
            ->whereHas('registration', fn ($query) => $query->where('activity_id', $activity->id))
            ->whereNull('voided_at')
            ->sum('amount');

        $expenseTotal = ActivityExpense::query()
            ->where('activity_id', $activity->id)
            ->sum('amount');

        $activity->update([
            'expected_revenue_cached' => $expectedRevenue,
            'collected_revenue_cached' => $collectedRevenue,
            'expense_total_cached' => $expenseTotal,
        ]);
    }

    public function syncInvoiceTotals(Invoice $invoice): void
    {
        $subtotal = InvoiceItem::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount');

        $discount = (float) $invoice->discount;
        $total = max($subtotal - $discount, 0);
        $paid = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->whereNull('voided_at')
            ->sum('amount');

        $invoice->update([
            'subtotal' => $subtotal,
            'total' => $total,
            'status' => $this->determineInvoiceStatus($invoice, $paid, $total),
        ]);
    }

    protected function determineInvoiceStatus(Invoice $invoice, float $paidAmount, float $invoiceTotal): string
    {
        if ($invoice->status === 'cancelled') {
            return 'cancelled';
        }

        if ($paidAmount <= 0) {
            return $invoice->status === 'draft' ? 'draft' : 'issued';
        }

        if ($paidAmount < $invoiceTotal) {
            return 'partial';
        }

        return 'paid';
    }
}
