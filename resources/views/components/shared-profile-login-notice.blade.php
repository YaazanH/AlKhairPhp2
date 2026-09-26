@props(['user' => null, 'profile'])
@if ($user && app(\App\Services\ManagedUserService::class)->isSharedAccount($user, $profile))
    <p class="soft-callout p-3 text-sm" data-shared-profile-login>{{ __('access.profile_accounts.shared_help') }}</p>
@endif
