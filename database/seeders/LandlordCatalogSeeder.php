<?php

namespace Database\Seeders;

use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use Illuminate\Database\Seeder;

class LandlordCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $core = Feature::query()->updateOrCreate(
            ['code' => Feature::CORE],
            [
                'name' => 'Core',
                'description' => 'Core education and organisation management.',
                'is_core' => true,
                'is_active' => true,
            ],
        );

        $finance = Feature::query()->updateOrCreate(
            ['code' => Feature::FINANCE],
            [
                'name' => 'Finance',
                'description' => 'Finance workflows and reports.',
                'is_core' => false,
                'is_active' => true,
            ],
        );

        $customPrinting = Feature::query()->updateOrCreate(
            ['code' => Feature::CUSTOM_PRINTING],
            [
                'name' => 'Custom Printing',
                'description' => 'Custom print templates and branded layouts.',
                'is_core' => false,
                'is_active' => true,
            ],
        );

        $plans = [
            ['code' => 'core', 'name' => 'Core', 'features' => [$core]],
            ['code' => 'core_finance', 'name' => 'Core + Finance', 'features' => [$core, $finance]],
            ['code' => 'core_finance_printing', 'name' => 'Core + Finance + Custom Printing', 'features' => [$core, $finance, $customPrinting]],
        ];

        foreach ($plans as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'is_active' => true,
                ],
            );

            $plan->features()->sync(collect($definition['features'])->pluck('id')->all());
        }
    }
}
