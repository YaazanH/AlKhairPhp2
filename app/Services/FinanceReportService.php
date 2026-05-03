<?php

namespace App\Services;

use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use Illuminate\Support\Carbon;

class FinanceReportService
{
    public function report(int $year, ?int $quarter = null): array
    {
        [$start, $end] = $this->period($year, $quarter);

        $transactions = FinanceTransaction::query()
            ->with(['cashBox', 'category', 'currency', 'activity', 'teacher'])
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->get();

        $income = (float) $transactions->where('local_amount', '>', 0)->sum('local_amount');
        $expense = abs((float) $transactions->where('local_amount', '<', 0)->sum('local_amount'));

        return [
            'period' => [
                'start' => $start,
                'end' => $end,
                'year' => $year,
                'quarter' => $quarter,
            ],
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'category_totals' => $transactions
                ->groupBy(fn (FinanceTransaction $transaction) => $transaction->category?->name ?: 'Uncategorized')
                ->map(fn ($rows, $category) => [
                    'category' => $category,
                    'income' => round((float) $rows->where('local_amount', '>', 0)->sum('local_amount'), 2),
                    'expense' => round(abs((float) $rows->where('local_amount', '<', 0)->sum('local_amount')), 2),
                    'net' => round((float) $rows->sum('local_amount'), 2),
                ])
                ->values(),
            'pending_pull_requests' => FinanceRequest::query()
                ->with(['activity', 'requestedBy', 'requestedCurrency'])
                ->where('type', FinanceRequest::TYPE_PULL)
                ->where('status', FinanceRequest::STATUS_PENDING)
                ->latest()
                ->limit(10)
                ->get(),
            'quarter_totals' => $this->quarterTotals($year),
            'summary' => [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'net' => round($income - $expense, 2),
                'transactions' => $transactions->count(),
            ],
            'transactions' => $transactions,
        ];
    }

    public function exportRows(int $year, ?int $quarter = null): array
    {
        return $this->report($year, $quarter)['transactions']
            ->map(fn (FinanceTransaction $transaction) => [
                $transaction->transaction_date?->format('Y-m-d'),
                $transaction->transaction_no,
                $transaction->cashBox?->name,
                $transaction->currency?->code,
                $transaction->type,
                $transaction->direction,
                (float) $transaction->amount,
                (float) $transaction->signed_amount,
                (float) $transaction->base_amount,
                (float) $transaction->local_amount,
                $transaction->category?->name,
                $transaction->activity?->title,
                $transaction->teacher ? trim($transaction->teacher->first_name.' '.$transaction->teacher->last_name) : null,
                $transaction->description,
            ])
            ->all();
    }

    protected function period(int $year, ?int $quarter): array
    {
        if ($quarter !== null) {
            $quarter = max(1, min(4, $quarter));
            $startMonth = (($quarter - 1) * 3) + 1;

            $start = Carbon::create($year, $startMonth, 1)->startOfDay();

            return [$start, $start->copy()->addMonths(3)->subDay()->endOfDay()];
        }

        return [
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }

    protected function quarterTotals(int $year): array
    {
        return collect([1, 2, 3, 4])
            ->map(function (int $quarter) use ($year) {
                [$start, $end] = $this->period($year, $quarter);
                $localTotal = (float) FinanceTransaction::query()
                    ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
                    ->sum('local_amount');

                return [
                    'quarter' => $quarter,
                    'net' => round($localTotal, 2),
                    'start' => $start,
                    'end' => $end,
                ];
            })
            ->all();
    }
}
