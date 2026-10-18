<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use App\Support\MobileUserData;
use App\Support\OperationalFeatureSettings;

class MobileBootstrapService
{
    public function __construct(
        protected MobileNavigationService $navigation,
        protected WebsiteService $website,
    ) {}

    public function for(User $user): array
    {
        $general = AppSetting::groupValues('general');
        $site = $this->website->siteSettings();
        $permissions = $user->getAllPermissions()->pluck('name')
            ->filter(fn (string $permission): bool => app(CurrentModuleAccess::class)->permissionAvailable($permission))
            ->sort()->values()->all();
        $supportedLocales = collect(config('app.supported_locales', []))
            ->map(fn (array $locale, string $code): array => [
                'code' => $code,
                'name' => $locale['name'] ?? $code,
                'native' => $locale['native'] ?? $code,
                'direction' => $locale['direction'] ?? 'ltr',
            ])->values()->all();

        return [
            'api' => [
                'version' => 'v1',
                'base_url' => url('/api/v1'),
            ],
            'user' => MobileUserData::from($user, $permissions),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $permissions,
            'navigation' => $this->navigation->for($user),
            'organization' => [
                'name' => $site['site_name'],
                'phone' => $site['contact_phone'],
                'email' => $site['contact_email'],
                'address' => $site['contact_address'],
                'logo_url' => filled($site['logo_url']) ? url($site['logo_url']) : null,
                'primary_color' => $site['primary_color'],
                'accent_color' => $site['accent_color'],
                'timezone' => $general->get('school_timezone') ?: config('app.timezone'),
            ],
            'localization' => [
                'locale' => app()->getLocale(),
                'fallback_locale' => config('app.fallback_locale'),
                'supported_locales' => $supportedLocales,
            ],
            'features' => [
                'activities' => app(CurrentModuleAccess::class)->enabled('activities') && OperationalFeatureSettings::activitiesEnabled(),
                'parent_portal' => (bool) $user->parentProfile && app(CurrentModuleAccess::class)->enabled('parent_portal'),
                'profile_photo_upload' => true,
            ],
        ];
    }
}
