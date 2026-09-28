<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('password_change.title')" :description="__('password_change.description')" />

        <form method="POST" action="{{ route('password.change-required.update') }}" class="flex flex-col gap-6">
            @csrf
            @method('PUT')

            <div>
                <flux:input :label="__('password_change.current_password')" type="password" name="current_password" required autofocus autocomplete="current-password" />
                @error('current_password')<div class="mt-2 text-sm font-medium text-red-600">{{ $message }}</div>@enderror
            </div>

            <div>
                <flux:input :label="__('password_change.new_password')" type="password" name="password" required autocomplete="new-password" />
                @error('password')<div class="mt-2 text-sm font-medium text-red-600">{{ $message }}</div>@enderror
            </div>

            <flux:input :label="__('password_change.confirm_password')" type="password" name="password_confirmation" required autocomplete="new-password" />

            <flux:button variant="primary" type="submit" class="w-full">{{ __('password_change.submit') }}</flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}">@csrf<flux:button variant="ghost" type="submit" class="w-full">{{ __('password_change.sign_out') }}</flux:button></form>
    </div>
</x-layouts.auth>
