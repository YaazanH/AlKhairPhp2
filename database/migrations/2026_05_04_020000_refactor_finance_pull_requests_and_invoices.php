<?php

use App\Models\FinanceRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_pull_request_kinds', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('mode', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('finance_invoice_kinds', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        DB::table('finance_pull_request_kinds')->insert([
            [
                'name' => 'Count request',
                'code' => 'count',
                'mode' => 'count',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Invoice request',
                'code' => 'invoice',
                'mode' => 'invoice',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('finance_invoice_kinds')->insert([
            [
                'name' => 'General invoice',
                'code' => 'general',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('finance_pull_request_kind_id')->nullable()->after('status')->index();
            $table->unsignedInteger('requested_count')->nullable()->after('requested_amount');
            $table->unsignedInteger('accepted_count')->nullable()->after('accepted_amount');
            $table->unsignedInteger('final_count')->nullable()->after('accepted_count');
            $table->decimal('remaining_amount', 12, 2)->nullable()->after('final_count');
            $table->unsignedBigInteger('invoice_id')->nullable()->after('posted_transaction_id')->index();
            $table->unsignedBigInteger('return_transaction_id')->nullable()->after('invoice_id')->index();
            $table->unsignedBigInteger('closing_transaction_id')->nullable()->after('return_transaction_id')->index();
            $table->timestamp('settled_at')->nullable()->after('declined_at');
            $table->unsignedBigInteger('settled_by')->nullable()->after('settled_at')->index();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('finance_invoice_kind_id')->nullable()->after('invoice_type')->index();
            $table->unsignedBigInteger('finance_request_id')->nullable()->after('finance_invoice_kind_id')->index();
            $table->string('invoicer_name')->nullable()->after('invoice_no');
        });

        $this->makeInvoiceParentNullable();

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->unsignedInteger('line_no')->nullable()->after('invoice_id');
            $table->string('item_name')->nullable()->after('line_no');
        });

        $countKindId = DB::table('finance_pull_request_kinds')->where('mode', 'count')->value('id');
        $invoiceKindId = DB::table('finance_invoice_kinds')->where('code', 'general')->value('id');

        DB::table('finance_requests')
            ->where('type', FinanceRequest::TYPE_PULL)
            ->whereNull('finance_pull_request_kind_id')
            ->update(['finance_pull_request_kind_id' => $countKindId]);

        DB::table('invoices')
            ->whereNull('finance_invoice_kind_id')
            ->update(['finance_invoice_kind_id' => $invoiceKindId]);

        DB::table('invoices')
            ->whereNull('invoicer_name')
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $parentName = $invoice->parent_id
                        ? DB::table('parents')->where('id', $invoice->parent_id)->value('father_name')
                        : null;

                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->update(['invoicer_name' => $parentName ?: $invoice->invoice_no]);
                }
            });

        DB::table('invoice_items')
            ->whereNull('item_name')
            ->update(['item_name' => DB::raw('description')]);

        DB::table('invoices')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $line = 1;

                    DB::table('invoice_items')
                        ->where('invoice_id', $invoice->id)
                        ->orderBy('id')
                        ->get(['id'])
                        ->each(function ($item) use (&$line): void {
                            DB::table('invoice_items')
                                ->where('id', $item->id)
                                ->whereNull('line_no')
                                ->update(['line_no' => $line++]);
                        });
                }
            });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['line_no', 'item_name']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['finance_invoice_kind_id', 'finance_request_id', 'invoicer_name']);
        });

        Schema::table('finance_requests', function (Blueprint $table): void {
            $table->dropColumn('finance_pull_request_kind_id');
            $table->dropColumn([
                'requested_count',
                'accepted_count',
                'final_count',
                'remaining_amount',
            ]);
            $table->dropColumn(['invoice_id', 'return_transaction_id', 'closing_transaction_id', 'settled_by']);
            $table->dropColumn('settled_at');
        });

        Schema::dropIfExists('finance_invoice_kinds');
        Schema::dropIfExists('finance_pull_request_kinds');
    }

    private function makeInvoiceParentNullable(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->unsignedBigInteger('parent_id')->nullable()->change();
            });

            return;
        }

        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement(<<<'SQL'
CREATE TABLE invoices_new (
    id integer primary key autoincrement not null,
    parent_id integer null,
    invoice_no varchar not null,
    invoicer_name varchar null,
    invoice_type varchar not null default 'tuition',
    finance_invoice_kind_id integer null,
    finance_request_id integer null,
    issue_date date not null,
    due_date date null,
    status varchar not null default 'draft',
    subtotal numeric not null default '0',
    discount numeric not null default '0',
    total numeric not null default '0',
    notes text null,
    created_at datetime null,
    updated_at datetime null,
    deleted_at datetime null
)
SQL);

        DB::statement(<<<'SQL'
INSERT INTO invoices_new (
    id,
    parent_id,
    invoice_no,
    invoicer_name,
    invoice_type,
    finance_invoice_kind_id,
    finance_request_id,
    issue_date,
    due_date,
    status,
    subtotal,
    discount,
    total,
    notes,
    created_at,
    updated_at,
    deleted_at
)
SELECT
    id,
    parent_id,
    invoice_no,
    invoicer_name,
    invoice_type,
    finance_invoice_kind_id,
    finance_request_id,
    issue_date,
    due_date,
    status,
    subtotal,
    discount,
    total,
    notes,
    created_at,
    updated_at,
    deleted_at
FROM invoices
SQL);

        DB::statement('DROP TABLE invoices');
        DB::statement('ALTER TABLE invoices_new RENAME TO invoices');
        DB::statement('CREATE UNIQUE INDEX invoices_invoice_no_unique ON invoices (invoice_no)');
        DB::statement('CREATE INDEX invoices_parent_id_index ON invoices (parent_id)');
        DB::statement('CREATE INDEX invoices_finance_invoice_kind_id_index ON invoices (finance_invoice_kind_id)');
        DB::statement('CREATE INDEX invoices_finance_request_id_index ON invoices (finance_request_id)');
        DB::statement('PRAGMA foreign_keys=ON');
    }
};
