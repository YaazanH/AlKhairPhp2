<?php

namespace App\Services\Landlord;

use App\Models\AppSetting;
use App\Models\Landlord\Tenant;
use App\Support\ApplicationTimezone;
use DateTimeZone;
use DomainException;
use Illuminate\Support\Facades\DB;

class TenantSetupManager
{
    public const GROUP = 'onboarding';

    public function __construct(
        private ModuleRegistry $registry,
        private TenantModuleAccess $moduleAccess,
    ) {}

    public function initialiseNewTenant(?Tenant $tenant = null): void
    {
        AppSetting::storeValue(self::GROUP, 'managed', true, 'boolean');
        AppSetting::storeValue(self::GROUP, 'modules', [], 'json');

        if ($tenant !== null) {
            AppSetting::storeValue('general', 'school_name', $tenant->name);
            AppSetting::storeValue('general', 'school_timezone', app(ApplicationTimezone::class)->normalize(
                (string) ($tenant->timezone ?: config('app.timezone')),
            ));
            AppSetting::storeValue('general', 'default_locale', $tenant->locale ?: config('app.locale', 'ar'));
        }
    }

    public function summary(Tenant $tenant): array
    {
        $enabled = $this->moduleAccess->snapshot($tenant)['enabled'];
        $states = (array) AppSetting::groupValues(self::GROUP)->get('modules', []);
        $managed = AppSetting::query()
            ->where('group', self::GROUP)
            ->where('key', 'managed')
            ->first()?->castValue();

        // Tenants created before M8 keep working. Their currently enabled modules
        // become the baseline; a module enabled later is still detected as new.
        if ($managed === null) {
            $this->inferExistingFoundation($tenant);
            foreach ($enabled as $code) {
                $states[$code] = $this->readyState($code, 'inferred');
            }
            $this->storeStates($states);
            AppSetting::storeValue(self::GROUP, 'managed', true, 'boolean');
        }

        $modules = [];
        foreach ($enabled as $code) {
            $definition = $this->definition($code);
            $saved = (array) ($states[$code] ?? []);
            $currentVersion = (int) $definition['version'];
            $savedVersion = (int) ($saved['version'] ?? 0);
            $status = $savedVersion === $currentVersion
                ? (string) ($saved['status'] ?? 'not_started')
                : 'not_started';
            if ($code === 'foundation' && $status === 'ready' && ! $this->foundationIsReady()) {
                $status = 'not_started';
            }
            if (! in_array($status, ['ready', 'skipped', 'not_started'], true)) {
                $status = 'not_started';
            }
            $modules[$code] = [
                'code' => $code,
                'name' => $this->registry->definitions()[$code]['name'] ?? $code,
                'version' => $currentVersion,
                'saved_version' => $savedVersion,
                'status' => $status,
                'required' => (bool) ($definition['required'] ?? false),
                'settings_route' => $definition['settings_route'] ?? null,
                'is_new_version' => $savedVersion > 0 && $savedVersion !== $currentVersion,
            ];
        }

        $pending = array_values(array_filter($modules, fn (array $module): bool => ! in_array($module['status'], ['ready', 'skipped'], true)));
        $required = array_values(array_filter($pending, fn (array $module): bool => $module['required']));
        $status = $required ? 'required' : ($pending ? 'attention' : 'ready');
        $version = hash('sha256', json_encode([$enabled, $modules], JSON_THROW_ON_ERROR));

        return compact('status', 'version', 'modules', 'pending', 'required');
    }

    public function saveFoundation(Tenant $tenant, array $data): array
    {
        abort_unless(in_array('foundation', $this->moduleAccess->snapshot($tenant)['enabled'], true), 403);

        DB::transaction(function () use ($data): void {
            AppSetting::storeValue('general', 'school_name', trim($data['school_name']));
            AppSetting::storeValue('general', 'school_timezone', $data['school_timezone']);
            AppSetting::storeValue('general', 'default_locale', $data['default_locale']);
            // Keep the older readers in sync while normal settings converge on general.*.
            AppSetting::storeValue('app', 'school_name', trim($data['school_name']));
            AppSetting::storeValue('app', 'timezone', $data['school_timezone']);
            $this->setState('foundation', 'ready');
        });
        $tenant->update(['name' => trim($data['school_name'])]);
        app(ApplicationTimezone::class)->apply($data['school_timezone']);

        return $this->summary($tenant);
    }

    public function mark(Tenant $tenant, string $code, string $status): array
    {
        if ($code === 'foundation' || ! in_array($code, $this->moduleAccess->snapshot($tenant)['enabled'], true)) {
            throw new DomainException('This setup step is not available.');
        }
        if (! in_array($status, ['ready', 'skipped'], true)) {
            throw new DomainException('Invalid setup status.');
        }

        $this->setState($code, $status);

        return $this->summary($tenant);
    }

    public function skipOptional(Tenant $tenant): array
    {
        $summary = $this->summary($tenant);
        if ($summary['required']) {
            throw new DomainException('Complete the required organisation step first.');
        }
        foreach ($summary['pending'] as $module) {
            if (! $module['required']) {
                $this->setState($module['code'], 'skipped');
            }
        }

        return $this->summary($tenant);
    }

    private function setState(string $code, string $status): void
    {
        $states = (array) AppSetting::groupValues(self::GROUP)->get('modules', []);
        $states[$code] = [
            'version' => (int) $this->definition($code)['version'],
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
        ];
        $this->storeStates($states);
    }

    private function readyState(string $code, string $source): array
    {
        return [
            'version' => (int) $this->definition($code)['version'],
            'status' => 'ready',
            'source' => $source,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function storeStates(array $states): void
    {
        ksort($states);
        AppSetting::storeValue(self::GROUP, 'modules', $states, 'json');
    }

    private function inferExistingFoundation(Tenant $tenant): void
    {
        $general = AppSetting::groupValues('general');
        $app = AppSetting::groupValues('app');
        AppSetting::storeValue('general', 'school_name', $general->get('school_name') ?: ($app->get('school_name') ?: $tenant->name));
        AppSetting::storeValue('general', 'school_timezone', app(ApplicationTimezone::class)->normalize(
            (string) ($general->get('school_timezone') ?: ($app->get('timezone') ?: config('app.timezone'))),
        ));
        AppSetting::storeValue('general', 'default_locale', $general->get('default_locale') ?: config('app.locale', 'ar'));
    }

    private function foundationIsReady(): bool
    {
        $settings = AppSetting::groupValues('general');

        return filled($settings->get('school_name'))
            && in_array($settings->get('default_locale'), array_keys(config('app.supported_locales', [])), true)
            && in_array($settings->get('school_timezone'), DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    private function definition(string $code): array
    {
        return config('modules.setup.'.$code, ['version' => 1]);
    }
}
