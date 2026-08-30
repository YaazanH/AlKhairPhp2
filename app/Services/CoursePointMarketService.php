<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePointMarketDepartment;
use App\Models\CoursePointMarketInvoice;
use App\Models\CoursePointMarketItem;
use App\Models\FinanceCurrency;
use App\Models\FinanceRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CoursePointMarketService
{
    public function eligibleInvoiceQuery(Course $course): Builder
    {
        [$from, $to] = $this->courseDateRange($course);

        $query = Invoice::query()
            ->where('invoice_type', 'finance')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereHas('financeRequest', fn (Builder $requestQuery) => $requestQuery
                ->whereIn('type', [FinanceRequest::TYPE_EXPENSE, FinanceRequest::TYPE_PULL])
                ->where('status', FinanceRequest::STATUS_SETTLED));

        if (! $from && ! $to) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when($from, fn (Builder $dateQuery) => $dateQuery->whereDate('issue_date', '>=', $from->toDateString()))
            ->when($to, fn (Builder $dateQuery) => $dateQuery->whereDate('issue_date', '<=', $to->toDateString()));
    }

    public function availableInvoices(Course $course): Collection
    {
        $addedIds = CoursePointMarketInvoice::query()
            ->where('course_id', $course->id)
            ->pluck('invoice_id');

        return $this->eligibleInvoiceQuery($course)
            ->whereNotIn('id', $addedIds)
            ->with([
                'financeRequest.acceptedCurrency',
                'financeRequest.category',
                'financeRequest.postedTransaction',
                'financeRequest.requestedCurrency',
            ])
            ->withCount('items')
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();
    }

    public function addInvoices(Course $course, array $invoiceIds, ?User $user): int
    {
        $invoiceIds = collect($invoiceIds)
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($invoiceIds->isEmpty()) {
            throw ValidationException::withMessages(['selectedInvoiceIds' => __('course_end.point_market.validation.select_invoice')]);
        }

        $eligibleIds = $this->eligibleInvoiceQuery($course)
            ->whereIn('id', $invoiceIds)
            ->pluck('id');

        if ($eligibleIds->count() !== $invoiceIds->count()) {
            throw ValidationException::withMessages(['selectedInvoiceIds' => __('course_end.point_market.validation.invoice_outside_course')]);
        }

        return DB::transaction(function () use ($course, $eligibleIds, $user): int {
            $added = 0;

            foreach ($eligibleIds as $invoiceId) {
                $link = CoursePointMarketInvoice::query()->firstOrCreate([
                    'course_id' => $course->id,
                    'invoice_id' => $invoiceId,
                ], [
                    'added_by' => $user?->id,
                ]);

                $added += $link->wasRecentlyCreated ? 1 : 0;
            }

            return $added;
        });
    }

    public function createDepartment(Course $course, string $name, ?User $user): CoursePointMarketDepartment
    {
        return CoursePointMarketDepartment::query()->create([
            'course_id' => $course->id,
            'name' => trim($name),
            'point_price' => 1,
            'created_by' => $user?->id,
        ]);
    }

    public function allocateItems(
        Course $course,
        Invoice $invoice,
        CoursePointMarketDepartment $department,
        array $invoiceItemIds,
        ?User $user,
    ): int {
        if ((int) $department->course_id !== (int) $course->id) {
            throw ValidationException::withMessages(['departmentId' => __('course_end.point_market.validation.invalid_department')]);
        }

        $pointMarketInvoice = CoursePointMarketInvoice::query()
            ->where('course_id', $course->id)
            ->where('invoice_id', $invoice->id)
            ->first();

        if (! $pointMarketInvoice) {
            throw ValidationException::withMessages(['selectedItemIds' => __('course_end.point_market.validation.invoice_not_added')]);
        }

        $invoiceItemIds = collect($invoiceItemIds)
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($invoiceItemIds->isEmpty()) {
            throw ValidationException::withMessages(['selectedItemIds' => __('course_end.point_market.validation.select_item')]);
        }

        return DB::transaction(function () use ($course, $department, $invoice, $invoiceItemIds, $pointMarketInvoice, $user): int {
            $invoice->loadMissing([
                'financeRequest.acceptedCurrency',
                'financeRequest.postedTransaction',
                'financeRequest.requestedCurrency',
            ]);

            $alreadyAllocatedIds = CoursePointMarketItem::query()
                ->where('course_id', $course->id)
                ->whereIn('invoice_item_id', $invoiceItemIds)
                ->lockForUpdate()
                ->pluck('invoice_item_id');

            $items = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('id', $invoiceItemIds->diff($alreadyAllocatedIds))
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['selectedItemIds' => __('course_end.point_market.validation.items_already_added')]);
            }

            $currency = $invoice->financeRequest?->acceptedCurrency
                ?: $invoice->financeRequest?->requestedCurrency
                ?: app(FinanceService::class)->localCurrency();
            $localCurrency = app(FinanceService::class)->localCurrency();
            $transaction = $invoice->financeRequest?->postedTransaction;
            $invoiceRateToBase = (float) ($transaction?->rate_to_base ?: $currency->rate_to_base);
            $localRateToBase = $this->localRateAtTransaction($transaction?->base_amount, $transaction?->local_amount, (float) $localCurrency->rate_to_base);

            foreach ($items as $item) {
                $localUnitPrice = $localRateToBase > 0
                    ? round(((float) $item->unit_price * $invoiceRateToBase) / $localRateToBase, 2)
                    : round((float) $item->unit_price, 2);

                CoursePointMarketItem::query()->create([
                    'course_id' => $course->id,
                    'course_point_market_department_id' => $department->id,
                    'invoice_id' => $invoice->id,
                    'invoice_item_id' => $item->id,
                    'invoice_sort_order' => $pointMarketInvoice->id,
                    'invoice_item_sort_order' => $item->line_no ?: $item->id,
                    'item_name' => $item->item_name ?: $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'currency_code' => $currency->code,
                    'currency_symbol' => $currency->symbol,
                    'currency_decimal_places' => $currency->decimal_places,
                    'local_unit_price' => $localUnitPrice,
                    'local_currency_code' => $localCurrency->code,
                    'local_currency_symbol' => $localCurrency->symbol,
                    'local_currency_decimal_places' => $localCurrency->decimal_places,
                    'added_by' => $user?->id,
                ]);
            }

            return $items->count();
        });
    }

    public function pointMarketInvoiceLinks(Course $course): Collection
    {
        return CoursePointMarketInvoice::query()
            ->where('course_id', $course->id)
            ->with([
                'invoice.financeRequest.acceptedCurrency',
                'invoice.financeRequest.category',
                'invoice.financeRequest.requestedCurrency',
                'invoice.items' => fn ($query) => $query->orderBy('line_no')->orderBy('id'),
            ])
            ->latest('id')
            ->get();
    }

    public function departments(Course $course): Collection
    {
        return CoursePointMarketDepartment::query()
            ->where('course_id', $course->id)
            ->with(['items' => fn ($query) => $query
                ->with('invoice.financeRequest')
                ->inInvoiceOrder()])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, CoursePointMarketDepartment>|null  $departments
     * @return array{
     *     exchange_rate: string,
     *     total_points_after_rules: int,
     *     departments_local_total: float,
     *     departments_base_total: float,
     *     local_currency: FinanceCurrency,
     *     base_currency: FinanceCurrency
     * }
     */
    public function summary(Course $course, ?Collection $departments = null): array
    {
        $departments ??= $this->departments($course);
        $financeService = app(FinanceService::class);
        $localCurrency = $financeService->localCurrency();
        $baseCurrency = $financeService->baseCurrency();
        $localTotal = round((float) $departments
            ->flatMap->items
            ->sum(fn (CoursePointMarketItem $item): float => (float) $item->quantity * (float) $item->local_unit_price), 2);
        $baseRate = (float) $baseCurrency->rate_to_base;
        $baseTotal = $baseRate > 0
            ? round(($localTotal * (float) $localCurrency->rate_to_base) / $baseRate, 2)
            : $localTotal;

        return [
            'exchange_rate' => $financeService->currencyRateLabel($localCurrency, $baseCurrency),
            'total_points_after_rules' => (int) app(CourseEndService::class)
                ->studentRows($course)
                ->sum('points_after'),
            'departments_local_total' => $localTotal,
            'departments_base_total' => $baseTotal,
            'local_currency' => $localCurrency,
            'base_currency' => $baseCurrency,
        ];
    }

    public function updatePointPrice(CoursePointMarketDepartment $department, float $pointPrice): void
    {
        $department->update(['point_price' => round($pointPrice, 2)]);
    }

    public function removeItem(Course $course, CoursePointMarketItem $item): void
    {
        if ((int) $item->course_id !== (int) $course->id) {
            throw ValidationException::withMessages([
                'departmentItems' => __('course_end.point_market.validation.invalid_item'),
            ]);
        }

        $item->delete();
    }

    /** @return array{0: ?CarbonInterface, 1: ?CarbonInterface} */
    public function courseDateRange(Course $course): array
    {
        $course->loadMissing('groups');

        $from = $course->starts_on ?: $course->groups->pluck('starts_on')->filter()->min();
        $to = $course->ends_on ?: $course->groups->pluck('ends_on')->filter()->max();

        return [$from, $to];
    }

    protected function localRateAtTransaction(mixed $baseAmount, mixed $localAmount, float $currentLocalRate): float
    {
        $base = abs((float) $baseAmount);
        $local = abs((float) $localAmount);

        return $base > 0 && $local > 0 ? $base / $local : $currentLocalRate;
    }
}
