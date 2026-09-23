<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Collection;

class InvoiceOwnershipService
{
    public const FINANCE = 'finance';

    public const STUDENT = 'student';

    public const INFERABLE = 'inferable';

    public const MIXED = 'mixed';

    public const UNLINKED = 'unlinked';

    public function classify(Invoice $invoice): array
    {
        if ($invoice->invoice_type === self::FINANCE || $invoice->finance_request_id !== null) {
            return ['classification' => self::FINANCE, 'student_id' => null];
        }

        if ($invoice->student_id !== null) {
            return ['classification' => self::STUDENT, 'student_id' => (int) $invoice->student_id];
        }

        $studentIds = $this->itemStudentIds($invoice);

        return match ($studentIds->count()) {
            0 => ['classification' => self::UNLINKED, 'student_id' => null],
            1 => ['classification' => self::INFERABLE, 'student_id' => (int) $studentIds->first()],
            default => ['classification' => self::MIXED, 'student_id' => null],
        };
    }

    public function isStudentBilling(Invoice $invoice): bool
    {
        return $this->classify($invoice)['classification'] !== self::FINANCE;
    }

    private function itemStudentIds(Invoice $invoice): Collection
    {
        return $invoice->items()
            ->with('enrollment:id,student_id')
            ->get(['id', 'invoice_id', 'student_id', 'enrollment_id'])
            ->map(fn ($item) => $item->student_id ?: $item->enrollment?->student_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
