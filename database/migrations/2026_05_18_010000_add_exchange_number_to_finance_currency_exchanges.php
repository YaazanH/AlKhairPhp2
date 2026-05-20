<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_currency_exchanges', function (Blueprint $table): void {
            $table->string('exchange_no')->nullable()->after('pair_uuid');
        });

        $prefix = $this->normalizedPrefix(
            DB::table('app_settings')
                ->where('group', 'finance')
                ->where('key', 'exchange_prefix')
                ->value('value'),
            'EXC',
        );

        DB::table('finance_currency_exchanges')
            ->orderBy('id')
            ->get(['id', 'exchange_no'])
            ->each(function (object $exchange, int $index) use ($prefix): void {
                if (filled($exchange->exchange_no)) {
                    return;
                }

                DB::table('finance_currency_exchanges')
                    ->where('id', $exchange->id)
                    ->update([
                        'exchange_no' => sprintf('%s-%06d', $prefix, $index + 1),
                    ]);
            });

        Schema::table('finance_currency_exchanges', function (Blueprint $table): void {
            $table->unique('exchange_no');
        });
    }

    public function down(): void
    {
        Schema::table('finance_currency_exchanges', function (Blueprint $table): void {
            $table->dropUnique('finance_currency_exchanges_exchange_no_unique');
            $table->dropColumn('exchange_no');
        });
    }

    protected function normalizedPrefix(?string $value, string $default): string
    {
        $normalized = Str::upper(trim((string) preg_replace('/[\s-]+/u', '', (string) $value)));

        return $normalized !== '' ? $normalized : $default;
    }
};
