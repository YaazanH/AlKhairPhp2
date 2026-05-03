<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\FinanceRequest;
use Illuminate\Contracts\View\View;

class FinanceRequestPrintController extends Controller
{
    public function __invoke(FinanceRequest $financeRequest): View
    {
        abort_unless(
            match ($financeRequest->type) {
                'pull' => request()->user()?->can('finance.pull-requests.print'),
                'expense' => request()->user()?->can('finance.expense-requests.print'),
                default => request()->user()?->can('finance.revenue-requests.print'),
            },
            403,
        );

        abort_unless($financeRequest->status === FinanceRequest::STATUS_ACCEPTED, 404);

        $financeRequest->load(['activity', 'cashBox', 'category', 'requestedBy', 'reviewedBy', 'teacher', 'requestedCurrency', 'acceptedCurrency']);

        return view('print.finance-request', [
            'organization' => $this->organizationProfile(),
            'request' => $financeRequest,
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
