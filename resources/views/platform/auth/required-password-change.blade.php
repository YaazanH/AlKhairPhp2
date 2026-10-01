<x-layouts.auth><div class="flex flex-col gap-6">
    <x-auth-header title="Change your temporary password" description="Set a password that only you know before continuing." />
    <form method="POST" action="{{ route('platform.password-change.update') }}" class="flex flex-col gap-6">
        @csrf @method('PUT')
        <flux:input label="Current temporary password" type="password" name="current_password" required autofocus autocomplete="current-password" />
        <flux:input label="New password" type="password" name="password" required autocomplete="new-password" />
        <flux:input label="Confirm new password" type="password" name="password_confirmation" required autocomplete="new-password" />
        <flux:button variant="primary" type="submit" class="w-full">Save new password</flux:button>
    </form>
    <form method="POST" action="{{ route('platform.logout') }}" class="mt-4">@csrf<flux:button variant="ghost" type="submit" class="w-full">Sign out</flux:button></form>
</div></x-layouts.auth>
