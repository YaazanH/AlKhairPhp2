<?php

namespace App\Support;

use App\Models\Landlord\PlatformAdministrator;

class PlatformWorkspaceNavigation
{
    public function homeRoute(?PlatformAdministrator $user): string
    {
        if (! $user) {
            return 'platform.dashboard';
        }

        foreach ($this->groups($user) as $items) {
            return $items[0][2];
        }

        return 'platform.settings.index';
    }

    public function groups(PlatformAdministrator $user): array
    {
        $groups = [
            'workspace' => [
                ['overview', 'squares-2x2', 'platform.dashboard', ['platform.dashboard'], ['view.dashboard']],
                ['tenants', 'building-office', 'platform.tenants.index', ['platform.tenants.*'], ['view.tenants', 'manage.tenants']],
                ['packages', 'building-office-2', 'platform.plans.index', ['platform.plans.*'], ['manage.plans']],
                ['requests', 'chat-bubble-left-right', 'platform.support.index', ['platform.support.index', 'platform.support.show', 'platform.support.groups', 'platform.support.attachments.*', 'platform.enquiries.*'], ['manage.support.problems', 'manage.support.suggestions', 'manage.landing-page']],
            ],
            'billing' => [
                ['subscriptions', 'credit-card', 'platform.billing.index', ['platform.billing.index'], ['view.subscriptions', 'manage.subscriptions']],
                ['payments', 'banknotes', 'platform.billing.payments', ['platform.billing.payments'], ['view.subscriptions', 'manage.subscriptions']],
                ['vouchers', 'ticket', 'platform.vouchers.index', ['platform.vouchers.*'], ['view.subscriptions', 'manage.subscriptions']],
            ],
            'operations' => [
                ['metrics', 'chart-bar', 'platform.metrics', ['platform.metrics'], ['view.dashboard']],
                ['storage', 'circle-stack', 'platform.storage.index', ['platform.storage.*'], ['view.storage']],
                ['backups', 'archive-box', 'platform.backups.index', ['platform.backups.*'], ['view.backups']],
            ],
            'content' => [
                ['website', 'globe-alt', 'platform.landing.edit', ['platform.landing.*'], ['manage.landing-page', 'publish.landing-page']],
                ['reports', 'chart-bar-square', 'platform.report-library.index', ['platform.report-library.*'], ['manage.report-library', 'publish.report-library']],
            ],
            'administration' => [
                ['access', 'users', 'platform.access.index', ['platform.access.*'], ['manage.platform-users']],
                ['settings', 'cog-6-tooth', 'platform.settings.index', ['platform.settings.*', 'platform.subscription-settings.*', 'platform.support.settings.*'], []],
            ],
        ];

        return collect($groups)->map(fn ($items) => array_values(array_filter($items,
            fn ($item) => $item[4] === [] || collect($item[4])->contains(fn ($permission) => $user->hasPlatformPermission($permission))
        )))->filter()->all();
    }
}
