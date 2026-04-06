<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\AccessScopeService;
use Illuminate\Contracts\View\View;

class PrintController extends Controller
{
    /**
     * Render a printable invoice view.
     */
    public function invoice(Invoice $invoice): View
    {
        abort_unless(app(AccessScopeService::class)->canAccessInvoice(request()->user(), $invoice), 403);

        $invoice->load([
            'items.activity',
            'items.enrollment.group.course',
            'items.student',
            'parentProfile.students',
            'payments.paymentMethod',
            'payments.receivedBy',
        ]);

        $activePaidTotal = $invoice->payments
            ->whereNull('voided_at')
            ->sum('amount');

        return view('print.invoice', [
            'activePaidTotal' => (float) $activePaidTotal,
            'invoice' => $invoice,
            'organization' => $this->organizationProfile(),
        ]);
    }

    /**
     * Render a printable payment receipt.
     */
    public function receipt(Payment $payment): View
    {
        abort_unless(app(AccessScopeService::class)->canAccessInvoice(request()->user(), $payment->invoice), 403);

        $payment->load([
            'invoice.items.student',
            'invoice.parentProfile',
            'paymentMethod',
            'receivedBy',
            'voidedBy',
        ]);

        return view('print.receipt', [
            'organization' => $this->organizationProfile(),
            'payment' => $payment,
        ]);
    }

    protected function organizationProfile(): array
    {
        $settings = AppSetting::query()
            ->where('group', 'general')
            ->whereIn('key', ['school_address', 'school_email', 'school_name', 'school_phone'])
            ->pluck('value', 'key');

        return [
            'address' => (string) ($settings['school_address'] ?? ''),
            'email' => (string) ($settings['school_email'] ?? ''),
            'name' => (string) ($settings['school_name'] ?? config('app.name', 'Alkhair')),
            'phone' => (string) ($settings['school_phone'] ?? ''),
        ];
    }
}
