<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\Feature;
use App\Models\Landlord\Plan;
use App\Models\Landlord\PlatformAuditEvent;
use App\Services\Landlord\PlanModuleManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlanManagementController extends Controller
{
    public function __construct(private PlanModuleManager $plans) {}

    public function index(): View
    {
        return view('platform.plans.index', [
            'plans' => Plan::query()->with('features')->withCount('subscriptions')->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('platform.plans.form', $this->formPayload(new Plan(['is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePlan($request);
        try {
            $plan = $this->plans->create([
                'code' => $data['code'], 'name' => $data['name'],
                'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? false), 'price_syp' => (int) ($data['price_syp'] ?? 0), 'billing_period_days' => (int) ($data['billing_period_days'] ?? 30),
            ], $data['modules'] ?? [], $request->user('platform'), $request->ip());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['modules' => $exception->getMessage()]);
        }

        return redirect()->route('platform.plans.edit', $plan)->with('status', 'Package created successfully.');
    }

    public function edit(Plan $plan): View
    {
        return view('platform.plans.form', $this->formPayload($plan));
    }

    public function preview(Request $request, Plan $plan): View|RedirectResponse
    {
        $data = $this->validatePlan($request, $plan);
        try {
            $preview = $this->plans->preview($plan, $data['modules'] ?? []);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['modules' => $exception->getMessage()]);
        }
        $request->session()->put('platform.plan_preview.'.$plan->id, $preview['signature']);

        return view('platform.plans.form', $this->formPayload($plan, $preview, $data));
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validatePlan($request, $plan);
        if ($request->has('features') && ! $request->has('modules')) {
            $codes = collect($data['features'] ?? [])->push('core')->unique();
            $featureIds = Feature::query()->whereIn('code', $codes)->pluck('id')->all();
            DB::connection('landlord')->transaction(function () use ($plan, $data, $featureIds, $request, $codes): void {
                $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
                $before = $locked->features()->pluck('code')->all();
                $locked->update(['name' => $data['name'], 'is_active' => (bool) ($data['is_active'] ?? false)]);
                $locked->features()->sync($featureIds);
                PlatformAuditEvent::query()->create([
                    'uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id,
                    'event' => 'plan_updated', 'properties' => ['plan_id' => $locked->id, 'plan_code' => $locked->code, 'before' => $before, 'after' => $codes->values()->all()],
                    'ip_address' => $request->ip(),
                ]);
            });

            return back()->with('status', 'Package saved successfully.');
        }
        try {
            $preview = $this->plans->preview($plan, $data['modules'] ?? []);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['modules' => $exception->getMessage()]);
        }

        $changed = collect($this->plans->selectedCodes($plan))->sort()->values()->all()
            !== collect($preview['selected'])->sort()->values()->all();
        if ($changed && $preview['tenants']->isNotEmpty()) {
            $previewed = (string) $request->session()->get('platform.plan_preview.'.$plan->id, '');
            if (! hash_equals($preview['signature'], $previewed) || ! $request->boolean('confirm_impact')) {
                return back()->withInput()->withErrors(['confirm_impact' => 'Preview and confirm the impact on assigned tenants before saving.']);
            }
        }

        $this->plans->update($plan, [
            'name' => $data['name'], 'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false), 'price_syp' => (int) ($data['price_syp'] ?? 0), 'billing_period_days' => (int) ($data['billing_period_days'] ?? 30),
        ], $data['modules'] ?? [], $request->user('platform'), $request->ip());
        $request->session()->forget('platform.plan_preview.'.$plan->id);

        return redirect()->route('platform.plans.edit', $plan)->with('status', 'Package saved successfully.');
    }

    public function duplicate(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $copy = $this->plans->duplicate($plan, $data['name'], $this->uniqueCode($data['name']), $request->user('platform'), $request->ip());

        return redirect()->route('platform.plans.edit', $copy)->with('status', 'Package duplicated successfully.');
    }

    public function setStatus(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::connection('landlord')->transaction(function () use ($request, $plan, $data): void {
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);
            $before = $locked->is_active;
            $locked->update(['is_active' => (bool) $data['is_active']]);
            PlatformAuditEvent::query()->create([
                'uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id,
                'event' => 'plan_status_updated',
                'properties' => ['plan_id' => $locked->id, 'plan_code' => $locked->code, 'before' => $before, 'after' => $locked->is_active],
                'ip_address' => $request->ip(),
            ]);
        });
        $plan->refresh();

        return back()->with('status', $plan->is_active ? 'Package activated.' : 'Package deactivated. Existing tenants keep their assignment.');
    }

    public function destroy(Request $request, Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->exists()) {
            return back()->withErrors(['delete' => 'An assigned package cannot be deleted. Deactivate it instead.']);
        }
        DB::connection('landlord')->transaction(function () use ($request, $plan): void {
            PlatformAuditEvent::query()->create([
                'uuid' => (string) Str::uuid(), 'platform_administrator_id' => $request->user('platform')->id,
                'event' => 'plan_deleted', 'properties' => ['plan_id' => $plan->id, 'plan_code' => $plan->code, 'name' => $plan->name],
                'ip_address' => $request->ip(),
            ]);
            $plan->delete();
        });

        return redirect()->route('platform.plans.index')->with('status', 'Package deleted.');
    }

    private function formPayload(Plan $plan, ?array $preview = null, array $input = []): array
    {
        $plan->loadMissing(['features', 'subscriptions.tenant']);

        return [
            'plan' => $plan, 'catalog' => $this->plans->catalog(),
            'selectedModules' => $input['modules'] ?? ($plan->exists ? $this->plans->selectedCodes($plan) : []),
            'preview' => $preview, 'submitted' => $input,
        ];
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        return $request->validate([
            'code' => [$plan?->exists ? 'sometimes' : 'required', 'alpha_dash', 'max:80', Rule::unique('landlord.plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'price_syp' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'billing_period_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'modules' => ['array'], 'modules.*' => ['string'], 'is_active' => ['nullable', 'boolean'],
            'confirm_impact' => ['nullable', 'boolean'],
            'features' => ['array'], 'features.*' => ['string', 'exists:landlord.features,code'],
        ], [
            'code.required' => 'Enter a short code for this package.',
            'code.alpha_dash' => 'The package code may contain only letters, numbers, dashes, and underscores.',
            'code.unique' => 'This package code is already in use. Choose a different code.',
            'name.required' => 'Enter a name for this package.',
            'modules.array' => 'The selected modules could not be read. Refresh the page and try again.',
            'is_active.boolean' => 'The package availability value is invalid.',
        ], [
            'code' => 'package code',
            'name' => 'package name',
            'description' => 'package description',
            'modules' => 'included modules',
        ]);
    }

    private function uniqueCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'package';
        $code = $base;
        $suffix = 2;
        while (Plan::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
    }
}
