<x-layouts.auth><div class="flex flex-col gap-6">
    <x-auth-header :title="__('platform.ui.password_change.title')" :description="__('platform.ui.password_change.description')" />
    <form method="POST" action="{{ route('platform.password-change.update') }}" class="flex flex-col gap-6">
        @csrf @method('PUT')
        <flux:input :label="__('platform.ui.password_change.current')" type="password" name="current_password" required autofocus autocomplete="current-password" />
        <flux:input :label="__('platform.ui.password_change.new')" type="password" name="password" required autocomplete="new-password" />
        <flux:input :label="__('platform.ui.password_change.confirm')" type="password" name="password_confirmation" required autocomplete="new-password" />
        <flux:button variant="primary" type="submit" class="w-full">{{ __('platform.ui.password_change.save') }}</flux:button>
    </form>
    <form method="POST" action="{{ route('platform.logout') }}" class="mt-4">@csrf<flux:button variant="ghost" type="submit" class="w-full">{{ __('platform.dashboard.sign_out') }}</flux:button></form>
</div></x-layouts.auth>
