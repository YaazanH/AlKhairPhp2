<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceOwnershipService;
use Illuminate\Console\Command;

class ClassifyInvoiceOwnership extends Command
{
    protected $signature = 'saas:classify-invoices {--apply-unambiguous : Assign the only item student to safe legacy invoices}';

    protected $description = 'Classify legacy invoices before enabling student-owned billing';

    public function handle(InvoiceOwnershipService $ownership): int
    {
        $counts = array_fill_keys([
            InvoiceOwnershipService::FINANCE,
            InvoiceOwnershipService::STUDENT,
            InvoiceOwnershipService::INFERABLE,
            InvoiceOwnershipService::MIXED,
            InvoiceOwnershipService::UNLINKED,
        ], 0);
        $blockers = [];

        Invoice::query()->orderBy('id')->each(function (Invoice $invoice) use (&$blockers, &$counts, $ownership): void {
            $result = $ownership->classify($invoice);
            $counts[$result['classification']]++;

            if ($result['classification'] === InvoiceOwnershipService::INFERABLE && $this->option('apply-unambiguous')) {
                $invoice->update(['student_id' => $result['student_id']]);
            }

            if (in_array($result['classification'], [InvoiceOwnershipService::MIXED, InvoiceOwnershipService::UNLINKED], true)) {
                $blockers[] = [$invoice->id, $invoice->invoice_no, $result['classification']];
            }
        });

        $this->table(['Classification', 'Count'], collect($counts)->map(fn ($count, $name) => [$name, $count])->values()->all());
        if ($blockers !== []) {
            $this->warn('These invoices require a manual ownership decision:');
            $this->table(['ID', 'Invoice number', 'Reason'], $blockers);
        }

        $this->info($this->option('apply-unambiguous') ? 'Safe ownership links were applied.' : 'Read-only preview complete.');

        return self::SUCCESS;
    }
}
