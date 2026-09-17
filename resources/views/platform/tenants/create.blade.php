@include('platform.tenants.form', ['tenant' => null, 'plans' => \App\Models\Landlord\Plan::query()->where('is_active', true)->orderBy('name')->get()])
