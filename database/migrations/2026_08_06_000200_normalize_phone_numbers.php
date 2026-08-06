<?php

use App\Support\PhoneNumberFormatter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeUniqueUserPhones();
        $this->normalizeColumns('teachers', ['phone']);
        $this->normalizeColumns('parents', ['father_phone', 'mother_phone', 'home_phone']);
        $this->normalizeColumns('community_contacts', ['phone', 'secondary_phone']);

        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')
                ->where(function ($query): void {
                    $query->where(fn ($query) => $query->where('group', 'general')->where('key', 'school_phone'))
                        ->orWhere(fn ($query) => $query->where('group', 'website')->where('key', 'contact_phone'));
                })
                ->orderBy('id')
                ->eachById(function (object $setting): void {
                    $formatted = PhoneNumberFormatter::format($setting->value);

                    if ($formatted !== null) {
                        DB::table('app_settings')->where('id', $setting->id)->update(['value' => $formatted]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Phone normalization is intentionally irreversible.
    }

    protected function normalizeUniqueUserPhones(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'phone')) {
            return;
        }

        $phones = DB::table('users')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'phone'])
            ->map(fn (object $user): array => [
                'id' => (int) $user->id,
                'phone' => PhoneNumberFormatter::normalize($user->phone),
            ]);

        foreach ($phones as $phone) {
            DB::table('users')->where('id', $phone['id'])->update([
                'phone' => '__phone_normalizing_'.$phone['id'],
            ]);
        }

        $seen = [];
        foreach ($phones as $phone) {
            $normalized = $phone['phone'];

            // A unique user-phone column cannot retain two textual variants of the same number.
            if ($normalized !== null && isset($seen[$normalized])) {
                $normalized = null;
            }

            if ($normalized !== null) {
                $seen[$normalized] = true;
            }

            DB::table('users')->where('id', $phone['id'])->update(['phone' => $normalized]);
        }
    }

    /** @param list<string> $columns */
    protected function normalizeColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->eachById(function (object $row) use ($table, $columns): void {
                $updates = [];

                foreach ($columns as $column) {
                    if (! filled($row->{$column} ?? null)) {
                        continue;
                    }

                    $normalized = PhoneNumberFormatter::normalize($row->{$column});
                    if ($normalized !== null) {
                        $updates[$column] = $normalized;
                    }
                }

                if ($updates !== []) {
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }, 200, 'id', 'id');
    }
};
