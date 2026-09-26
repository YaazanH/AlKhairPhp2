@props(['user'])
<button type="button" wire:click="viewLinkedAccount({{ $user->id }})" class="admin-icon-button" title="{{ __('access.profile_accounts.view_account') }}" aria-label="{{ __('access.profile_accounts.view_account') }}" data-user-profile-view="{{ $user->id }}">
    <x-admin-action-icon name="account" />
</button>
