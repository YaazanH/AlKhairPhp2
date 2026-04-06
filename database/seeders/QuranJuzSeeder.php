<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuranJuzSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();

        DB::table('quran_juzs')->upsert([
            ['juz_number' => 1, 'from_page' => 1, 'to_page' => 21, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 2, 'from_page' => 22, 'to_page' => 41, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 3, 'from_page' => 42, 'to_page' => 61, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 4, 'from_page' => 62, 'to_page' => 81, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 5, 'from_page' => 82, 'to_page' => 101, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 6, 'from_page' => 102, 'to_page' => 121, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 7, 'from_page' => 122, 'to_page' => 141, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 8, 'from_page' => 142, 'to_page' => 161, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 9, 'from_page' => 162, 'to_page' => 181, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 10, 'from_page' => 182, 'to_page' => 201, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 11, 'from_page' => 202, 'to_page' => 221, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 12, 'from_page' => 222, 'to_page' => 241, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 13, 'from_page' => 242, 'to_page' => 261, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 14, 'from_page' => 262, 'to_page' => 281, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 15, 'from_page' => 282, 'to_page' => 301, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 16, 'from_page' => 302, 'to_page' => 321, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 17, 'from_page' => 322, 'to_page' => 341, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 18, 'from_page' => 342, 'to_page' => 361, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 19, 'from_page' => 362, 'to_page' => 381, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 20, 'from_page' => 382, 'to_page' => 401, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 21, 'from_page' => 402, 'to_page' => 421, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 22, 'from_page' => 422, 'to_page' => 441, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 23, 'from_page' => 442, 'to_page' => 461, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 24, 'from_page' => 462, 'to_page' => 481, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 25, 'from_page' => 482, 'to_page' => 501, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 26, 'from_page' => 502, 'to_page' => 521, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 27, 'from_page' => 522, 'to_page' => 541, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 28, 'from_page' => 542, 'to_page' => 561, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 29, 'from_page' => 562, 'to_page' => 581, 'created_at' => $now, 'updated_at' => $now],
            ['juz_number' => 30, 'from_page' => 582, 'to_page' => 604, 'created_at' => $now, 'updated_at' => $now],
        ], ['juz_number'], ['from_page', 'to_page', 'updated_at']);
    }
}
