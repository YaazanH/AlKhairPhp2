@include('platform.tenants.form', ['plans' => \App\Models\Landlord\Plan::query()->where('is_active', true)->orderBy('name')->get()])
