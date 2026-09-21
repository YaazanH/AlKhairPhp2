<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlanManagementController extends Controller
{
    public function index(): View
    {
        return view('platform.plans.index', [
            'plans' => Plan::query()->with('features')->orderBy('name')->get(),
            'features' => Feature::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'features' => ['array'],
            'features.*' => ['string', 'exists:landlord.features,code'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $codes = collect($data['features'] ?? [])->push(Feature::CORE)->unique()->all();
        $featureIds = Feature::query()->whereIn('code', $codes)->pluck('id')->all();
        DB::connection('landlord')->transaction(function () use ($plan, $data, $featureIds): void {
            $lockedPlan = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $lockedPlan->update(['name' => $data['name'], 'is_active' => (bool) ($data['is_active'] ?? false)]);
            $lockedPlan->features()->sync($featureIds);
        });

        return back()->with('status', 'Package saved successfully.');
    }
}
