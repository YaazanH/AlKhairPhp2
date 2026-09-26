@props(['profile', 'editing' => false])
@if (! $editing && auth()->user()?->can('users.update'))
    <div class="admin-form-field" data-existing-profile-account="{{ $profile }}">
        <label for="{{ $profile }}-existing-account">{{ __('access.profile_accounts.existing_label') }}</label>
        <select id="{{ $profile }}-existing-account" wire:model.live="existingAccountId" data-search-input="true" data-open-on-focus="true">
            <option value="">{{ __('access.profile_accounts.new_login') }}</option>
            @foreach ($this->availableExistingAccounts($profile) as $account)
                <option value="{{ $account->id }}">{{ $account->name }} — {{ $account->username }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-neutral-400">{{ __('access.profile_accounts.existing_help') }}</p>
        @error('existingAccountId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
    </div>
@endif
